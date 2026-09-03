<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;

/**
 * Builds a catalog product model from a feed document, so the stock GraphQL
 * resolvers can run on it unchanged.
 *
 * The feeds export labels where the model holds ids (status, visibility, tax
 * class, select attribute options) and absolute media URLs where the model
 * holds files, so this builder translates back. Label-to-id maps are cached
 * per process.
 */
class ProductModelBuilder
{
    public const DOCUMENT_KEY = '_gc_document';

    private const GUEST_CUSTOMER_GROUP = '0';

    /** @var array<string, string|null> */
    private array $optionIdCache = [];

    /** @var array<string, int>|null */
    private ?array $taxClassMap = null;

    /** @var array<string, int>|null */
    private ?array $visibilityMap = null;

    public function __construct(
        private readonly ProductFactory $productFactory,
        private readonly EavConfig $eavConfig,
        private readonly TaxClassCollectionFactory $taxClassCollectionFactory,
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
            'type_id' => $document['type'] ?? 'simple',
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

        if (isset($document['taxClassId'])) {
            $data['tax_class_id'] = $this->taxClassId((string)$document['taxClassId']);
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
                $attribute['attributeCode'],
                (array)($attribute['value'] ?? [])
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

    private function taxClassId(string $label): ?int
    {
        if ($this->taxClassMap === null) {
            $this->taxClassMap = [];
            foreach ($this->taxClassCollectionFactory->create() as $taxClass) {
                $this->taxClassMap[$taxClass->getClassName()] = (int)$taxClass->getId();
            }
        }

        return $this->taxClassMap[$label] ?? null;
    }

    /**
     * Select and multiselect attributes come as option labels; the model holds
     * option ids. Attributes without a source keep the raw value.
     */
    private function attributeValue(string $code, array $labels): ?string
    {
        $ids = [];
        foreach ($labels as $label) {
            $key = $code . ':' . $label;
            if (!array_key_exists($key, $this->optionIdCache)) {
                $this->optionIdCache[$key] = $this->lookupOptionId($code, (string)$label);
            }
            $ids[] = $this->optionIdCache[$key] ?? $label;
        }

        return $ids ? implode(',', $ids) : null;
    }

    private function lookupOptionId(string $code, string $label): ?string
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if (!$attribute->getId() || !$attribute->usesSource()) {
                return null;
            }
            $optionId = $attribute->getSource()->getOptionId($label);

            return $optionId !== null ? (string)$optionId : null;
        } catch (\Throwable) {
            return null;
        }
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
