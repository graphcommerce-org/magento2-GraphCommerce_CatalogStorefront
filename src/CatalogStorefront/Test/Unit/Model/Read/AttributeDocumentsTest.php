<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use PHPUnit\Framework\TestCase;

class AttributeDocumentsTest extends TestCase
{
    public function testACodeIsFetchedOncePerRequestAndAMissingOneIsRemembered(): void
    {
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->expects(self::exactly(2))->method('get')->willReturnCallback(
            static fn(string $entity, string $store, array $codes) => match ($codes) {
                ['color', 'gone'] => ['color' => ['label' => 'Color']],
                ['size'] => ['size' => ['label' => 'Size']],
            }
        );
        $documents = new AttributeDocuments($storage);

        self::assertSame(['color' => ['label' => 'Color']], $documents->byCodes('default', ['color', 'gone']));
        self::assertSame(
            ['color' => ['label' => 'Color'], 'size' => ['label' => 'Size']],
            $documents->byCodes('default', ['color', 'size', 'gone'])
        );
        self::assertSame([], $documents->byCodes('default', ['gone']));

        $documents->_resetState();
        $storage->expects(self::once())->method('count')->with('attribute', 'default')->willReturn(0);
        self::assertFalse($documents->available('default'));
        self::assertFalse($documents->available('default'));
    }
}
