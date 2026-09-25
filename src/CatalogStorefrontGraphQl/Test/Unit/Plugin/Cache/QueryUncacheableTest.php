<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Cache;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\StorefrontKey;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Cache\QueryUncacheable;
use Magento\GraphQlCache\Model\CacheableQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QueryUncacheableTest extends TestCase
{
    #[DataProvider('cacheModes')]
    public function testDocumentAndKeyedResponsesPreventHttpCaching(
        bool $cacheable,
        bool $documents,
        bool $keyed,
        bool $expected,
    ): void {
        $key = $this->createStub(StorefrontKey::class);
        $key->method('granted')->willReturn($keyed);
        $mode = $this->createStub(Mode::class);
        $mode->method('documents')->willReturn($documents);

        self::assertSame($expected, (new QueryUncacheable($key, $mode))->afterIsCacheable(
            $this->createStub(CacheableQuery::class),
            $cacheable,
        ));
    }

    public static function cacheModes(): array
    {
        return [
            'public documents' => [true, true, false, false],
            'public core' => [true, false, false, true],
            'keyed documents' => [true, true, true, false],
            'keyed core' => [true, false, true, false],
            'uncacheable documents' => [false, true, false, false],
            'uncacheable core' => [false, false, false, false],
            'uncacheable keyed documents' => [false, true, true, false],
            'uncacheable keyed core' => [false, false, true, false],
        ];
    }
}
