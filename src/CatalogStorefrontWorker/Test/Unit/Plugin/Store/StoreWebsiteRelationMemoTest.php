<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\Store;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use GraphCommerce\CatalogStorefrontWorker\Plugin\Store\StoreWebsiteRelationMemo;
use Magento\Store\Model\ResourceModel\StoreWebsiteRelation;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Stub/MemoFactory.php';

class StoreWebsiteRelationMemoTest extends TestCase
{
    public function testEveryArgumentKeysTheRelationUntilTheConfigGenerationChanges(): void
    {
        $generation = 'g1';
        $generations = $this->createStub(Generation::class);
        $generations->method('current')
            ->willReturnCallback(static function (string $name) use (&$generation): string {
                self::assertSame(Generation::CONFIG, $name);

                return $generation;
            });
        $factory = new class ($generations) extends MemoFactory {
            public function __construct(private readonly Generation $generations)
            {
            }

            public function create(array $data = []): Memo
            {
                return new Memo($this->generations, $data['name'], $data['limit'] ?? 5000);
            }
        };
        $plugin = new StoreWebsiteRelationMemo($factory);
        $subject = $this->createStub(StoreWebsiteRelation::class);
        $calls = 0;
        $proceed = static function (
            int $websiteId,
            bool $available,
            ?int $storeGroupId,
            ?int $storeId,
        ) use (&$calls): array {
            $calls++;

            return compact('websiteId', 'available', 'storeGroupId', 'storeId', 'calls');
        };

        $first = $plugin->aroundGetWebsiteStores($subject, $proceed, 1);
        self::assertSame($first, $plugin->aroundGetWebsiteStores($subject, $proceed, 1));
        self::assertSame(1, $calls, 'the same relation is reused in one config generation');

        self::assertSame(2, $plugin->aroundGetWebsiteStores($subject, $proceed, 2)['calls']);
        self::assertSame(3, $plugin->aroundGetWebsiteStores($subject, $proceed, 1, true)['calls']);
        self::assertSame(4, $plugin->aroundGetWebsiteStores($subject, $proceed, 1, false, 4)['calls']);
        self::assertSame(5, $plugin->aroundGetWebsiteStores($subject, $proceed, 1, false, null, 5)['calls']);
        self::assertSame(5, $calls, 'website, availability, group and store are separate memo dimensions');

        $generation = 'g2';
        $refilled = $plugin->aroundGetWebsiteStores($subject, $proceed, 1);
        self::assertSame(6, $refilled['calls']);
        self::assertNotSame($first, $refilled, 'a config generation change refills the relation');
    }
}
