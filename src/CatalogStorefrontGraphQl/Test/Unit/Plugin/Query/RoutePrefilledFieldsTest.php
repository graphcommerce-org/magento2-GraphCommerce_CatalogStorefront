<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Query;

use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query\RoutePrefilledFields;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Magento\Framework\GraphQl\Schema\SchemaGeneratorInterface;
use PHPUnit\Framework\TestCase;

class RoutePrefilledFieldsTest extends TestCase
{
    public function testAPrefilledFieldReadsTheValueAndFallsBackToTheResolver(): void
    {
        $interface = new InterfaceType(['name' => 'ProductInterface', 'fields' => ['uid' => Type::string()]]);
        $product = new ObjectType([
            'name' => 'SimpleProduct',
            'interfaces' => [$interface],
            'fields' => [
                'uid' => ['type' => Type::string(), 'resolve' => static fn($value) => 'resolved:' . $value['id']],
                'name' => ['type' => Type::string(), 'resolve' => static fn($value) => 'resolved-name'],
            ],
        ]);
        $schema = new Schema(['query' => new ObjectType(['name' => 'Query', 'fields' => ['product' => $product]]), 'types' => [$product]]);

        (new RoutePrefilledFields(['ProductInterface' => ['uid'], 'SimpleProduct' => ['missing']]))
            ->afterGenerate($this->createMock(SchemaGeneratorInterface::class), $schema);

        $uid = $product->getField('uid')->resolveFn;
        self::assertSame('filled', $uid(['id' => 1, PrefillerInterface::KEY => ['uid' => 'filled']], [], null, null));
        self::assertSame('resolved:1', $uid(['id' => 1, PrefillerInterface::KEY => ['name' => 'x']], [], null, null));
        self::assertSame('resolved:1', $uid(['id' => 1], [], null, null));
        self::assertSame('resolved-name', ($product->getField('name')->resolveFn)([PrefillerInterface::KEY => ['name' => 'filled']], [], null, null));
    }
}
