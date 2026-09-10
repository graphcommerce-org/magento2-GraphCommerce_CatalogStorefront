<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProduct\Test\Unit;

use Composer\InstalledVersions;
use Magento\Framework\Config\Dom;
use Magento\Framework\Config\ValidationStateInterface;
use PHPUnit\Framework\TestCase;

class EtConfigurationTest extends TestCase
{
    public function testModuleLoadsAfterTheVariantMetadataItExtends(): void
    {
        $module = new \DOMDocument();
        self::assertTrue($module->load(dirname(__DIR__, 2) . '/etc/module.xml'));
        $xpath = new \DOMXPath($module);

        self::assertSame(1, $xpath->query(
            '/config/module[@name="GraphCommerce_CatalogStorefrontConfigurableProduct"]'
            . '/sequence/module[@name="Magento_ProductVariantDataExporter"]'
        )?->length);
    }

    public function testVariantTombstonesRetainTheirParentIdentity(): void
    {
        $adobe = InstalledVersions::getInstallPath('magento/module-product-variant-data-exporter');
        self::assertNotNull($adobe);
        $validation = $this->createStub(ValidationStateInterface::class);
        $validation->method('isValidationRequired')->willReturn(false);
        $merged = new Dom(
            (string)file_get_contents($adobe . '/etc/di.xml'),
            $validation,
            [
                '/config/(type|virtualType)' => 'name',
                '/config/(type|virtualType)/arguments/argument' => 'name',
                '/config/(type|virtualType)/arguments/argument(/item)+' => 'name',
            ],
            'xsi:type',
        );
        $merged->merge((string)file_get_contents(dirname(__DIR__, 2) . '/etc/di.xml'));

        $xpath = new \DOMXPath($merged->getDom());
        $items = $xpath->query(
            '//virtualType[@name="Magento\\ProductVariantDataExporter\\Model\\Indexer\\ProductVariantFeedIndexMetadata"]'
            . '/arguments/argument[@name="minimalPayload"]/item'
        );
        self::assertNotFalse($items);
        $payload = [];
        foreach ($items as $item) {
            self::assertInstanceOf(\DOMElement::class, $item);
            $payload[$item->getAttribute('name')] = trim($item->textContent);
        }

        self::assertSame('productId', $payload['productId'] ?? null);
        self::assertSame('parentSku', $payload['parentSku'] ?? null);
        self::assertSame('parentId', $payload['parentId'] ?? null);
    }
}
