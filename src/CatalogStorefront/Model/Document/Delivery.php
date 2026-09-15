<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriteAcceptanceInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\DataExporter\Model\ExportFeedInterface;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;
use Psr\Log\LoggerInterface;

/**
 * Local delivery for commerce-data-export feeds: each feed batch goes to every
 * writer registered for its feed name (di.xml `writers`, feed name to writer
 * name), in registration order. Each writer writes its own slice of the
 * documents. A feed without a writer is accepted and only
 * persisted in its feed table, as is every feed while storefront indexing is
 * off. A storage failure reports status 500 for the exporter's retry bookkeeping.
 * After a registered writer succeeds, an optional feed acceptor can durably record
 * the batch. Acceptor failures escape so the calling indexer can retain or requeue
 * its source work; they are not reduced to a feed-table status.
 *
 * Every write is logged at debug level with its row count and duration, so
 * an export's time splits between the exporter and the store. After a write, the cache tags of the entities the batch touched (di.xml
 * `identities`: feed name to cache tag to row keys) are purged the way an
 * indexer purges them: the response and resolver caches and the page cache
 * hold nothing built from the documents the batch replaced. The product
 * save purges the same tags at once, when the document is still the old one.
 */
class Delivery implements ExportFeedInterface
{
    private const STATUS_ACCEPTED = 200;
    private const STATUS_RETRY = 500;

    /**
     * @param array<string, FeedWriterInterface[]> $writers by feed name, then by writer name
     * @param array<string, array<string, string[]>> $identities feed name to cache tag to row keys
     * @param FeedWriteAcceptanceInterface[] $acceptors by feed name
     */
    public function __construct(
        private readonly FeedExportStatusBuilder $feedExportStatusBuilder,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly CacheContextFactory $cacheContextFactory,
        private readonly ManagerInterface $eventManager,
        private readonly CacheInterface $cache,
        private readonly array $writers = [],
        private readonly array $identities = [],
        private readonly array $acceptors = [],
    ) {
    }

    public function export(array $data, FeedIndexMetadata $metadata): FeedExportStatus
    {
        if (!$this->config->indexing()) {
            return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Storefront indexing is off');
        }
        $feed = $metadata->getFeedName();
        $written = $this->writers[$feed] ?? [];
        $started = microtime(true);
        try {
            foreach ($written as $writer) {
                $writer->write($data);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('catalog-storefront: storing feed "%s" failed: %s', $feed, $e->getMessage()));

            return $this->feedExportStatusBuilder->build(self::STATUS_RETRY, $e->getMessage());
        }
        if ($written && isset($this->acceptors[$feed])) {
            $this->acceptors[$feed]->accept($data, $metadata);
        }
        if ($written) {
            $this->logger->debug(sprintf(
                'catalog-storefront: feed "%s": %d rows written in %d ms',
                $feed,
                count($data),
                (int)round((microtime(true) - $started) * 1000)
            ));
        }
        if ($written && isset($this->identities[$feed])) {
            $context = $this->cacheContextFactory->create();
            foreach ($this->identities[$feed] as $tag => $keys) {
                $ids = [];
                foreach ($data as $row) {
                    foreach ($keys as $key) {
                        if (!empty($row[$key])) {
                            $ids[] = (int)$row[$key];
                        }
                    }
                }
                if ($ids) {
                    $context->registerEntities($tag, array_values(array_unique($ids)));
                }
            }
            if ($context->getIdentities()) {
                $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $context]);
                $this->cache->clean($context->getIdentities());
            }
        }

        return $this->feedExportStatusBuilder->build(self::STATUS_ACCEPTED, 'Stored in document store');
    }
}
