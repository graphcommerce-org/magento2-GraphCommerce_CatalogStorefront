<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontSearch\Setup\Patch\Data;

use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * The fulltext documents need the `entity_id` field the sort tie-break reads.
 */
class ReindexFulltextForEntityId implements DataPatchInterface
{
    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
    ) {
    }

    public function apply(): self
    {
        $this->indexerRegistry->get(Fulltext::INDEXER_ID)->invalidate();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
