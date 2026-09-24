<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use GraphCommerce\CatalogStorefront\Model\ProductPrice;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;

/**
 * Builds a catalog product model from a feed document, so the stock GraphQL
 * resolvers can run on it unchanged.
 *
 * The model holds what a product load holds: the entity columns from the
 * document's base fields and every attribute's raw store view value from the
 * document's custom attributes, ids where the feed carries labels. The feed
 * folds a fixed bundle's price type into the product type; the model carries
 * the bundle type attributes as strings, as core loads them.
 */
class ProductModelBuilder
{
    /** Core holds the loaded tier price rows under this attribute; the feed's string form is not a model value. */
    private const NOT_MODEL_VALUES = ['tier_price'];

    /** @var array<string, int>|null */
    private ?array $visibilityMap = null;

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly ProductPrice $productPrice,
    ) {
    }

    /**
     * A document without the products-feed slice (only prices or stock arrived
     * yet) does not carry enough data and yields null.
     */
    public function build(array $document, int $storeId): ?Product
    {
        if (!isset($document['sku'], $document['productId'])) {
            return null;
        }

        $data = [
            // The string the entity table gives. getJsonConfig() puts an id into the rendered JSON
            // as it is, so an int renders as 1812 where a loaded product renders "1812".
            'entity_id' => (string)$document['productId'],
            'sku' => $document['sku'],
            'name' => $document['name'] ?? null,
            'type_id' => ($document['type'] ?? 'simple') === 'bundle_fixed' ? 'bundle' : ($document['type'] ?? 'simple'),
            'attribute_set_id' => (int)($document['attributeSetId'] ?? 0),
            'status' => ($document['status'] ?? '') === 'Enabled'
                ? Status::STATUS_ENABLED
                : Status::STATUS_DISABLED,
            'visibility' => $this->visibilityId($document['visibility'] ?? ''),
            'description' => $document['description'] ?? null,
            'short_description' => $document['shortDescription'] ?? null,
            'url_key' => $document['urlKey'] ?? null,
            'weight' => $document['weight'] ?? null,
            'created_at' => $document['createdAt'] ?? null,
            'updated_at' => $document['updatedAt'] ?? null,
            'image' => $this->mediaFile($document['image']['url'] ?? null),
            'small_image' => $this->mediaFile($document['smallImage']['url'] ?? null),
            'thumbnail' => $this->mediaFile($document['thumbnail']['url'] ?? null),
            'media_gallery' => $this->mediaGallery($document['media_gallery'] ?? []),
            'quantity_and_stock_status' => [
                'is_in_stock' => (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false),
                'qty' => $document['stock']['qty'] ?? 0,
            ],
            'is_salable' => (int)($document['stock']['isSalable'] ?? $document['inStock'] ?? false),
            'tax_class_id' => $document['taxClassId'] ?? null,
            // A loaded product carries its custom options as a list, and a detail page counts them.
            // A document that holds any is not built at all, so the list is empty here.
            'options' => [],
        ];

        if (in_array($document['type'] ?? '', ['bundle', 'bundle_fixed'], true)) {
            $data['price_type'] = ($document['type'] ?? '') === 'bundle_fixed' ? '1' : '0';
            foreach (['skuType' => 'sku_type', 'weightType' => 'weight_type', 'shipmentType' => 'shipment_type'] as $key => $attribute) {
                if (isset($document[$key])) {
                    $data[$attribute] = (string)(int)$document[$key];
                }
            }
            if (isset($document['priceView'])) {
                $data['price_view'] = $document['priceView'] === 'as_low_as' ? '1' : '0';
            }
        }
        if (isset($document['linksPurchasedSeparately'])) {
            $data['links_purchased_separately'] = (int)$document['linksPurchasedSeparately'];
        }

        $fallbackRow = $this->productPrice->row((array)($document['prices'] ?? []), ProductPrice::FALLBACK_GROUP);
        if ($fallbackRow !== null) {
            $data['price'] = $fallbackRow['regular'];
        }

        if (!empty($document['categoryData'])) {
            $data['category_ids'] = array_values(array_filter(array_map(
                static fn(array $category) => isset($category['categoryId']) ? (string)$category['categoryId'] : null,
                $document['categoryData']
            )));
        }

        foreach ((array)($document['customAttributes'] ?? []) as $attribute) {
            if (isset($attribute['attributeCode']) && !in_array($attribute['attributeCode'], self::NOT_MODEL_VALUES, true)) {
                $data[$attribute['attributeCode']] = $attribute['value'] ?? null;
            }
        }

        $product = $this->productFactory->create();
        $product->setData($data);

        // The document is what was stored, so the model holds it as its original values too.
        // Without them every comparison against them reads as a change: getIdentities() takes a
        // changed status to mean the product moved category and adds a cache tag per category, so
        // a page built here is purged by more than the same page loaded from the database.
        $product->setOrigData();

        $product->setData(ProductDocumentsInterface::DOCUMENT_KEY, $document);
        $product->setStoreId($storeId);
        $product->setHasDataChanges(false);

        return $product;
    }

    private function visibilityId(string $label): int
    {
        if ($this->visibilityMap === null) {
            $this->visibilityMap = [];
            foreach (Visibility::getOptionArray() as $id => $optionLabel) {
                $this->visibilityMap[(string)$optionLabel] = (int)$id;
            }
        }

        return $this->visibilityMap[$label] ?? Visibility::VISIBILITY_NOT_VISIBLE;
    }

    private function mediaFile(?string $url): string
    {
        if ($url === null) {
            return 'no_selection';
        }
        $position = strpos($url, '/catalog/product');

        return $position === false ? $url : substr($url, $position + strlen('/catalog/product'));
    }

    /**
     * The entries in position order, as the gallery read handler lists them.
     */
    private function mediaGallery(array $entries): array
    {
        usort($entries, static fn(array $a, array $b) => (int)($a['sort_order'] ?? 0) <=> (int)($b['sort_order'] ?? 0));
        $images = [];
        foreach ($entries as $index => $entry) {
            $images[] = [
                'value_id' => $index + 1,
                'file' => $this->mediaFile($entry['url'] ?? null),
                'label' => $entry['label'] ?? '',
                'position' => (string)($entry['sort_order'] ?? $index + 1),
                'types' => (array)($entry['types'] ?? []),
                'media_type' => 'image',
                'disabled' => 0,
            ];
        }

        return ['images' => $images];
    }
}
