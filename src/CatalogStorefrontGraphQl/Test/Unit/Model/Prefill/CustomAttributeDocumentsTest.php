<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model\Prefill;

use GraphCommerce\CatalogStorefront\Model\Read\AttributeDocuments;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Prefill\CustomAttributeDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\Store\Api\Data\StoreInterface;
use PHPUnit\Framework\TestCase;

class CustomAttributeDocumentsTest extends TestCase
{
    public function testThePageCodesAreFetchedOnceAndOnlyWhenTheFieldIsSelected(): void
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $attributeDocuments = $this->createMock(AttributeDocuments::class);
        $attributeDocuments->expects(self::once())->method('byCodes')->with('default', ['color', 'size', 'material']);
        $prefiller = new CustomAttributeDocuments($attributeDocuments);
        $documents = [
            1 => ['customAttributes' => [['attributeCode' => 'color', 'value' => '5'], ['attributeCode' => 'size', 'value' => '7']]],
            2 => ['customAttributes' => [['attributeCode' => 'size', 'value' => '8'], ['attributeCode' => 'material', 'value' => 'x']]],
            3 => [],
        ];

        self::assertSame([], $prefiller->fill([], $documents, new PrefillRequest($store, 'g0', ['custom_attributesV2', 'name'], [])));
        self::assertSame([], $prefiller->fill([], $documents, new PrefillRequest($store, 'g0', ['name'], [])));
    }
}
