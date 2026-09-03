<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;

/**
 * Local delivery for commerce-data-export feeds.
 *
 * Accepts every batch, so the feed machinery persists the rows and their status
 * in the local feed tables (enable PERSIST_EXPORTED_FEED in env.php to keep the
 * payloads). The document store consumes the feed tables.
 */
class LocalExportFeed implements ExportFeedInterface
{
    private const STATUS_ACCEPTED = 200;

    public function __construct(
        private readonly FeedExportStatusBuilder $feedExportStatusBuilder,
    ) {
    }

    public function export(array $data, FeedIndexMetadata $metadata): FeedExportStatus
    {
        return $this->feedExportStatusBuilder->build(
            self::STATUS_ACCEPTED,
            'Stored in local feed table'
        );
    }
}
