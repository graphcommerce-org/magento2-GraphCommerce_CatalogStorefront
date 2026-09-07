<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Field;

use GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Every website the product is assigned to, as `websiteIds`: the feed row
 * names only the website of its own store view, and the `websites` field
 * lists them all.
 */
class WebsiteIds implements ProductDocumentFieldInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    public function add(string $storeViewCode, array $documents): array
    {
        if (!$documents) {
            return $documents;
        }
        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll($connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_website'), ['product_id', 'website_id'])
            ->where('product_id IN (?)', array_keys($documents))
            ->order('website_id'));
        foreach ($documents as $id => $document) {
            $documents[$id]['websiteIds'] = [];
        }
        foreach ($rows as $row) {
            $documents[(int)$row['product_id']]['websiteIds'][] = (int)$row['website_id'];
        }

        return $documents;
    }
}
