<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontOpenSearch\Test\Unit\Model;

use GraphCommerce\CatalogStorefrontOpenSearch\Model\Serializer;
use OpenSearch\Exception\JsonException;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    public function testJsonResponsesDecodeToArraysAndOthersPassThrough(): void
    {
        $serializer = new Serializer();
        $document = ['hits' => ['hits' => [['_id' => '1', '_source' => ['sku' => 'A', 'price' => 1.5, 'tags' => []]]]]];
        $json = json_encode($document);

        self::assertSame($document, $serializer->deserialize($json, ['Content-Type' => ['application/json; charset=UTF-8']]));
        self::assertSame($document, $serializer->deserialize($json, ['content_type' => 'application/json']));
        self::assertSame([], $serializer->deserialize('', ['content_type' => 'application/json']));
        self::assertSame('plain', $serializer->deserialize('plain', ['content_type' => 'text/plain']));
    }

    public function testInvalidJsonThrowsTheClientsException(): void
    {
        $this->expectException(JsonException::class);
        (new Serializer())->deserialize('{"hits": ', ['content_type' => 'application/json']);
    }
}
