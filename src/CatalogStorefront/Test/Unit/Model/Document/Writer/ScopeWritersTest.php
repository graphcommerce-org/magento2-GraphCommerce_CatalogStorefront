<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefront\Model\Document\Writer\CustomerGroups;
use GraphCommerce\CatalogStorefront\Model\Document\Writer\Websites;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

/**
 * The document contract of the two scopes feeds: the store views of a website
 * flattened into one list, the group with the code its price rows name it
 * with, and a removed scope deleted.
 */
class ScopeWritersTest extends TestCase
{
    public function testTheWebsiteDocumentCarriesEveryStoreViewOfItsStoreGroups(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        (new Websites($storage))->write([
            [
                'websiteId' => '1',
                'websiteCode' => 'base',
                'stores' => [
                    ['storeId' => '1', 'storeViews' => [['storeViewCode' => 'default'], ['storeViewCode' => 'nl']]],
                    ['storeId' => '2', 'storeViews' => [['storeViewCode' => 'second']]],
                ],
            ],
            ['websiteId' => '2', 'deleted' => true],
            ['websiteCode' => 'no-id'],
        ]);

        self::assertSame([[Scopes::WEBSITE, Scopes::SCOPE, [
            1 => ['code' => 'base', 'storeViews' => ['default', 'nl', 'second']],
        ]]], $upserts->calls);
        self::assertSame([[Scopes::WEBSITE, Scopes::SCOPE, [2]]], $deletes->calls);
    }

    public function testTheGroupDocumentKeepsTheFeedCodeAndTheNameForTheNotLoggedInGroup(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        (new CustomerGroups($storage))->write([
            ['customerGroupId' => '0', 'customerGroupCode' => 'hash-0', 'name' => 'NOT LOGGED IN'],
            ['customerGroupId' => '4', 'deleted' => true],
        ]);

        self::assertSame([[Scopes::CUSTOMER_GROUP, Scopes::SCOPE, [
            0 => ['code' => 'hash-0', 'name' => 'NOT LOGGED IN'],
        ]]], $upserts->calls);
        self::assertSame([[Scopes::CUSTOMER_GROUP, Scopes::SCOPE, [4]]], $deletes->calls);
    }

    private function storage(): array
    {
        $upserts = (object)['calls' => []];
        $deletes = (object)['calls' => []];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('upsert')->willReturnCallback(
            static function (string $entity, string $scope, array $documents) use ($upserts): void {
                $upserts->calls[] = [$entity, $scope, $documents];
            }
        );
        $storage->method('delete')->willReturnCallback(
            static function (string $entity, string $scope, array $ids) use ($deletes): void {
                $deletes->calls[] = [$entity, $scope, $ids];
            }
        );

        return [$storage, $upserts, $deletes];
    }
}
