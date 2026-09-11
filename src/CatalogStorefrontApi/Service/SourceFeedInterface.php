<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Imported generic catalog records. Trusted local adapter v1, not remote authentication. */
interface SourceFeedInterface
{
    /**
     * catalog-source-feed-v1: simple-product basic records, flat categories and
     * attribute metadata only; composite relationships and other feeds are unsupported.
     * JSON/scalars only. producer equals the configured generic Source code.
     * UPSERT replaces a complete owned record; DELETE removes it. One revision covers
     * all three record kinds. Replay the identical latest batch after uncertain delivery.
     * Whole-batch validation precedes writes; writes are not multi-record atomic.
     * An accepted receipt does not imply search visibility or generic Source publication.
     * @param array{contract:string,sourceId:int,producer:string,batchId:string,expectedRevision:int,records:list<array{entity:string,id:int|string,operation:string,data?:array}>} $batch
     * @return array{sourceId:int,batchId:string,revision:int,status:string,published:bool,records:array}
     */
    public function submit(array $batch): array;

    /** Latest durable receipt, including pending/failed state; null before first delivery. */
    public function receipt(int $sourceId): ?array;
}
