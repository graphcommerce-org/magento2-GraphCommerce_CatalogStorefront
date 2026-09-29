<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Detail;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the product selected by catalog_product_view from its document.
 * The model carries its status, visibility, categories, type and store.
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
        private readonly array $supportedOptionTypes = [],
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

        $store = $storeId === null
            ? $this->storeManager->getStore()
            : $this->storeManager->getStore($storeId);

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

            $type = $documents[$id]['type'] ?? '';
            $optionType = $type === 'bundle_fixed' ? 'bundle' : $type;
            if (in_array($optionType, ['bundle', 'grouped'], true)
                && (empty($this->supportedOptionTypes[$optionType])
                    || !isset($documents[$id]['hasOptions'], $documents[$id]['requiredOptions']))) {
                throw new DocumentReadException('Catalog product detail requires its type module and option flags: ' . $type);
            }

            foreach ((array)($documents[$id]['optionsV2'] ?? []) as $option) {
                if (empty($this->supportedOptionTypes[$option['type'] ?? ''])) {
                    throw new DocumentReadException('Catalog product detail requires document support for option type: ' . ($option['type'] ?? 'unknown'));
                }
            }
            if (!empty($documents[$id]['shopperInputOptions'])) {
                throw new DocumentReadException('Catalog product detail requires document support for shopper input options.');
            }

            $models = $this->products->build($store, $documents);
        } catch (\Throwable $e) {
            $this->logger->error(
                'catalog-storefront detail document read: ' . $e->getMessage(),
                ['exception' => $e]
            );

            throw new DocumentReadException('Catalog product detail document could not be read.', 0, $e);
        }

        $model = $models[$id] ?? null;
        if ($model === null) {
            throw new DocumentReadException(sprintf(
                'Catalog product %d requires a usable document.',
                $id
            ));
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
