<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\AttributeOptions;
use GraphCommerce\CatalogStorefront\Model\Read\ProductAttributeOptions;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\App\State as AppState;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class AttributeOptionsTest extends TestCase
{
    public function testTableOptionsUseTheProductScopedCanonicalRowsAndCustomSourcesKeepTheirOrder(): void
    {
        $table = $this->createMock(Attribute::class);
        $table->method('getId')->willReturn(147);
        $table->method('usesSource')->willReturn(true);
        $tableSource = $this->createMock(Table::class);
        $tableSource->expects(self::once())->method('getAllOptions')->willReturn([
            ['value' => '146', 'label' => 'Jersey'],
            ['value' => '35', 'label' => 'Leather'],
            ['value' => '144', 'label' => 'Fleece'],
            ['value' => '33', 'label' => 'Cotton'],
        ]);
        $table->method('getSource')->willReturn($tableSource);

        $source = $this->createMock(Table::class);
        $source->expects(self::once())->method('getAllOptions')->willReturn([
            ['value' => '', 'label' => ''],
            ['value' => 'yes', 'label' => 'Yes'],
            ['value' => 'no', 'label' => 'No'],
        ]);
        $custom = $this->createMock(Attribute::class);
        $custom->method('getId')->willReturn(200);
        $custom->method('usesSource')->willReturn(true);
        $custom->method('getSource')->willReturn($source);
        $custom->method('getSourceModel')->willReturn('Vendor\Catalog\Model\CustomTableSource');

        $eav = $this->createMock(EavConfig::class);
        $eav->method('getAttribute')->willReturnMap([
            ['catalog_product', 147, $table],
            ['catalog_product', 200, $custom],
        ]);
        $state = $this->createMock(AppState::class);
        $state->method('emulateAreaCode')->willReturnCallback(static fn(string $area, callable $callback) => $callback());
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::once())->method('startEnvironmentEmulation')->with(1, 'frontend', true);
        $emulation->expects(self::once())->method('stopEnvironmentEmulation');
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->with('default')->willReturn($store);
        $productOptions = $this->createMock(ProductAttributeOptions::class);
        $productOptions->expects(self::once())->method('all')->with([147, 200], 1)->willReturn([
            147 => [
                ['id' => '33', 'label' => 'Cotton', 'sortOrder' => 2],
                ['id' => '144', 'label' => 'Fleece', 'sortOrder' => 2],
                ['id' => '35', 'label' => 'Leather', 'sortOrder' => 4],
                ['id' => '146', 'label' => 'Jersey', 'sortOrder' => 4],
            ],
            // A custom source remains authoritative even if auxiliary table rows exist.
            200 => [['id' => 'stale', 'label' => 'Stale', 'sortOrder' => 0]],
        ]);

        $result = (new AttributeOptions($eav, $state, $emulation, $stores, $productOptions))->get([
            ['id' => 147, 'storeViewCode' => 'default'],
            ['id' => 200, 'storeViewCode' => 'default'],
        ]);

        $options = array_map(static fn(array $row): array => $row['options'], array_values($result));
        self::assertSame(
            ['146', '35', '144', '33'],
            array_column(array_slice($options, 0, 4), 'id')
        );
        self::assertSame([4, 4, 2, 2], array_column(array_slice($options, 0, 4), 'facetSortOrder'));
        self::assertSame(['yes', 'no'], array_column(array_slice($options, 4), 'id'));
        self::assertSame([null, null], array_column(array_slice($options, 4), 'facetSortOrder'));
    }
}
