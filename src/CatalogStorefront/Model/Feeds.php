<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;

/**
 * The feeds that write the document store, by the entity they write and
 * their indexer id (di.xml `feeds`; a module registers the feeds it writes).
 * The rebuild and the status command read them.
 */
class Feeds
{
    /**
     * @param array<string, array<string, FeedIndexMetadata>> $feeds by entity name and indexer id
     */
    public function __construct(
        private readonly array $feeds = [],
    ) {
    }

    /**
     * @return array<string, array<string, FeedIndexMetadata>>
     */
    public function byEntity(): array
    {
        return $this->feeds;
    }
}
