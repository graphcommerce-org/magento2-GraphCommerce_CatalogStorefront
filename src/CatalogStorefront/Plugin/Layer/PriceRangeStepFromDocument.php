<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Registry;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * The price facet's step comes from the current category's document (the
 * store root on a listing) instead of a category load. A missing document
 * falls back to core.
 */
class PriceRangeStepFromDocument
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly LayerResolver $layerResolver,
        private readonly Registry $registry,
        private readonly Strict $strict,
    ) {
    }

    public function aroundGetPriceRange(Range $subject, \Closure $proceed)
    {
        $store = $this->layerResolver->get()->getCurrentStore();
        $category = $this->registry->registry('current_category_filter');
        $categoryId = (int)($category ? $category->getId() : $store->getRootCategoryId());
        try {
            $document = $this->storage->get('category', $store->getCode(), [$categoryId], ['filterPriceRange'])[$categoryId] ?? null;
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
            $document = null;
        }
        if ($document === null || !array_key_exists('filterPriceRange', $document)) {
            $this->strict->fallback(self::class, 'category document without filterPriceRange');
            return $proceed();
        }

        return $document['filterPriceRange'];
    }
}
