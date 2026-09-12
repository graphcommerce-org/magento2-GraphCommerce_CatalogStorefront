<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Test\Unit\Ui\Component;

use GraphCommerce\CatalogStorefrontAdminhtml\Ui\Component\LocaleName;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\ResourceLabels;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use PHPUnit\Framework\TestCase;

final class LocaleNameTest extends TestCase
{
    public function testLocaleCodesRenderFullNamesAndKeepAbsentLocalesBlank(): void
    {
        $locale=$this->createStub(ResolverInterface::class);$locale->method('getLocale')->willReturn('en_US');
        $column=new LocaleName($this->createStub(ContextInterface::class),$this->createStub(UiComponentFactory::class),$locale,[],['name'=>'locale']);
        $data=$column->prepareDataSource(['data'=>['items'=>[['locale'=>'en_US'],['locale'=>'nl_NL'],['locale'=>''],['locale'=>'All locales'],['locale'=>'zh_Hans_CN']]]]);
        self::assertSame(['English (United States)','Dutch (Netherlands)','','All locales','Chinese (Simplified, China)'],array_column($data['data']['items'],'locale'));
    }
    public function testBookDisplayNamesDoNotMutateGroupLabelsContainingSlashes(): void
    {
        self::assertSame('NOT LOGGED IN',ResourceLabels::name(['type'=>'platform_customer_group','name'=>'Main Website / NOT LOGGED IN']));
        self::assertSame('Trade / Wholesale',ResourceLabels::name(['type'=>'platform_customer_group','name'=>'Main Website / Trade / Wholesale']));
        self::assertSame('A / B',ResourceLabels::name(['type'=>'generic','name'=>'A / B']));
    }
}
