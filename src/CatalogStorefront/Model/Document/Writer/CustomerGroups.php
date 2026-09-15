<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;

/**
 * The scopes customer group feed lands as one group document in the global
 * scope, with the code a price row names the group with and the name the
 * price index orders the groups by.
 */
class CustomerGroups implements FeedWriterInterface
{
    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
    ) {
    }

    public function write(array $rows): void
    {
        $upserts = [];
        $deletes = [];
        foreach ($rows as $row) {
            if (!isset($row['customerGroupId'])) {
                continue;
            }
            if (!empty($row['deleted'])) {
                $deletes[] = (int)$row['customerGroupId'];
                continue;
            }
            $upserts[(int)$row['customerGroupId']] = [
                'code' => (string)$row['customerGroupCode'],
                'name' => (string)($row['name'] ?? ''),
            ];
        }
        $this->storage->upsert(Scopes::CUSTOMER_GROUP, Scopes::SCOPE, $upserts);
        $this->storage->delete(Scopes::CUSTOMER_GROUP, Scopes::SCOPE, $deletes);
    }
}
