<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query\RoutePrefilledFields;
use GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver\MediaGalleryFromDocument;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Magento\Catalog\Model\Product;
use Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Image\Placeholder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Schema\Type\TypeRegistry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class MediaGalleryFromDocumentTest extends TestCase
{
    public function testExportedPathsUseTheCurrentMediaHostAndSkipUrlResolvers(): void
    {
        $strict = $this->createMock(Strict::class);
        $strict->expects(self::never())->method('fallback');
        $placeholder = $this->createMock(Placeholder::class);
        $placeholder->expects(self::once())->method('getPlaceholder')->with('image')->willReturn('https://current.example/placeholder.jpg');
        $result = $this->resolve([
            ['file' => '/back.jpg', 'mediaPath' => 'catalog/product/cache/hash/back.jpg', 'sort_order' => 2, 'label' => 'Back'],
            ['file' => '/front.jpg', 'mediaPath' => 'catalog/product/cache/hash/front.jpg', 'sort_order' => 1, 'types' => ['image']],
            ['file' => '/missing.jpg', 'placeholder' => true, 'sort_order' => 3],
            ['file' => '/missing2.jpg', 'placeholder' => true, 'sort_order' => 4],
        ], $strict, $placeholder);
        $type = new ObjectType(['name' => 'ProductImage', 'fields' => ['url' => [
            'type' => Type::string(),
            'resolve' => static fn() => self::fail('The exported URL must bypass the image resolver.'),
        ]]]);
        (new RoutePrefilledFields(['ProductImage' => ['url']]))->afterGet($this->createMock(TypeRegistry::class), $type, 'ProductImage');
        $resolve = $type->getField('url')->resolveFn;

        self::assertSame('https://current.example/media/catalog/product/cache/hash/front.jpg', $resolve($result[0], [], null, null));
        self::assertSame('https://current.example/media/catalog/product/cache/hash/back.jpg', $resolve($result[1], [], null, null));
        self::assertSame('https://current.example/placeholder.jpg', $resolve($result[2], [], null, null));
        self::assertSame('https://current.example/placeholder.jpg', $resolve($result[3], [], null, null));
        self::assertSame(['image'], $result[0]['types']);
        self::assertSame('Product', $result[0]['label']);
        self::assertSame('Back', $result[1]['label']);
        self::assertSame('/front.jpg', $result[0]['file']);
    }

    public function testMissingUrlDataUsesTheCoreUrlResolverAndReportsTheFallback(): void
    {
        $strict = $this->createMock(Strict::class);
        $strict->expects(self::once())->method('fallback')->with(MediaGalleryFromDocument::class, 'gallery entry without image URL');
        $placeholder = $this->createMock(Placeholder::class);
        $placeholder->expects(self::never())->method('getPlaceholder');
        $result = $this->resolve([['file' => '/front.jpg']], $strict, $placeholder);
        self::assertArrayNotHasKey('url', $result[0][PrefillerInterface::KEY]);
        self::assertSame('/front.jpg', $result[0]['file']);
    }

    public function testCoreModeUsesTheCoreGallery(): void
    {
        $strict = $this->createMock(Strict::class);
        $strict->expects(self::never())->method('fallback');
        $placeholder = $this->createMock(Placeholder::class);
        $placeholder->expects(self::never())->method('getPlaceholder');
        self::assertSame(['core'], $this->resolve([], $strict, $placeholder, false));
    }

    private function resolve(array $gallery, Strict $strict, Placeholder $placeholder, bool $documents = true): array
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://current.example/media/');
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $mode = $this->createMock(Mode::class);
        $mode->method('documents')->willReturn($documents);
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods(['getData', 'getName'])->getMock();
        $product->method('getName')->willReturn('Product');
        $product->method('getData')->willReturnCallback(static fn(string $key) => match ($key) {
            HydrationInterface::DOCUMENT_KEY => ['media_gallery' => $gallery],
            'name' => 'Product',
            default => null,
        });
        $info = $this->createMock(ResolveInfo::class);
        $info->method('getFieldSelection')->willReturn(['url' => true]);

        return (new MediaGalleryFromDocument($strict, $stores, $placeholder, $mode))->aroundResolve(
            $this->createMock(MediaGallery::class),
            static fn() => $documents ? self::fail('The document must supply the gallery.') : ['core'],
            $this->createMock(Field::class),
            null,
            $info,
            ['model' => $product],
        );
    }
}
