<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Registry;

use Magento\Framework\Exception\InputException;

/** Resource fields and adapter type IDs are independent of the installed platform's display name. */
class Definition
{
    public const KINDS = ['views', 'sources', 'books', 'stocks', 'layers', 'policies'];
    public const LABELS = ['views' => 'Catalog View', 'sources' => 'Catalog Source', 'books' => 'Price Book',
        'stocks' => 'Stock', 'layers' => 'Catalog Layer', 'policies' => 'Catalog Policy'];
    public function __construct(private readonly Platform $platform)
    {
    }
    public function table(string $kind): string
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new InputException(__('Unknown catalog resource.'));
        }
        return 'catalog_storefront_registry_' . $kind;
    }
    public function types(string $kind): array
    {
        $this->table($kind);
        $p = $this->platform->name();
        return match ($kind) {
            'views'=>['generic' => 'Generic Catalog View', 'platform_store_view' => "$p Store View Binding"],
            'sources'=>['generic' => 'Generic Catalog Source', 'platform_store_view' => "$p Store View Catalog"],
            'books'=>['generic' => 'Generic Price Book', 'platform_root' => "$p Root", 'platform_website' => "$p Website", 'platform_customer_group' => "$p Customer Group"],
            'stocks'=>['generic' => 'Generic Stock', 'platform_msi' => "$p MSI Stock"],
            'layers'=>['generic' => 'Generic Catalog Layer', 'platform_reviews' => "$p Rating Summary"],
            'policies'=>['generic' => 'Generic Catalog Policy', 'platform_stock_visibility' => "$p Stock Visibility"],
        };
    }
    public function fields(string $kind): array
    {
        $this->table($kind);
        $nativeStore = ['label' => $this->platform->name() . ' Store View', 'element' => 'select', 'options' => 'native_stores'];
        return match ($kind) {
            'views'=>[
                'source_id' => ['label' => 'Catalog Source','element' => 'select','options' => 'sources','required' => true],
                'stock_id' => ['label' => 'Stock','element' => 'select','options' => 'stocks'],
                'protection' => ['label' => 'Protection','element' => 'select','options' => ['public' => 'Public','private' => 'Private'],'default' => 'public'],
                'book_mode' => ['label' => 'Price Book Selection','element' => 'select','options' => ['all' => 'All available books','selected' => 'Selected books','single' => 'Single book'],'default' => 'all'],
                'book_ids' => ['label' => 'Price Books','element' => 'multiselect','options' => 'books'],
                'layer_ids' => ['label' => 'Layers in Resolution Order','element' => 'ordered','options' => 'layers'],
                'policy_ids' => ['label' => 'Catalog Policies','element' => 'multiselect','options' => 'policies'],
                'native_store_id' => $nativeStore + ['types' => ['platform_store_view'],'required' => true],
            ],
            'sources'=>[
                'document_scope' => ['element' => 'input','visible' => false],
                'locale' => ['label' => 'Locale','element' => 'input'],
                'identity_namespace' => ['label' => 'Product Identity Namespace','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'A stable identifier shared by this Source and its price, stock and layer feeds. Set it before identity ingestion; it cannot change afterward.'],
                'native_store_id' => $nativeStore + ['types' => ['platform_store_view'],'required' => true],
            ],
            'books'=>[
                'parent_id' => ['label' => 'Parent Price Book','element' => 'select','options' => 'books'],
                'currency' => ['label' => 'Currency','element' => 'select','options' => 'currencies','notice' => 'Required for a root book. Leave empty to inherit the parent currency.'],
                'price_producer' => ['label' => 'Price Producer','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'The producer identifier supplied by this Book’s price feed. Leave both binding fields empty for a Book that only inherits prices.'],
                'identity_namespace' => ['label' => 'Product Identity Namespace','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'Must match the Source product identities. Producer and namespace cannot change after ingestion begins.'],
                'native_website_id' => ['label' => $this->platform->name() . ' Website','element' => 'select','options' => 'native_websites','types' => ['platform_website','platform_customer_group'],'required' => true],
                'native_group_id' => ['label' => $this->platform->name() . ' Customer Group','element' => 'select','options' => 'native_groups','types' => ['platform_customer_group'],'required' => true],
            ],
            'stocks'=>[
                'stock_producer' => ['label' => 'Stock Producer','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'The producer identifier supplied by this Stock’s availability feed.'],
                'identity_namespace' => ['label' => 'Product Identity Namespace','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'Must match the Source product identities. Set producer and namespace together; they cannot change after ingestion begins.'],
                'locations' => ['label' => 'Inventory Sources','element' => 'locations','types' => ['generic']],
                'native_stock_id' => ['label' => $this->platform->name() . ' MSI Stock','element' => 'select','options' => 'native_stocks','types' => ['platform_msi'],'required' => true],
            ],
            'layers'=>[
                'scope' => ['label' => 'Availability','element' => 'select','options' => ['view' => 'Selected Views','global' => 'All Views'],'default' => 'view'],
                'priority' => ['label' => 'Default Priority','element' => 'input','default' => '10'],
                'locale' => ['label' => 'Locale','element' => 'input','notice' => 'Leave empty for all locales.'],
                'source_id' => ['label' => 'Catalog Source','element' => 'select','options' => 'sources','types' => ['generic'],'binding' => true,'notice' => 'For a canonical feed, select its generic Source and set producer and namespace together. Only Sources with a matching product namespace are offered.'],
                'layer_producer' => ['label' => 'Layer Producer','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'The producer identifier supplied by this Layer’s content feed. Owned fields use imported Source attribute codes and scalar Override values; ratings and array merges are not supported by this feed.'],
                'identity_namespace' => ['label' => 'Product Identity Namespace','element' => 'input','types' => ['generic'],'binding' => true,'notice' => 'Must match the selected Source. Source, producer, namespace and owned fields cannot change after ingestion begins.'],
                'fields' => ['label' => 'Owned Fields','element' => 'fields','types' => ['generic'],'notice' => 'Enter attribute codes from the Source’s imported metadata. Canonical feeds support scalar Override fields only; ratings and array merges require separate contracts.'],
                'native_store_id' => $nativeStore + ['types' => ['platform_reviews'],'required' => true],
            ],
            'policies'=>[
                'source_id' => ['label' => 'Catalog Source','element' => 'select','options' => 'sources','types' => ['generic'],'required' => true],
                'attribute' => ['label' => 'Attribute Code','element' => 'input','types' => ['generic'],'required' => true],
                'operator' => ['label' => 'Operator','element' => 'select','options' => ['eq' => 'Equals','in' => 'In','not_in' => 'Not in','gte' => 'Greater than or equal','lte' => 'Less than or equal'],'default' => 'eq','types' => ['generic']],
                'value_source' => ['label' => 'Value Source','element' => 'select','options' => ['static' => 'Static','trigger' => 'Trigger'],'default' => 'static','types' => ['generic']],
                'values' => ['label' => 'Static Values','element' => 'textarea','notice' => 'One value per line. Used when Value Source is Static.','types' => ['generic']],
                'trigger' => ['label' => 'Trigger Name','element' => 'input','notice' => 'Required when Value Source is Trigger.','types' => ['generic']],
                'native_store_id' => $nativeStore + ['types' => ['platform_stock_visibility'],'required' => true],
            ],
        };
    }
}
