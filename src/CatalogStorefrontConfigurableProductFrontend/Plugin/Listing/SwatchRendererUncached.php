<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Swatches\Block\Product\Renderer\Configurable;

/**
 * The swatch renderer of a product built from a document renders without the block cache.
 * One renderer block serves every card of a listing, so the lifetime follows the product
 * it is set to, and a product without a document gets the block's own lifetime back.
 */
class SwatchRendererUncached
{
    private \SplObjectStorage $silenced;

    public function __construct()
    {
        $this->silenced = new \SplObjectStorage();
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function afterSetProduct(Configurable $subject, $result, Product $product)
    {
        if (is_array($product->getData(ProductDocumentsInterface::DOCUMENT_KEY))) {
            if (!$this->silenced->contains($subject)) {
                $this->silenced[$subject] = $subject->hasData('cache_lifetime')
                    ? [$subject->getData('cache_lifetime')]
                    : [];
                $subject->setData('cache_lifetime', false);
            }
        } elseif ($this->silenced->contains($subject)) {
            $own = $this->silenced[$subject];
            $this->silenced->detach($subject);
            $own === [] ? $subject->unsetData('cache_lifetime') : $subject->setData('cache_lifetime', $own[0]);
        }

        return $result;
    }
}
