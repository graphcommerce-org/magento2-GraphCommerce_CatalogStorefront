<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

class ScopesTest extends TestCase
{
    public function testTheStoreViewsComeFromTheWebsiteDocuments(): void
    {
        $scopes = $this->scopes([
            1 => ['id' => 1, 'code' => 'base', 'storeViews' => ['default', 'second']],
            2 => ['id' => 2, 'code' => 'other', 'storeViews' => ['third']],
        ], []);

        self::assertSame(['default', 'second', 'third'], $scopes->storeViews());
        self::assertSame(['default', 'second'], $scopes->storeViewsOfWebsite('base'));
        self::assertSame([], $scopes->storeViewsOfWebsite('missing'));
    }

    public function testTheNotLoggedInGroupComesFirstAndTheOthersByName(): void
    {
        $scopes = $this->scopes([1 => ['id' => 1, 'code' => 'base', 'storeViews' => ['default']]], [
            2 => ['id' => 2, 'code' => 'hash-2', 'name' => 'Wholesale'],
            3 => ['id' => 3, 'code' => 'hash-3', 'name' => 'Retailer'],
            1 => ['id' => 1, 'code' => 'hash-1', 'name' => 'General'],
        ]);

        self::assertSame(
            [sha1('0') => 0, 'hash-1' => 1, 'hash-3' => 3, 'hash-2' => 2],
            $scopes->customerGroups()
        );
    }

    public function testAnEmptyScopeIndexIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/scopesWebsite feed/');

        $this->scopes([], [])->storeViews();
    }

    private function scopes(array $websites, array $customerGroups): Scopes
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('all')->willReturnCallback(
            static fn(string $entity) => $entity === Scopes::WEBSITE ? $websites : $customerGroups
        );

        return new Scopes($storage);
    }
}
