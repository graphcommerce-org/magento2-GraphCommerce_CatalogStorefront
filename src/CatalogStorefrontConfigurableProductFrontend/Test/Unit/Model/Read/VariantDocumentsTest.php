<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\VariantDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class VariantDocumentsTest extends TestCase
{
    private ProductDocumentsInterface&MockObject $products;

    private ListingDocuments $page;

    protected function setUp(): void
    {
        $this->products = $this->createMock(ProductDocumentsInterface::class);
        $this->page = new ListingDocuments();
    }

    private function variants(): VariantDocuments
    {
        return new VariantDocuments(
            $this->products,
            $this->page,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function store(): StoreInterface
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');

        return $store;
    }

    public function testChildIdsAreAscendingAndUnique(): void
    {
        // The feed's key order is not id order, and a null value is a link it reports removed.
        $ids = VariantDocuments::childIds(
            ['variantIds' => ['v3' => 3, 'v1' => 1, 'v2' => 2, 'v9' => null, 'v1b' => 1]]
        );

        $this->assertSame([1, 2, 3], $ids);
    }

    public function testChildIdsOfADocumentWithoutVariants(): void
    {
        $this->assertSame([], VariantDocuments::childIds([]));
    }

    public function testOneFetchCoversEveryParentOnThePage(): void
    {
        $this->page->set('default', [
            100 => ['variantIds' => ['v2' => 2, 'v1' => 1]],
            200 => ['variantIds' => ['v3' => 3]],
        ]);
        $this->products->expects($this->once())
            ->method('documents')
            ->with('default', [1, 2, 3])
            ->willReturn([1 => ['sku' => 'a'], 2 => ['sku' => 'b'], 3 => ['sku' => 'c']]);

        $variants = $this->variants();

        $this->assertSame([1 => ['sku' => 'a'], 2 => ['sku' => 'b']], $variants->documents($this->store(), [1, 2]));
        $this->assertSame([3 => ['sku' => 'c']], $variants->documents($this->store(), [3]));
    }

    public function testIdsOutsideThePageAreNotCovered(): void
    {
        $this->page->set('default', [100 => ['variantIds' => ['v1' => 1]]]);
        $this->products->method('documents')->willReturn([1 => ['sku' => 'a']]);

        $this->assertNull($this->variants()->documents($this->store(), [1, 9]));
    }

    public function testNothingIsCoveredWithoutAListingPage(): void
    {
        // A product detail page renders configurables the listing fetch never saw.
        $this->products->expects($this->never())->method('documents');

        $this->assertNull($this->variants()->documents($this->store(), [1]));
    }

    public function testACoveredChildWithNoDocumentIsAbsent(): void
    {
        // The caller compares what it asked for against what it got, and falls back to core.
        $this->page->set('default', [100 => ['variantIds' => ['v1' => 1, 'v2' => 2]]]);
        $this->products->method('documents')->willReturn([1 => ['sku' => 'a']]);

        $this->assertSame([1 => ['sku' => 'a']], $this->variants()->documents($this->store(), [1, 2]));
    }

    public function testAFailedFetchIsAttemptedOnceAndCoversNothing(): void
    {
        // The fetch is an optimisation: every card falls back to its own, none repeats the failure.
        $this->page->set('default', [100 => ['variantIds' => ['v1' => 1]]]);
        $this->products->expects($this->once())
            ->method('documents')
            ->willThrowException(new \RuntimeException('no alive nodes'));

        $variants = $this->variants();

        $this->assertNull($variants->documents($this->store(), [1]));
        $this->assertNull($variants->documents($this->store(), [1]));
    }

    public function testResetDropsThePage(): void
    {
        // The class outlives a request under an application server.
        $this->page->set('default', [100 => ['variantIds' => ['v1' => 1]]]);
        $this->products->expects($this->exactly(2))
            ->method('documents')
            ->willReturn([1 => ['sku' => 'a']]);

        $variants = $this->variants();
        $variants->documents($this->store(), [1]);
        $variants->_resetState();

        $this->assertSame([1 => ['sku' => 'a']], $variants->documents($this->store(), [1]));
    }
}
