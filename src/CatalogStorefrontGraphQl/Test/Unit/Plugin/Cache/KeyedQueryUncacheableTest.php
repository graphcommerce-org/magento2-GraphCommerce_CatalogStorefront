<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Cache;

use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Cache\KeyedQueryUncacheable;
use Magento\GraphQlCache\Model\CacheableQuery;
use PHPUnit\Framework\TestCase;

class KeyedQueryUncacheableTest extends TestCase
{
    public function testAKeyedRequestIsNeverCacheableAndOthersKeepTheirVerdict(): void
    {
        $query = $this->createMock(CacheableQuery::class);
        $keyed = $this->createMock(StorefrontKey::class);
        $keyed->method('granted')->willReturn(true);
        $plain = $this->createMock(StorefrontKey::class);
        $plain->method('granted')->willReturn(false);

        self::assertFalse((new KeyedQueryUncacheable($keyed))->afterIsCacheable($query, true));
        self::assertTrue((new KeyedQueryUncacheable($plain))->afterIsCacheable($query, true));
        self::assertFalse((new KeyedQueryUncacheable($plain))->afterIsCacheable($query, false));
    }
}
