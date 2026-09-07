<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Serializer;
use GraphCommerce\CatalogStorefrontOpenSearch\Plugin\ClientSerializer;
use Magento\AdvancedSearch\Model\Client\ClientOptionsInterface;
use PHPUnit\Framework\TestCase;

class ClientSerializerTest extends TestCase
{
    public function testTheSerializerRidesTheClientOptions(): void
    {
        $serializer = new Serializer();
        $options = (new ClientSerializer($serializer))->afterPrepareClientOptions(
            $this->createMock(ClientOptionsInterface::class),
            ['hostname' => 'localhost', 'port' => '9200', 'engine' => 'opensearch']
        );

        self::assertSame(['hostname' => 'localhost', 'port' => '9200', 'engine' => 'opensearch', 'serializer' => $serializer], $options);
    }
}
