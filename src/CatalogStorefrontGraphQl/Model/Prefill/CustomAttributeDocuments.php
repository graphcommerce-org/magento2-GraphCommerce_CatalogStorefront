<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;

/**
 * Fetches the attribute documents of every attribute code the page's
 * documents carry in one multi-get when the query selects
 * custom_attributesV2, so the resolver plugin finds them in the request memo
 * instead of fetching them per product. Fills no field.
 */
class CustomAttributeDocuments implements PrefillerInterface
{
    public function __construct(
        private readonly AttributeDocuments $attributeDocuments,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        if (!$request->selects('custom_attributesV2')) {
            return [];
        }
        $codes = [];
        foreach ($documents as $document) {
            foreach ((array)($document['customAttributes'] ?? []) as $entry) {
                $codes[$entry['attributeCode']] = true;
            }
        }
        if ($codes) {
            $this->attributeDocuments->byCodes($request->store->getCode(), array_keys($codes));
        }

        return [];
    }
}
