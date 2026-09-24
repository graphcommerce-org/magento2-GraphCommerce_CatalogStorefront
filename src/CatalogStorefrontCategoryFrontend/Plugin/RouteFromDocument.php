<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\CompositeUrlFinder;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Service\V1\Data\UrlRewriteFactory;

/**
 * The route of a category URL from the category document with that URL path. The router
 * asks for a request path and a store; a path that names no category document, such as
 * a product, a CMS page or a redirect, takes core's rewrite table. After the router
 * forwards to the category view, it asks for that target path too, and the answer is that
 * no rewrite exists for it.
 */
class RouteFromDocument implements ResetAfterRequestInterface
{
    /** @var array<int, string> by store, the target path of the rewrite answered in this request */
    private array $targets = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly CategoryDocuments $documents,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlRewriteFactory $urlRewriteFactory,
    ) {
    }

    /**
     * @return UrlRewrite|null
     */
    public function aroundFindOneByData(CompositeUrlFinder $subject, \Closure $proceed, array $data)
    {
        $path = $data[UrlRewrite::REQUEST_PATH] ?? null;
        if (!is_string($path) || $path === '' || !isset($data[UrlRewrite::STORE_ID]) || count($data) !== 2) {
            return $proceed($data);
        }
        $storeId = (int)$data[UrlRewrite::STORE_ID];
        if (($this->targets[$storeId] ?? null) === $path) {
            return null;
        }
        if (!$this->mode->listing($storeId)) {
            return $proceed($data);
        }
        $suffix = (string)$this->scopeConfig->getValue(
            CategoryUrlPathGenerator::XML_PATH_CATEGORY_URL_SUFFIX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($suffix !== '' && !str_ends_with($path, $suffix)) {
            return $proceed($data);
        }
        $urlPath = $suffix === '' ? $path : substr($path, 0, -strlen($suffix));

        $storeViewCode = (string)$this->storeManager->getStore($storeId)->getCode();
        $found = $this->storage->find(CategoryDocuments::ENTITY, $storeViewCode, ['urlPath' => $urlPath], [], 0, 1)['documents'];
        $document = $found ? reset($found) : null;
        if (!is_array($document) || !isset($document['id'])) {
            return $proceed($data);
        }
        $this->documents->add($storeViewCode, $document);
        $this->targets[$storeId] = 'catalog/category/view/id/' . (int)$document['id'];

        return $this->urlRewriteFactory->create()
            ->setEntityType('category')
            ->setEntityId((int)$document['id'])
            ->setRequestPath($path)
            ->setTargetPath($this->targets[$storeId])
            ->setRedirectType(0)
            ->setStoreId($storeId);
    }

    public function _resetState(): void
    {
        $this->targets = [];
    }
}
