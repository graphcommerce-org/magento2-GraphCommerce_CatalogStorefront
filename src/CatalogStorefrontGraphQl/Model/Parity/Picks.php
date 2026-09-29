<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Parity;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The products and categories a parity query names, picked from the catalog
 * of the installation. A kind is a product type (`simple`, `configurable`,
 * `bundle`, `grouped`, `downloadable`, `virtual` or `any`), followed by a
 * trait: `special-price`, `tier-price`, `reviewed`, `links`, `fixed` (a
 * bundle with a fixed price), `fpt` (a fixed product tax) or `child` (a
 * simple product under a configurable one). Only enabled products on the
 * default website count, with the core stock visibility filter. The trait
 * `child` includes hidden products. Products use ascending entity id.
 * A category kind names the products it holds: the active
 * category of the default store view with the most of them wins, and the
 * trait `children` limits it to categories with child categories.
 */
class Picks
{
    private const TYPES = ['simple', 'configurable', 'bundle', 'grouped', 'downloadable', 'virtual', 'any'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly Uid $uidEncoder,
        private readonly StoreManagerInterface $storeManager,
        private readonly CollectionFactory $collectionFactory,
        private readonly Stock $stock,
    ) {
    }

    /**
     * @return string[] the skus, fewer than `$count` where the catalog holds fewer, empty where it holds none
     */
    public function skus(string $kind, int $count): array
    {
        return array_map('strval', $this->connection()->fetchCol($this->products($kind)->columns('e.sku')->limit($count)));
    }

    public function urlKey(string $kind): ?string
    {
        $select = $this->products($kind)->limit(1);
        $this->joinAttribute($select, 'e', Product::ENTITY, 'url_key', 'url_key', 'url_key.value IS NOT NULL', 'catalog_product_entity_varchar');

        return $this->one($select->columns(['url_key' => 'url_key.value']));
    }

    public function categoryId(string $kind): ?int
    {
        $id = $this->one($this->categories($kind)->columns('c.entity_id'));

        return $id === null ? null : (int)$id;
    }

    public function categoryUrlPath(string $kind): ?string
    {
        $select = $this->categories($kind);
        $this->joinAttribute($select, 'c', Category::ENTITY, 'url_path', 'url_path', 'url_path.value IS NOT NULL', 'catalog_category_entity_varchar');

        return $this->one($select->columns(['url_path' => 'url_path.value']));
    }

    /**
     * The uid of the lowest option of the first super attribute of the picked
     * configurable product, as `configurable_product_options_selection` takes it.
     */
    public function optionUid(string $kind): ?string
    {
        $connection = $this->connection();
        $productId = $this->one($this->products($kind === 'any' ? 'configurable' : $kind)->columns('e.entity_id')->limit(1));
        if ($productId === null) {
            return null;
        }
        $attributeId = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_attribute'), 'attribute_id')
                ->where('product_id = ?', (int)$productId)
                ->order(['position ASC', 'product_super_attribute_id ASC'])
                ->limit(1)
        );
        if ($attributeId === false) {
            return null;
        }
        $option = $connection->fetchOne(
            $connection->select()
                ->from(['l' => $this->resource->getTableName('catalog_product_super_link')], [])
                ->join(['v' => $this->resource->getTableName('catalog_product_entity_int')], 'v.entity_id = l.product_id AND v.store_id = 0', ['value' => 'MIN(v.value)'])
                ->where('l.parent_id = ?', (int)$productId)
                ->where('v.attribute_id = ?', (int)$attributeId)
        );

