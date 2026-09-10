<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Ui\DataProvider;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\DerivedViews;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\OverviewData;
use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Read-only array provider for the five Catalog Storefront Admin listings.
 */
class OverviewDataProvider extends AbstractDataProvider
{
    private const DATASETS = [
        'catalogViews',
        'sources',
        'books',
        'layers',
        'policies',
    ];

    private string $dataset;

    /**
     * @param string $name
     * @param string $primaryFieldName
     * @param string $requestFieldName
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        $name,
        $primaryFieldName,
        $requestFieldName,
        private readonly DerivedViews $derivedViews,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        // Magento's UI definition converter supplies provider data from the
        // dataSource settings; custom dataProvider child arguments are omitted.
        $dataset = $data['config']['dataset'] ?? match ($name) {
            'catalog_storefront_views_listing_data_source' => 'catalogViews',
            'catalog_storefront_sources_listing_data_source' => 'sources',
            'catalog_storefront_books_listing_data_source' => 'books',
            'catalog_storefront_layers_listing_data_source' => 'layers',
            'catalog_storefront_policies_listing_data_source' => 'policies',
            default => null,
        };
        if (!is_string($dataset) || !in_array($dataset, self::DATASETS, true)) {
            throw new \InvalidArgumentException('A supported Catalog Storefront overview dataset is required.');
        }
        $this->dataset = $dataset;
    }

    /** @return array{items: array<int, array<string, mixed>>, totalRecords: int} */
    public function getData(): array
    {
        $overview = OverviewData::build(
            $this->derivedViews->views(),
            $this->derivedViews->groups(),
            $this->derivedViews->contributions(),
        );
        /** @var array<int, array<string, mixed>> $items */
        $items = $overview[$this->dataset];
        return ['items' => $items, 'totalRecords' => count($items)];
    }

    #[\ReturnTypeWillChange]
    public function count(): int
    {
        return $this->getData()['totalRecords'];
    }

    /**
     * The overview listings intentionally expose no filtering controls.
     */
    public function addFilter(Filter $filter): void
    {
    }

    /**
     * The factual provider order is part of the overview presentation.
     * Columns are configured as non-sortable in the UI component definitions.
     */
    public function addOrder($field, $direction): void
    {
    }

    /**
     * The bounded overview datasets are rendered without paging controls.
     */
    public function setLimit($offset, $size): void
    {
    }

    /**
     * No collection field selection is required for array-backed rows.
     */
    public function addField($field, $alias = null): void
    {
    }
}
