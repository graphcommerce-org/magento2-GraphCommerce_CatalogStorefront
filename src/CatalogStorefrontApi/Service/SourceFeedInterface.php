<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Imported catalog records of one Catalog Source. Trusted local adapter, not remote authentication. */
interface SourceFeedInterface
{
    /**
     * catalog-source-feed-v8: product, category and attribute records of one Source. A product
     * record is named by its SKU, a category by its ID and an attribute by its code. JSON and
     * scalars only. UPSERT replaces a complete owned record; DELETE removes it. The whole batch
     * is validated before any write, and the writes are not atomic over the records.
     * Acceptance implies neither search visibility nor publication.
     *
     * @param array{contract:string,sourceId:int,records:list<array{entity:string,id:int|string,operation:string,data?:array}>} $batch
     * @return array{accepted:int}
     * @throws \InvalidArgumentException where a record is invalid or was rejected by storage.
     */
    public function submit(array $batch): array;
}
