<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Test\Unit\Plugin\Query;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use GraphCommerce\CatalogStorefrontWorker\Plugin\Query\KeepParsedDocuments;
use GraphQL\Language\Parser;
use Magento\Framework\GraphQl\Query\QueryParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Stub/MemoFactory.php';

class KeepParsedDocumentsTest extends TestCase
{
    public function testARepeatedQueryTextGetsTheSameDocumentUntilTheGenerationChanges(): void
    {
        $generation = 'g1';
        $generations = $this->createMock(Generation::class);
        $generations->method('current')->willReturnCallback(static function () use (&$generation) { return $generation; });
        $factory = new class ($generations) extends MemoFactory {
            public function __construct(private readonly Generation $generations)
            {
            }

            public function create(array $data = []): Memo
            {
                return new Memo($this->generations, $data['name'], $data['limit']);
            }
        };
        $parses = 0;
        $proceed = static function (string $query) use (&$parses) {
            $parses++;

            return Parser::parse($query);
        };
        $plugin = new KeepParsedDocuments($factory);
        $parser = $this->createMock(QueryParser::class);

        $first = $plugin->aroundParse($parser, $proceed, '{ products { items { sku } } }');
        self::assertSame($first, $plugin->aroundParse($parser, $proceed, '{ products { items { sku } } }'));
        self::assertNotSame($first, $plugin->aroundParse($parser, $proceed, '{ products { items { name } } }'));
        self::assertSame(2, $parses);

        $generation = 'g2';
        self::assertNotSame($first, $plugin->aroundParse($parser, $proceed, '{ products { items { sku } } }'));
        self::assertSame(3, $parses);
    }
}
