<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\CollectionFlag;
use GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing\LinkCollectionFlag;
use Magento\Catalog\Model\Product\Link as LinkType;
use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection;
use Magento\Framework\DataObject;
use Magento\GroupedProduct\Model\ResourceModel\Product\Link as GroupedLink;
use PHPUnit\Framework\TestCase;

class LinkCollectionFlagTest extends TestCase
{
    /** @var array<string, mixed> the flags set on the collection */
    private array $flags = [];

    private function collection(int $linkTypeId): Collection
    {
        // getLinkTypeId() is a magic getter over the link model's data, which useRelatedLinks()
        // and its siblings set, so the type travels as data rather than as a declared method.
        $linkModel = new DataObject(['link_type_id' => $linkTypeId]);

        $collection = $this->createMock(Collection::class);
        $collection->method('getLinkModel')->willReturn($linkModel);
        $collection->method('setFlag')->willReturnCallback(
            function (string $key, $value) use ($collection) {
                $this->flags[$key] = $value;

                return $collection;
            }
        );

        return $collection;
    }

    private function flagged(int $linkTypeId): bool
    {
        $this->flags = [];
        $collection = $this->collection($linkTypeId);

        (new LinkCollectionFlag())->afterSetLinkModel($collection, $collection);

        return ($this->flags[CollectionFlag::FLAG] ?? false) === true;
    }

    public function testRelatedIsMarked(): void
    {
        $this->assertTrue($this->flagged(LinkType::LINK_TYPE_RELATED));
    }

    public function testUpsellIsMarked(): void
    {
        $this->assertTrue($this->flagged(LinkType::LINK_TYPE_UPSELL));
    }

    public function testCrosssellIsMarked(): void
    {
        $this->assertTrue($this->flagged(LinkType::LINK_TYPE_CROSSSELL));
    }

    public function testAGroupedProductsChildrenAreNotMarked(): void
    {
        // They come from this same class, and they are the product itself rather than a row of
        // cards. Hydrating a nested collection costs more than it saves.
        $this->assertFalse($this->flagged(GroupedLink::LINK_TYPE_GROUPED));
    }

    public function testTheCollectionIsPassedThrough(): void
    {
        $collection = $this->collection(LinkType::LINK_TYPE_RELATED);

        $result = (new LinkCollectionFlag())->afterSetLinkModel($collection, $collection);

        $this->assertSame($collection, $result);
    }
}
