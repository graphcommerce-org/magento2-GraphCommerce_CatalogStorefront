<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit;

use Composer\InstalledVersions;
use Magento\Framework\Config\Dom;
use Magento\Framework\Config\ValidationStateInterface;
use PHPUnit\Framework\TestCase;

class EtConfigurationTest extends TestCase
{
    public function testModuleLoadsAfterTheVariantsFieldItReplaces(): void
    {
        $module = new \DOMDocument();
        self::assertTrue($module->load(dirname(__DIR__, 2) . '/etc/module.xml'));
        $xpath = new \DOMXPath($module);

        self::assertSame(1, $xpath->query(
            '/config/module[@name="GraphCommerce_CatalogStorefrontConfigurableProduct"]'
            . '/sequence/module[@name="Magento_ConfigurableProductDataExporter"]'
        )?->length);
    }

    public function testTheVariantsFieldTakesThisProviderAndKeepsItsUsingFields(): void
    {
        $adobe = InstalledVersions::getInstallPath('magento/module-configurable-product-data-exporter');
        self::assertNotNull($adobe);
        $validation = $this->createStub(ValidationStateInterface::class);
        $validation->method('isValidationRequired')->willReturn(false);
        $merged = new Dom(
            (string)file_get_contents($adobe . '/etc/et_schema.xml'),
            $validation,
            ['/config/record' => 'name', '/config/record/field' => 'name'],
        );
        $merged->merge((string)file_get_contents(dirname(__DIR__, 2) . '/etc/et_schema.xml'));

        $xpath = new \DOMXPath($merged->getDom());
        $field = $xpath->query('/config/record[@name="Product"]/field[@name="variants"]')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $field);
        self::assertSame(
            'GraphCommerce\CatalogStorefrontConfigurableProduct\Model\DataExporter\Provider\Variants',
            $field->getAttribute('provider')
        );
        self::assertSame('Variant', $field->getAttribute('type'));
        self::assertSame('true', $field->getAttribute('repeated'));
        $using = [];
        foreach ($xpath->query('using', $field) ?: [] as $node) {
            self::assertInstanceOf(\DOMElement::class, $node);
            $using[] = $node->getAttribute('field');
        }
        self::assertSame(['productId', 'storeViewCode'], $using);
    }
}
