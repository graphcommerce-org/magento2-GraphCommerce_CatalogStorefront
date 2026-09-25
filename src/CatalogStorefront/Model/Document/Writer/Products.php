<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\CompositeLinks;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;

/**
 * The products feed is the base document per store view: the feed row with the
 * composite links kept by id, and every slice a reader takes, once and in the
 * shape the reader takes it.
 */
class Products implements FeedWriterInterface
{
    /** The media URL of a gallery entry and of an image type holds the file after this path. */
    private const MEDIA_PATH = '/catalog/product';

    /** The image types the model builder puts on the product model. */
    private const IMAGE_TYPES = ['image', 'smallImage', 'thumbnail'];

    /**
     * The feed slices the document leaves out. The prices and stock feeds write
     * their own slice. `attributes` and the `meta` and `samples` title fields
     * repeat values of `customAttributes`, and `images` and `videos` repeat
     * entries of `media_gallery`. `parents` becomes the parent id lists and
     * `categoryData` the category ids. `deleted` routes the row to a delete.
     * The rest has no reader.
     */
    private const NOT_STORED = [
        'deleted' => true,
        'prices' => true,
        'stock' => true,
        'inventory' => true,
        'attributes' => true,
        'metaTitle' => true,
        'metaDescription' => true,
        'metaKeyword' => true,
        'samplesTitle' => true,
        'swatchImage' => true,
        'images' => true,
        'videos' => true,
        'parents' => true,
        'categories' => true,
        'categoryIds' => true,
        'categoryData' => true,
        'storeCode' => true,
        'websiteCode' => true,
        'productType' => true,
        'currency' => true,
        'weightUnit' => true,
        'linksExist' => true,
        'lowStock' => true,
        'displayable' => true,
        'buyable' => true,
        'deletedAt' => true,
        'modifiedAt' => true,
    ];

    public function __construct(
        private readonly ProductDocumentStorageInterface $storage,
        private readonly CompositeLinks $compositeLinks,
    ) {
    }

    public function write(array $rows): void
    {
        $feedRows = [];
        $deletes = [];
        foreach ($rows as $row) {
            $store = $row['storeViewCode'];
            if (!empty($row['deleted'])) {
                $deletes[$store][] = (int)$row['productId'];
            } else {
                $feedRows[$store][(int)$row['productId']] = $row;
            }
        }
        foreach (array_unique(array_merge(array_keys($feedRows), array_keys($deletes))) as $store) {
            // The links come from the feed row: a child names its parents and a parent its
            // children by sku, which the document keeps by id.
            [$links, $listChanges] = $this->compositeLinks->upserts($store, $feedRows[$store] ?? [], $deletes[$store] ?? []);
            $documents = [];
            foreach ($feedRows[$store] ?? [] as $id => $row) {
                $document = array_diff_key($row, self::NOT_STORED);
                if (isset($row['customAttributes'])) {
                    // The raw value per attribute code, which is how every reader takes it.
                    $attributes = [];
                    foreach ((array)$row['customAttributes'] as $attribute) {
                        $attributes[$attribute['attributeCode']] = $attribute['value'] ?? null;
                    }
                    $document['customAttributes'] = $attributes;
                }
                foreach (self::IMAGE_TYPES as $type) {
                    if (isset($row[$type])) {
                        $document[$type] = $this->mediaFile($row[$type]['url'] ?? null);
                    }
                }
                if (isset($row['media_gallery'])) {
                    $document['media_gallery'] = array_map(
                        fn(array $entry): array => array_filter([
                            'file' => $this->mediaFile($entry['url'] ?? null),
                            'label' => $entry['label'] ?? null,
                            'types' => (array)($entry['types'] ?? []),
                            'sort_order' => $entry['sort_order'] ?? null,
                            ...array_intersect_key($entry['imageUrl'] ?? [], ['mediaPath' => true, 'placeholder' => true]),
                        ], static fn($value): bool => $value !== null && $value !== []),
                        (array)$row['media_gallery']
                    );
                }
                if (isset($row['urlRewrites'])) {
                    // The request path is what the read side answers; the host is the store's.
                    $document['urlRewrites'] = array_map(
                        static function (array $rewrite): array {
                            $url = (string)($rewrite['url'] ?? '');

                            return array_filter([
                                'url' => ltrim((string)(parse_url($url, PHP_URL_PATH) ?? $url), '/'),
                                'parameters' => (array)($rewrite['parameters'] ?? []),
                            ], static fn($value): bool => $value !== []);
                        },
                        (array)$row['urlRewrites']
                    );
                }
                if (isset($row['categoryData'])) {
                    // The category product index holds the assignments and the anchor ancestors;
                    // the read side takes the ids of both.
                    $document['categoryIds'] = array_values(array_filter(array_map(
                        static fn(array $category): int => (int)($category['categoryId'] ?? 0),
                        (array)$row['categoryData']
                    )));
                }
                $documents[$id] = $document;
            }
            foreach ($links as $id => $keys) {
                $documents[$id] = array_replace($documents[$id] ?? [], $keys);
            }
            $this->storage->upsert($store, $documents);
            $this->storage->updateLists($store, $listChanges);
            $this->storage->delete($store, $deletes[$store] ?? []);
        }
    }

    private function mediaFile(?string $url): string
    {
        if ($url === null) {
            return 'no_selection';
        }
        $position = strpos($url, self::MEDIA_PATH);

        return $position === false ? $url : substr($url, $position + strlen(self::MEDIA_PATH));
    }
}
