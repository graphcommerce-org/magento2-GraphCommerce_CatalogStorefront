<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontApi\Service;

/** Imported catalog records of one entity. Trusted local adapter, not remote authentication. */
interface SourceFeedInterface
{
    /**
     * Product, category and attribute records. Every item names its Catalog Source in
     * `source.locale`, a product is named by its SKU, a category by its slug path and an attribute
     * by its code. JSON and scalars only. CREATE replaces a whole record, UPDATE merges its scalar
     * and object fields and replaces its lists, DELETE removes it. An invalid item is reported with
     * its field and its message; the valid items of the same batch are accepted. Acceptance implies
     * no search visibility.
     *
     * @param string $entity product, productMetadata, category or categoryMetadata
     * @param string $operation CREATE, UPDATE or DELETE
     * @param list<array> $items the records
     * @return array{status:string,acceptedCount:int,errors?:list<array{itemIndex:int,code:string,message:string,value:string}>}
     * @throws \InvalidArgumentException where the whole batch is unusable.
     */
    public function submit(string $entity, string $operation, array $items): array;
}
