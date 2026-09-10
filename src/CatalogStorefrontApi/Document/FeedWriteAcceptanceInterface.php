<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Document;

use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;

/**
 * Persists acceptance of a feed batch after its registered document writer succeeds.
 *
 * An implementation MUST throw when acceptance is not durable. The delivery lets
 * that exception escape so the exporter can retain or requeue the source work.
 */
interface FeedWriteAcceptanceInterface
{
    /**
     * @param array[] $rows the same feed batch handed to the document writer
     */
    public function accept(array $rows, FeedIndexMetadata $metadata): void;
}
