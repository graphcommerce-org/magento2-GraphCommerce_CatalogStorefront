<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Customer;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;

/**
 * A new customer group needs an entry in every product's price index, and
 * the price feed re-exports only rows whose hash changed. So the feed table
 * is truncated and its indexer invalidated: the next cron run re-exports
 * every row, with the group's entry.
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
            $this->resourceConnection->getConnection()->truncateTable(
                $this->resourceConnection->getTableName($this->feed->getFeedTableName())
            );
            $this->indexerRegistry->get($this->indexerId)->invalidate();
        }

        return $result;
    }
}
