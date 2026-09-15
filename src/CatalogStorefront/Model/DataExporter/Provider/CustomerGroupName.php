<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Framework\App\ResourceConnection;

/**
 * Adds the group name to a scopes customer group row; the exporter carries
 * the group by id and by the hash the price rows name it with. The price
 * index lists the groups by name, as core lists them.
 */
class CustomerGroupName
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function get(array $values): array
    {
        $ids = array_unique(array_map(static fn(array $value) => (int)$value['customerGroupId'], $values));
        if (!$ids) {
            return [];
        }
        $connection = $this->resourceConnection->getConnection();
        $names = $connection->fetchPairs(
            $connection->select()
                ->from($this->resourceConnection->getTableName('customer_group'), ['customer_group_id', 'customer_group_code'])
                ->where('customer_group_id IN (?)', $ids)
        );
        $output = [];
        foreach ($values as $value) {
            $output[$value['customerGroupId']] = [
                'customerGroupId' => $value['customerGroupId'],
                'name' => (string)($names[(int)$value['customerGroupId']] ?? ''),
            ];
        }

        return $output;
    }
}
