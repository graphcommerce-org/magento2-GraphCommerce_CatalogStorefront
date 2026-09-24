<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use PHPUnit\Framework\TestCase;

class ListingDocumentsTest extends TestCase
{
    public function testAStoreViewWithoutAListingHasNoDocuments(): void
    {
        $this->assertSame([], (new ListingDocuments())->documents('default'));
    }

    public function testDocumentsAreKeptPerStoreView(): void
    {
        $listing = new ListingDocuments();
        $listing->add('default', [1 => ['sku' => 'a']]);
        $listing->add('second', [2 => ['sku' => 'b']]);

        $this->assertSame([1 => ['sku' => 'a']], $listing->documents('default'));
        $this->assertSame([2 => ['sku' => 'b']], $listing->documents('second'));
    }

    public function testEveryRowOfCardsAddsToThePool(): void
    {
        // A detail page renders a related row and an upsell row, and a later row must not hide
        // what an earlier one put there.
        $listing = new ListingDocuments();
        $listing->add('default', [1 => ['sku' => 'a']]);
        $listing->add('default', [2 => ['sku' => 'b']]);

        $this->assertSame([1 => ['sku' => 'a'], 2 => ['sku' => 'b']], $listing->documents('default'));
    }

    public function testAProductInTwoRowsIsKeptOnce(): void
    {
        $listing = new ListingDocuments();
        $listing->add('default', [1 => ['sku' => 'a']]);
        $listing->add('default', [1 => ['sku' => 'a'], 2 => ['sku' => 'b']]);

        $this->assertSame([1 => ['sku' => 'a'], 2 => ['sku' => 'b']], $listing->documents('default'));
    }

    public function testResetDropsTheDocuments(): void
    {
        // The class outlives a request under an application server.
        $listing = new ListingDocuments();
        $listing->add('default', [1 => ['sku' => 'a']]);
        $listing->_resetState();

        $this->assertSame([], $listing->documents('default'));
    }
    public function testPriceDataIsKeptPerStoreViewAndMergedPerKind(): void
    {
        $listing = new ListingDocuments();
        $listing->add('default', [], ['configurable' => [1 => ['salable' => null]]]);
        $listing->add('default', [], ['configurable' => [2 => ['salable' => null]], 'grouped' => []]);
        $listing->add('other', [], ['configurable' => [3 => ['salable' => null]]]);

        $this->assertSame([1, 2], array_keys($listing->priceData('default')['configurable']));
        $this->assertSame([3], array_keys($listing->priceData('other')['configurable']));
        $this->assertSame([], $listing->priceData('none'));
    }
}
