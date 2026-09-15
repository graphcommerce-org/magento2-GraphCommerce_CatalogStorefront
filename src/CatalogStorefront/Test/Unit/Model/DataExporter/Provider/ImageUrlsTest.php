<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\ImageUrls;
use Magento\Catalog\Model\Product\Image;
use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ImageUrlsTest extends TestCase
{
    private const BASE_URL = 'https://shop.example/media/';

    public function testTheMediaPathLeavesTheBaseUrlOffAndAPlaceholderIsMarked(): void
    {
        $files = [];
        $provider = $this->provider($files, ['/m/b/mb01.jpg' => true, 'no_selection' => false]);

        $output = $provider->get([
            [
                'productId' => '1',
                'storeViewCode' => 'default',
                'image' => ['url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg'],
                'smallImage' => ['url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg'],
            ],
        ]);

        self::assertSame([
            'default_1' => [
                'productId' => '1',
                'storeViewCode' => 'default',
                'imageUrls' => [
                    'image' => ['mediaPath' => 'catalog/product/image/m/b/mb01.jpg'],
                    'small_image' => ['mediaPath' => 'catalog/product/small_image/m/b/mb01.jpg'],
                    'thumbnail' => ['placeholder' => true],
                ],
            ],
        ], $output);
        self::assertSame(
            ['image:/m/b/mb01.jpg', 'small_image:/m/b/mb01.jpg', 'thumbnail:no_selection'],
            $files
        );
    }

    public function testTheUrlOfOneFileAndTypeIsBuiltOnceForEveryProductOfTheBatch(): void
    {
        $files = [];
        $provider = $this->provider($files, ['/m/b/mb01.jpg' => true, 'no_selection' => false]);

        $provider->get([
            ['productId' => '1', 'storeViewCode' => 'default', 'image' => ['url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg']],
            ['productId' => '2', 'storeViewCode' => 'default', 'image' => ['url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg']],
        ]);

        self::assertSame(
            ['image:/m/b/mb01.jpg', 'small_image:no_selection', 'thumbnail:no_selection'],
            $files
        );
    }

    /**
     * @param array<string, bool> $exists the base files that resolve to an image, the others to a placeholder
     */
    private function provider(array &$files, array $exists): ImageUrls
    {
        $factory = $this->createMock(ImageFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$files, $exists): Image {
            $state = (object)['type' => '', 'file' => ''];
            $image = $this->createMock(Image::class);
            $image->method('setDestinationSubdir')->willReturnCallback(
                static function (string $type) use ($image, $state): Image {
                    $state->type = $type;

                    return $image;
                }
            );
            $image->method('setBaseFile')->willReturnCallback(
                static function (string $file) use ($image, $state, &$files): Image {
                    $state->file = $file;
                    $files[] = $state->type . ':' . $file;

                    return $image;
                }
            );
            $image->method('isBaseFilePlaceholder')->willReturnCallback(
                static fn(): bool => !($exists[$state->file] ?? false)
            );
            $image->method('getUrl')->willReturnCallback(
                static fn(): string => self::BASE_URL . 'catalog/product/' . $state->type . $state->file
            );

            return $image;
        });

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn(self::BASE_URL);
        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::once())->method('startEnvironmentEmulation')->with(1, 'frontend', true);
        $emulation->expects(self::once())->method('stopEnvironmentEmulation');

        return new ImageUrls($emulation, $stores, $factory);
    }
}
