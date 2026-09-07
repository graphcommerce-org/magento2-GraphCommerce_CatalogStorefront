<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Layer;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Read\FacetDocuments;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Layer\PrimeFacetDocuments;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilder;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Framework\Search\Response\Aggregation\Value;
use Magento\Framework\Search\Response\Bucket;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PrimeFacetDocumentsTest extends TestCase
{
    public function testEveryBucketPrimesTheFacetReadOnTheDocumentPathOnly(): void
    {
        $aggregation = new Aggregation([
            'price_bucket' => new Bucket('price_bucket', [new Value('0.1_999.7', ['count' => 5])]),
            'color_bucket' => new Bucket('color_bucket', [new Value('5', ['count' => 2]), new Value('6', ['count' => 1])]),
            'category_bucket' => new Bucket('category_bucket', [new Value('9', ['count' => 3]), new Value('10', ['count' => 1])]),
        ]);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);
        $facets = $this->createMock(FacetDocuments::class);
        $facets->expects(self::once())->method('prime')->with('default', ['0.1_999.7', '5', '6'], ['price', 'color'], [9, 10]);
        $mode = $this->createMock(Mode::class);
        $mode->method('documents')->willReturnOnConsecutiveCalls(true, false);
        $plugin = new PrimeFacetDocuments($facets, $storeManager, $mode, $this->createMock(Strict::class));
        $builder = $this->createMock(LayerBuilder::class);

        $plugin->beforeBuild($builder, $aggregation, 1);
        $plugin->beforeBuild($builder, $aggregation, 1);
    }
}
