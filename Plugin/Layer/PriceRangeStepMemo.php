<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\Registry;

/**
 * Remembers the price step of the current category per process. Core loads
 * the current category, the store root on a listing, for its filter price
 * range on every request that builds the price facet. The step is category
 * configuration; a change reaches a worker at its next restart.
 */
class PriceRangeStepMemo
{
    private const LIMIT = 500;

    /** @var array<string, mixed> */
    private array $steps = [];

    public function __construct(
        private readonly LayerResolver $layerResolver,
        private readonly Registry $registry,
    ) {
    }

    public function aroundGetPriceRange(Range $subject, \Closure $proceed)
    {
        $store = $this->layerResolver->get()->getCurrentStore();
        $category = $this->registry->registry('current_category_filter');
        $key = $store->getId() . ':' . ($category ? $category->getId() : $store->getRootCategoryId());
        if (!array_key_exists($key, $this->steps)) {
            if (count($this->steps) >= self::LIMIT) {
                $this->steps = [];
            }
            $this->steps[$key] = $proceed();
        }

        return $this->steps[$key];
    }
}
