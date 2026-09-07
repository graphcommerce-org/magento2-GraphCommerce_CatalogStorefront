<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use GraphCommerce\CatalogStorefrontSearch\Model\Config;
use Magento\Elasticsearch\Model\Adapter\FieldMapper\Product\AttributeAdapter;
use Magento\Elasticsearch\SearchAdapter\Query\Builder\Sort\EntityId;

/**
 * The entity id tie-break sorts on the `entity_id` field `EntityIdField`
 * writes. Core sorts on a painless script that parses the document id, which
 * runs for every matching document: 40 ms of a listing over 300 000 visible
 * products against 6 ms on the field. A document indexed before the field
 * existed sorts last among its ties until the fulltext index is rebuilt. Off
 * in the configuration, core's script sort stands.
 */
class EntityIdSort
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function afterBuild(EntityId $subject, array $result, AttributeAdapter $attribute, string $direction): array
    {
        return $this->config->entityIdSort() ? ['entity_id' => ['order' => $direction, 'unmapped_type' => 'integer']] : $result;
    }
}
