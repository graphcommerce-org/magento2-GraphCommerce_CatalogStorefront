<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Owned generic content rows; trusted local adapter v1, not a remote authentication API. */
interface LayerFeedInterface
{
    /**
     * JSON/scalars only. producer must equal the configured generic Layer code.
     * UPSERT replaces this producer's entire per-product values; DELETE removes it.
     * expectedRevision starts at 0. Replay the identical batchId/payload after failure.
     * Whole-batch validation precedes writes; transport writes are not multi-record atomic.
     * @param array{sourceId:int,layerId:int,producer:string,batchId:string,expectedRevision:int,records:list<array{productId:int,operation:string,values?:array}>} $batch
     * @return array{batchId:string,revision:int,status:string,published:bool,records:array}
     */
    public function submit(array $batch): array;
}
