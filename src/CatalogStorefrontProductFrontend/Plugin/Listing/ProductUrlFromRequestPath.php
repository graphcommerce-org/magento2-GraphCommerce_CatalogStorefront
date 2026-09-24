<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Url as ProductUrl;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The URL of a document product with a request path: the store's link base URL and the
 * path, as core's URL builder concatenates them, without a builder instance per call. A
 * product without a request path, a product of another store, and a call with route
 * parameters of its own take core's builder.
 */
class ProductUrlFromRequestPath implements ResetAfterRequestInterface
{
    private const OWN_PARAMETERS = ['_ignore_category' => true, '_nosid' => true];

    /** @var array<int, string> the link base URL per store, for the request's scheme */
    private array $baseUrls = [];

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
    ) {
    }

    /**
     * @param mixed $params
     * @return mixed
     */
    public function aroundGetUrl(ProductUrl $subject, \Closure $proceed, Product $product, $params = [])
    {
        $requestPath = $product->getData('request_path');
        if (!is_string($requestPath)
            || $requestPath === ''
            || $product->hasData('url_data_object')
            || array_diff_key((array)$params, self::OWN_PARAMETERS) !== []
            || !is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))
        ) {
            return $proceed($product, $params);
        }
        $storeId = (int)$product->getStoreId();
        if ($storeId !== (int)$this->storeManager->getStore()->getId()) {
            return $proceed($product, $params);
        }
        $this->baseUrls[$storeId] ??= (string)$this->storeManager->getStore($storeId)
            ->getBaseUrl(UrlInterface::URL_TYPE_LINK, $this->request->isSecure());

        return $this->baseUrls[$storeId] . ltrim($requestPath, '/');
    }

    public function _resetState(): void
    {
        $this->baseUrls = [];
    }
}
