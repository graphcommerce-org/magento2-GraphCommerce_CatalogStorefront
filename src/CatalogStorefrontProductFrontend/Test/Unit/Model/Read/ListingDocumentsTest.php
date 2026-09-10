<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductListing\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefrontProductListing\Model\Read\ListingDocuments;
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
        $listing->set('default', [1 => ['sku' => 'a']]);
        $listing->set('second', [2 => ['sku' => 'b']]);

        $this->assertSame([1 => ['sku' => 'a']], $listing->documents('default'));
        $this->assertSame([2 => ['sku' => 'b']], $listing->documents('second'));
    }

    public function testResetDropsTheDocuments(): void
    {
        // The class outlives a request under an application server.
        $listing = new ListingDocuments();
        $listing->set('default', [1 => ['sku' => 'a']]);
        $listing->_resetState();

        $this->assertSame([], $listing->documents('default'));
    }
}
