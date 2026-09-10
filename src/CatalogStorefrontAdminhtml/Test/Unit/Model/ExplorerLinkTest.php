<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontAdminhtml\Model\ExplorerLink;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExplorerLinkTest extends TestCase
{
    #[DataProvider('access')]
    public function testLinkRequiresTheExplorerModuleAndItsAcl(bool $enabled, bool $allowed, ?string $expected): void
    {
        $modules = $this->createMock(ModuleManager::class);
        $modules->expects(self::once())->method('isEnabled')->with('MageOS_GraphQLAdminHtml')->willReturn($enabled);
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->expects($enabled ? self::once() : self::never())
            ->method('isAllowed')
            ->with('MageOS_GraphQLAdminHtml::graphql')
            ->willReturn($allowed);
        $url = $this->createMock(UrlInterface::class);
        $url->expects($enabled && $allowed ? self::once() : self::never())
            ->method('getUrl')
            ->with('mageos_graphql/index/index')
            ->willReturn('/admin/mageos_graphql/index/index/key/redacted');

        self::assertSame($expected, (new ExplorerLink($modules, $authorization, $url))->url());
    }

    /** @return array<string, array{bool, bool, string|null}> */
    public static function access(): array
    {
        return [
            'module disabled' => [false, true, null],
            'ACL denied' => [true, false, null],
            'available and authorized' => [true, true, '/admin/mageos_graphql/index/index/key/redacted'],
        ];
    }
}
