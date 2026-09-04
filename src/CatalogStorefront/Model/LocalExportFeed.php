<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model;

use GraphCommerce\CatalogStorefrontApi\Feed\FeedApplierInterface;
use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Psr\Log\LoggerInterface;

/**
 * Local delivery for commerce-data-export feeds: each feed batch goes to the
 * applier registered for its feed name (di.xml `appliers`), which writes its
 * slice of the documents. A feed without an applier is accepted and only
 * persisted in its feed table. A storage failure reports status 500, so the
 * feed machinery retries the batch by cron.
 */
class LocalExportFeed implements ExportFeedInterface
{
    private const STATUS_ACCEPTED = 200;
    private const STATUS_RETRY = 500;

    /**
     * @param FeedApplierInterface[] $appliers by feed name
     */
    public function __construct(
        private readonly FeedExportStatusBuilder $feedExportStatusBuilder,
        private readonly LoggerInterface $logger,
        private readonly array $appliers = [],
    ) {
    }

    public function export(array $data, FeedIndexMetadata $metadata): FeedExportStatus
    {
        try {
            ($this->appliers[$metadata->getFeedName()] ?? null)?->apply($data);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('catalog-storefront: storing feed "%s" failed: %s', $metadata->getFeedName(), $e->getMessage())
            );

            return $this->feedExportStatusBuilder->build(self::STATUS_RETRY, $e->getMessage());
        }

        return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Stored in document store');
    }
}