        return $option ? $this->uidEncoder->encode('configurable/' . (int)$attributeId . '/' . (int)$option) : null;
    }

    /**
     * The longest word of the name of the first visible product, for a search query.
     */
    public function searchTerm(): ?string
    {
        $select = $this->products('any')->limit(1);
        $this->joinAttribute($select, 'e', Product::ENTITY, 'name', 'name', 'name.value IS NOT NULL', 'catalog_product_entity_varchar');
        $name = (string)$this->one($select->columns(['name' => 'name.value']));
        preg_match_all('/\p{L}{4,}/u', $name, $words);
        usort($words[0], static fn(string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return isset($words[0][0]) ? mb_strtolower($words[0][0]) : null;
    }

    private function products(string $kind): Select
    {
        [$type, $trait] = explode(':', $kind, 2) + [1 => ''];
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown product kind "%s"', $kind));
        }
        $store = $this->storeManager->getDefaultStoreView();
        $collection = $this->collectionFactory->create()
            ->setStoreId($store->getId())
            ->addStoreFilter($store)
            ->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);
        if ($trait !== 'child') {
            $collection->addAttributeToFilter('visibility', ['in' => [2, 3, 4]]);
        }
        $this->stock->addIsInStockFilterToCollection($collection);
        $select = $collection->getSelect()->reset(Select::COLUMNS)->reset(Select::ORDER)->order('e.entity_id ASC');
        if ($type !== 'any') {
            $select->where('e.type_id = ?', $type);
        }
        $this->trait($select, $trait);

        return $select;
    }

    public function currency(): ?string
    {
        $store = $this->storeManager->getDefaultStoreView();
        foreach ($store->getAvailableCurrencyCodes(true) as $currency) {
            if ($currency !== $store->getBaseCurrencyCode() && (float)$store->getBaseCurrency()->getRate($currency) > 0) {
                return $currency;
            }
        }
        return null;
    }

    private function trait(Select $select, string $trait): void
    {
        $exists = fn(string $table, string $column) => sprintf(
            'EXISTS (SELECT 1 FROM %s t WHERE t.%s = e.entity_id%s)',
            $this->resource->getTableName($table),
            $column,
            $table === 'review' ? ' AND t.status_id = 1 AND t.entity_id = 1' : ''
        );
        match ($trait) {
            '' => null,
            'special-price' => $this->joinAttribute($select, 'e', Product::ENTITY, 'special_price', 'special_price', 'special_price.value IS NOT NULL', 'catalog_product_entity_decimal'),
            'tier-price' => $select->where($exists('catalog_product_entity_tier_price', 'entity_id')),
            'reviewed' => $select->where($exists('review', 'entity_pk_value')),
            'links' => $select->where($exists('catalog_product_link', 'product_id')),
            'fixed' => $this->joinAttribute($select, 'e', Product::ENTITY, 'price_type', 'price_type', 'price_type.value = 1', 'catalog_product_entity_int'),
            'fpt' => $select->where($exists('weee_tax', 'entity_id')),
            'child' => $select->where($exists('catalog_product_super_link', 'product_id')),
            default => throw new \InvalidArgumentException(sprintf('Unknown product trait "%s"', $trait)),
        };
    }

    private function categories(string $kind): Select
    {
        $trait = '';
        if (str_ends_with($kind, ':children')) {
            $trait = 'children';
            $kind = substr($kind, 0, -9);
        }
        $connection = $this->connection();
        $products = $this->products($kind)->reset(Select::ORDER)->columns('e.entity_id');
        $select = $connection->select()
            ->from(['c' => $this->resource->getTableName('catalog_category_entity')], [])
            ->join(
                ['cp' => $this->resource->getTableName('catalog_category_product')],
                'cp.category_id = c.entity_id AND cp.product_id IN (' . $products . ')',
                []
            )
            ->where('c.level >= 2')
            ->group('c.entity_id')
            ->order(['COUNT(cp.product_id) DESC', 'c.entity_id ASC'])
            ->limit(1);
        $this->joinAttribute($select, 'c', Category::ENTITY, 'is_active', 'is_active', 'is_active.value = 1', 'catalog_category_entity_int');
        if ($trait === 'children') {
            $select->where('c.children_count > 0');
        }

        return $select;
    }

    /**
     * Joins the default store view value of an attribute, under `$alias`, with `$condition` on it.
     */
    private function joinAttribute(Select $select, string $entity, string $entityType, string $code, string $alias, string $condition, string $table): void
    {
        $attributeId = (int)$this->eavConfig->getAttribute($entityType, $code)->getId();
        $select->join(
            [$alias => $this->resource->getTableName($table)],
            sprintf('%s.entity_id = %s.entity_id AND %s.store_id = 0 AND %s.attribute_id = %d AND %s', $alias, $entity, $alias, $alias, $attributeId, $condition),
            []
        );
    }

    private function one(Select $select): ?string
    {
        $value = $this->connection()->fetchOne($select);

        return $value === false || $value === null ? null : (string)$value;
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
