<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the product a detail page renders from its document instead of loading it.
 *
 * The load is what costs, not what follows it: a repository load reads the entity row, every
 * attribute value, the media gallery, the options and, for a configurable, its links and its super
 * attributes, all before it returns. A document put on the model afterwards is never read, because
 * the work is already done and a type instance memoises what it built.
 *
 * So the load is replaced, as the listing replaces its own: serve from the document, or hand over
 * to the database and change nothing. The model carries its status, visibility, categories, type
 * and store, which is what the detail page's init asks before it renders. It carries the document
 * too, so every plugin that reads one acts on it.
 *
 * Only the product the request renders is served. The cart, the wishlist, an order, an indexer and
 * the admin share this repository; none of them is a product view, so none of them reaches this.
 */
class ProductDocument implements ResetAfterRequestInterface
{
    private const ACTION = 'catalog_product_view';

    /** @var array<string, ProductInterface> the products built this request, by store view and id */
    private array $built = [];

    public function __construct(
        private readonly ProductDocumentsInterface $products,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param ProductRepositoryInterface $subject
     * @param \Closure $proceed
     * @param int|string $productId
     * @param bool $editMode
     * @param int|null $storeId
     * @param bool $forceReload
     * @return ProductInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetById(
        ProductRepositoryInterface $subject,
        \Closure $proceed,
        $productId,
        $editMode = false,
        $storeId = null,
        $forceReload = false
    ) {
        $id = (int)$productId;

        // Edit mode is the admin's own load, and a forced reload asks for the database by name.
        if ($editMode || $forceReload || !$this->viewed($id)) {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        try {
            $store = $storeId === null
                ? $this->storeManager->getStore()
                : $this->storeManager->getStore($storeId);
        } catch (\Throwable $e) {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        if (!$this->mode->detail((int)$store->getId())) {
            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        // The repository keeps its own instance per id, and this answers before it, so the page
        // gets one model however many times it asks.
        $key = $store->getCode() . ':' . $id;
        if (isset($this->built[$key])) {
            return $this->built[$key];
        }

        try {
            $documents = $this->products->documents((string)$store->getCode(), [$id]);

            // Custom, bundle, downloadable and grouped options are stored under optionsV2 and are
            // not built into a model yet, and the detail page renders them. A product that has any
            // comes from the database whole.
            if (!empty($documents[$id]['optionsV2'])) {
                return $proceed($productId, $editMode, $storeId, $forceReload);
            }

            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront detail fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        $model = $models[$id] ?? null;
        if ($model === null) {
            // The page loads from the database, as it does with the setting off. Say so: one
            // product points at the feeds, every product at the store code or the cluster.
            $this->logger->info(sprintf(
                'catalog-storefront: product %d loaded from the database, it has no document',
                $id
            ));

            return $proceed($productId, $editMode, $storeId, $forceReload);
        }

        $this->built[$key] = $model;

        return $model;
    }

    /**
     * Whether this product is the one the current request renders a detail page for.
     */
    private function viewed(int $productId): bool
    {
        // An indexer, a console command and a queue consumer load products through the same
        // repository, and their request carries no action at all.
        if ($productId === 0 || !$this->request instanceof HttpRequest) {
            return false;
        }

        return $this->request->getFullActionName() === self::ACTION
            && (int)$this->request->getParam('id') === $productId;
    }

    public function _resetState(): void
    {
        $this->built = [];
    }
}
