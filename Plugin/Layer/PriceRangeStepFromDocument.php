<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Storage\MetadataDocumentStorage;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

/**
 * The price facet's step comes from the current category's document (the
 * store root on a listing) instead of a category load. A missing document
 * falls back to core.
 */
class PriceRangeStepFromDocument
{
    public function __construct(
        private readonly MetadataDocumentStorage $categoryStorage,
        private readonly LayerResolver $layerResolver,
        private readonly Registry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundGetPriceRange(Range $subject, \Closure $proceed)
    {
        $store = $this->layerResolver->get()->getCurrentStore();
        $category = $this->registry->registry('current_category_filter');
        $categoryId = (int)($category ? $category->getId() : $store->getRootCategoryId());
        try {
            $document = $this->categoryStorage->get($store->getCode(), [$categoryId], ['filterPriceRange'])[$categoryId] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront price step fallback: ' . $e->getMessage());
            $document = null;
        }
        if ($document === null || !array_key_exists('filterPriceRange', $document)) {
            return $proceed();
        }

        return $document['filterPriceRange'];
    }
}
