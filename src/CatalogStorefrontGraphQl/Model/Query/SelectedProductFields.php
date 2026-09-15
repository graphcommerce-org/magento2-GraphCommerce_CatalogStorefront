<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Query;

use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * The product fields a list of items selects: the selection under every
 * `product` and `configured_variant` node below the resolved field. A
 * prefiller fills what the list asks for and nothing else.
 */
class SelectedProductFields
{
    private const PRODUCT_NODES = ['product', 'configured_variant'];

    private const DEPTH = 3;

    /**
     * @return string[] empty where the query selects no product node
     */
    public function of(ResolveInfo $info): array
    {
        return array_values(array_unique($this->walk($info->getFieldSelection(self::DEPTH))));
    }

    /**
     * @return string[]
     */
    private function walk(array $selection): array
    {
        $fields = [];
        foreach ($selection as $name => $children) {
            if (!is_array($children)) {
                continue;
            }
            $fields = array_merge(
                $fields,
                in_array($name, self::PRODUCT_NODES, true) ? array_keys($children) : $this->walk($children)
            );
        }

        return $fields;
    }
}
