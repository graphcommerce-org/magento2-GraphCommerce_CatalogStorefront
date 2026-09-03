<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;

/**
 * Builds a catalog product model from a feed document, so the stock GraphQL
 * resolvers can run on it unchanged.
 *
 * The feeds export labels where the model holds ids (status, visibility) and
 * absolute media URLs where the model holds files, so this builder translates
 * back; the option and tax class ids were resolved by the writer.
 */
class ProductModelBuilder
{
    public const DOCUMENT_KEY = '_gc_document';

    private const GUEST_CUSTOMER_GROUP = '0';

    /** @var array<string, int>|null */
    private ?array $visibilityMap = null;

    public function __construct(
        private readonly ProductFactory $productFactory,
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
            'entity_id' => (int)$document['productId'],
            'sku' => $document['sku'],
            'name' => $document['name'] ?? null,
            'type_id' => ($document['type'] ?? 'simple') === 'bundle_fixed' ? 'bundle' : ($document['type'] ?? 'simple'),
            'attribute_set_id' => 4,
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
        ];

        // The feed folds a fixed bundle price type into the product type. The
        // bundle attributes are strings, as core loads them: its enum lookups
        // accept nothing else.
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
        if (array_key_exists('taxClassNumericId', $document)) {
            $data['tax_class_id'] = $document['taxClassNumericId'];
        }

        foreach ((array)($document['prices'] ?? []) as $priceRow) {
            if (($priceRow['customerGroupCode'] ?? null) === self::GUEST_CUSTOMER_GROUP
                && isset($priceRow['regular'])
            ) {
                $data['price'] = $priceRow['regular'];
                break;
            }
        }

        if (!empty($document['categoryData'])) {
            $data['category_ids'] = array_values(array_filter(array_map(
                static fn(array $category) => isset($category['categoryId']) ? (string)$category['categoryId'] : null,
                $document['categoryData']
            )));
        }

        foreach ($document['attributes'] ?? [] as $attribute) {
            if (!isset($attribute['attributeCode'])) {
                continue;
            }
            $data[$attribute['attributeCode']] = $this->attributeValue(
                (array)($attribute['value'] ?? []),
                (array)($attribute['valueId'] ?? [])
            );
        }

        $product = $this->productFactory->create();
        $product->setData($data);
        $product->setData(self::DOCUMENT_KEY, $document);
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

    /**
     * The model holds option ids where the feed carries labels; the writer
     * resolved them next to the labels. An attribute without a source keeps
     * the label.
     */
    private function attributeValue(array $labels, array $ids): ?string
    {
        $values = [];
        foreach ($labels as $index => $label) {
            $values[] = $ids[$index] ?? $label;
        }

        return $values ? implode(',', $values) : null;
    }

    private function mediaFile(?string $url): string
    {
        if ($url === null) {
            return 'no_selection';
        }
        $position = strpos($url, '/catalog/product');

        return $position === false ? $url : substr($url, $position + strlen('/catalog/product'));
    }

    private function mediaGallery(array $entries): array
    {
        $images = [];
        foreach ($entries as $index => $entry) {
            $images[] = [
                'value_id' => $index + 1,
                'file' => $this->mediaFile($entry['url'] ?? null),
                'label' => $entry['label'] ?? '',
                'position' => $entry['sort_order'] ?? $index + 1,
                'media_type' => 'image',
                'disabled' => 0,
            ];
        }

        return ['images' => $images];
    }
}
