<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\AdminLinks;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

class AdminLinksTest extends TestCase
{
    public function testLinksUseExistingAclProtectedMagentoRoutes(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnMap([
            ['Magento_Backend::store', true],
            ['Magento_Catalog::config_catalog', true],
            ['Magento_Indexer::index', true],
        ]);
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::exactly(3))->method('getUrl')->willReturnCallback(
            static fn(string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters),
        );

        $links = new AdminLinks($authorization, $url);

        self::assertSame('adminhtml/system_store/index?', $links->stores());
        self::assertSame('adminhtml/system_config/edit?section=catalog', $links->configuration());
        self::assertSame('indexer/indexer/list?', $links->indexers());
    }

    public function testUnauthorizedLinksAreNotRendered(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(false);
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::never())->method('getUrl');
        $links = new AdminLinks($authorization, $url);

        self::assertNull($links->stores());
        self::assertNull($links->configuration());
        self::assertNull($links->indexers());
    }

    public function testBroadConfigurationAccessDoesNotExposeDeniedCatalogSection(): void
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn(string $resource): bool => $resource === 'Magento_Config::config',
        );
        $url = $this->createMock(UrlInterface::class);
        $url->expects(self::never())->method('getUrl');

        self::assertNull((new AdminLinks($authorization, $url))->configuration());
    }
}
