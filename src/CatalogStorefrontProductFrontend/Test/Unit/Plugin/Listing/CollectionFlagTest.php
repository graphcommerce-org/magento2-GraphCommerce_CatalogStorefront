<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\CollectionFlag;
use Magento\Catalog\Model\Layer\ItemCollectionProviderInterface;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Elasticsearch\Model\Layer\Category\ItemCollectionProvider as ElasticsearchCategoryProvider;
use Magento\Elasticsearch\Model\Layer\Search\ItemCollectionProvider as ElasticsearchSearchProvider;
use Magento\Framework\Data\Collection as DataCollection;
use PHPUnit\Framework\TestCase;

class CollectionFlagTest extends TestCase
{
    public function testTheProviderOfEveryLayerIsMarked(): void
    {
        foreach ([ElasticsearchCategoryProvider::class, ElasticsearchSearchProvider::class] as $provider) {
            $this->assertTrue(is_subclass_of($provider, ItemCollectionProviderInterface::class), $provider);
        }
    }

    public function testTheListingCollectionIsMarked(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->once())->method('setFlag')->with(CollectionFlag::FLAG, true);

        $result = (new CollectionFlag())->afterGetCollection(
            $this->createMock(ItemCollectionProviderInterface::class),
            $collection
        );

        $this->assertSame($collection, $result);
    }

    public function testAnotherCollectionIsPassedThrough(): void
    {
        $collection = $this->createMock(DataCollection::class);
        $collection->expects($this->never())->method('setFlag');

        (new CollectionFlag())->afterGetCollection($this->createMock(ItemCollectionProviderInterface::class), $collection);
    }
}
