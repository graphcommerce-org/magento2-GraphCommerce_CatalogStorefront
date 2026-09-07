<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Plugin;

use Magento\Elasticsearch\Model\Adapter\BatchDataMapper\ProductDataMapper;

/**
 * The fulltext document carries its product id as an integer field, so the
 * entity id tie-break of every listing sorts on doc values (`EntityIdSort`)
 * instead of running a script over every matching document.
 */
class EntityIdField
{
    public function afterMap(ProductDataMapper $subject, array $documents): array
    {
        foreach ($documents as $productId => &$document) {
            $document['entity_id'] = (int)$productId;
        }

        return $documents;
    }
}
