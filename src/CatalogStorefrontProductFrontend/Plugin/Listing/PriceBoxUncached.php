<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Render\RendererPool;
use Magento\Framework\Pricing\SaleableInterface;
use Magento\Framework\View\Element\AbstractBlock;

/**
 * The price box of a product built from a document renders without the block cache. Its
 * render costs less than a cache hit, and a cold page pays no cache write per card.
 */
class PriceBoxUncached
{
    /**
     * @param mixed $result
     * @param mixed $priceCode
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterCreatePriceRender(RendererPool $subject, $result, $priceCode, SaleableInterface $saleableItem)
    {
        if ($result instanceof AbstractBlock
            && $saleableItem instanceof Product
            && is_array($saleableItem->getData(ProductDocumentsInterface::DOCUMENT_KEY))
        ) {
            $result->setData('cache_lifetime', false);
        }

        return $result;
    }
}
