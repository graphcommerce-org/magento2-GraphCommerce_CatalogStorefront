<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Registry;

use GraphCommerce\CatalogStorefront\Model\Registry\Definition;
use GraphCommerce\CatalogStorefront\Model\Registry\NativeResources;
use GraphCommerce\CatalogStorefront\Model\Registry\ReadOnlyConfiguration;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class ReadOnlyConfigurationTest extends TestCase
{
    public function testTheMagentoConfigurationIsPresentedWithoutMutation(): void
    {
        $native = $this->createMock(NativeResources::class);
        $native->expects(self::never())->method('snapshot');
        $service = new ReadOnlyConfiguration($native, $this->createStub(Definition::class));
        self::assertFalse($service->capabilities()['can_manage']);
        foreach (Definition::KINDS as $kind) {
            try {
                $service->save($kind, ['type' => 'generic', 'code' => 'test', 'name' => 'Test']);
                self::fail('The base module must not save ' . $kind);
            } catch (LocalizedException $e) {
                self::assertStringContainsString('cannot be saved here', $e->getMessage());
            }
            try {
                $service->delete($kind, 1, 0);
                self::fail('The base module must not delete ' . $kind);
            } catch (LocalizedException $e) {
                self::assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        }
    }

    public function testOnlyNativeSnapshotValuesArePresented(): void
    {
        $native = $this->createStub(NativeResources::class);
        $native->method('snapshot')->willReturn([
            'books' => [['id' => 4, 'code' => 'base', 'effective_currency' => 'EUR']],
            'layers' => [],
        ]);
        $service = new ReadOnlyConfiguration($native, $this->createStub(Definition::class));
        self::assertSame('EUR', $service->bookCurrency(4));
        self::assertSame([], $service->all('layers'));
        self::assertSame('base', $service->get('books', 4)['code']);
        self::assertSame($service->capabilities(), json_decode(json_encode($service->capabilities(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }
}
