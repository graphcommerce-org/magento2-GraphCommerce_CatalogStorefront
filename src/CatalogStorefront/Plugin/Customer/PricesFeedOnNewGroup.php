<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Customer;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * A new customer group needs an entry in every product's price index, a
 * deleted one must leave it, and the price feed re-exports only rows whose
 * hash changed. So the feed table is emptied and its indexer invalidated:
 * the next cron run re-exports every row, with the groups as they are.
 */
class PricesFeedOnNewGroup
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly FeedIndexMetadata $feed,
        private readonly string $indexerId,
    ) {
    }

    public function aroundSave(GroupRepositoryInterface $subject, \Closure $proceed, GroupInterface $group): GroupInterface
    {
        $new = !$group->getId();
        $result = $proceed($group);
        if ($new) {
            $this->reexport();
        }

        return $result;
    }

    public function afterDeleteById(GroupRepositoryInterface $subject, bool $result): bool
    {
        $this->reexport();

        return $result;
    }

    public function afterDelete(GroupRepositoryInterface $subject, bool $result): bool
    {
        $this->reexport();

        return $result;
    }

    private function reexport(): void
    {
        // A data patch saves a group inside a transaction, where MySQL refuses TRUNCATE.
        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName($this->feed->getFeedTableName())
        );
        $this->indexerRegistry->get($this->indexerId)->invalidate();
    }
}
