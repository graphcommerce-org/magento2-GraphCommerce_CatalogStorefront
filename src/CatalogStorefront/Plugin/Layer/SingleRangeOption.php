<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Layer\SingleRange;
use Magento\Catalog\Model\Config\Source\Price\Step;

class SingleRangeOption
{
    public function afterToOptionArray(Step $subject, array $options): array
    {
        $options[] = ['value' => SingleRange::METHOD, 'label' => __('Single range (lowest to highest price)')];

        return $options;
    }
}
