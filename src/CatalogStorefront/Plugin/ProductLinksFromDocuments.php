<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Model\ProductLink\Data\ListCriteria;
use Magento\Catalog\Model\ProductLink\Data\ListResult;
use Magento\Catalog\Model\ProductLink\ProductLinkQuery;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Answers the product links service contract, which the product_links field
 * resolves through, from the links slice of the documents: related, upsell
 * and crosssell links, and a grouped product's associated products from its
 * option slice, in the order of core's link type provider and then by linked
 * product id, as core's link map comes back. The linked products' types come
 * from their documents, fetched by sku in one request.
 */
class ProductLinksFromDocuments
{
    private const LINK_TYPES = ['related', 'crosssell', 'upsell', 'associated'];

    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly ProductLinkInterfaceFactory $productLinkFactory,
        private readonly Strict $strict,
    ) {
    }

    /**
     * @param ListCriteria[] $criteria
     * @return ListResult[]
     */
    public function aroundSearch(ProductLinkQuery $subject, \Closure $proceed, array $criteria): array
    {
        $documents = [];
        foreach ($criteria as $index => $listCriteria) {
            $document = $listCriteria instanceof ListCriteria
                ? $listCriteria->getBelongsToProduct()?->getData(ProductDocumentsInterface::DOCUMENT_KEY)
                : null;
            if (!is_array($document)) {
                return $proceed($criteria);
            }
            $documents[$index] = $document;
        }

        try {
            $links = [];
            $skus = [];
            foreach ($documents as $index => $document) {
                $rows = [];
                foreach ((array)($document['links'] ?? []) as $link) {
                    if (isset($link['sku'], $link['type'])) {
                        $rows[] = ['type' => $link['type'], 'sku' => $link['sku'], 'position' => (int)($link['position'] ?? 0)];
                    }
                }
                foreach ((array)($document['optionsV2'] ?? []) as $option) {
                    if (($option['type'] ?? null) !== 'grouped') {
                        continue;
                    }
                    foreach ((array)($option['values'] ?? []) as $value) {
                        if (isset($value['sku'])) {
                            $rows[] = ['type' => 'associated', 'sku' => $value['sku'], 'position' => (int)($value['sortOrder'] ?? 0)];
                        }
                    }
                }
                $links[$index] = $rows;
                $skus = array_merge($skus, array_column($rows, 'sku'));
            }

            $types = [];
            $ids = [];
            $skus = array_values(array_unique($skus));
            if ($skus) {
                $store = $criteria[array_key_first($criteria)]->getBelongsToProduct()->getStore()->getCode();
                foreach ($this->storage->findBySku($store, $skus) as $id => $data) {
                    $types[$data['sku']] = ($data['type'] ?? 'simple') === 'bundle_fixed' ? 'bundle' : ($data['type'] ?? 'simple');
                    $ids[$data['sku']] = $id;
                }
            }

            $results = [];
            foreach ($criteria as $index => $listCriteria) {
                $accepted = $listCriteria->getLinkTypes();
                $list = [];
                usort($links[$index], static fn(array $a, array $b) =>
                    [array_search($a['type'], self::LINK_TYPES, true), $ids[$a['sku']] ?? 0]
                    <=> [array_search($b['type'], self::LINK_TYPES, true), $ids[$b['sku']] ?? 0]);
                foreach ($links[$index] as $row) {
                    if (!isset($types[$row['sku']]) || ($accepted !== null && !in_array($row['type'], $accepted, true))) {
                        continue;
                    }
                    $list[] = $this->productLinkFactory->create()
                        ->setSku($listCriteria->getBelongsToProductSku())
                        ->setLinkType($row['type'])
                        ->setLinkedProductSku($row['sku'])
                        ->setLinkedProductType($types[$row['sku']])
                        ->setPosition($row['position']);
                }
                $results[] = new ListResult($list, null);
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($criteria);
        }

        return $results;
    }
}
