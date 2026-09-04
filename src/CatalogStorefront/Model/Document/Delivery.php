<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Psr\Log\LoggerInterface;

/**
 * Local delivery for commerce-data-export feeds: each feed batch goes to the
 * writer registered for its feed name (di.xml `writers`), which writes its
 * slice of the documents. A feed without a writer is accepted and only
 * persisted in its feed table, as is every feed while storefront indexing is
 * off. A storage failure reports status 500, so the feed machinery retries the
 * batch by cron.
 */
class Delivery implements ExportFeedInterface
{
    private const STATUS_ACCEPTED = 200;
    private const STATUS_RETRY = 500;

    /**
     * @param FeedWriterInterface[] $writers by feed name
     */
    public function __construct(
        private readonly FeedExportStatusBuilder $feedExportStatusBuilder,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $writers = [],
    ) {
    }

    public function export(array $data, FeedIndexMetadata $metadata): FeedExportStatus
    {
        if (!$this->config->indexing()) {
            return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Storefront indexing is off');
        }
        try {
            ($this->writers[$metadata->getFeedName()] ?? null)?->write($data);
        } catch (\Throwable $e) {
            $this->logger->error(
                sprintf('catalog-storefront: storing feed "%s" failed: %s', $metadata->getFeedName(), $e->getMessage())
            );

            return $this->feedExportStatusBuilder->build(self::STATUS_RETRY, $e->getMessage());
        }

        return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Stored in document store');
    }
}
