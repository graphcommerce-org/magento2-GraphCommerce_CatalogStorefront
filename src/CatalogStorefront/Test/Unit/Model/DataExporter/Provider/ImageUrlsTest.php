<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\DataExporter\Provider;

use GraphCommerce\CatalogStorefront\Model\DataExporter\Provider\ImageUrls;
use Magento\Catalog\Model\Product\Image;
use Magento\Catalog\Model\Product\ImageFactory;
use Magento\CatalogDataExporter\Model\Provider\Product\MediaGallery;
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

    public function testGalleryPathsUseTheImageRoleAndMarkMissingFiles(): void
    {
        $files = [];
        $provider = $this->provider($files, ['/m/b/mb01.jpg' => true]);
        $rows = [
            'front' => ['productId' => '1', 'storeViewCode' => 'default', 'media_gallery' => [
                'url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg', 'sort_order' => 1,
            ]],
            'shared' => ['productId' => '2', 'storeViewCode' => 'default', 'media_gallery' => [
                'url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg', 'sort_order' => 2,
            ]],
            'missing' => ['productId' => '1', 'storeViewCode' => 'default', 'media_gallery' => [
                'url' => self::BASE_URL . 'catalog/product/missing.jpg',
            ]],
        ];

        $result = $provider->afterGet($this->createMock(MediaGallery::class), $rows);

        self::assertSame(['mediaPath' => 'catalog/product/image/m/b/mb01.jpg'], $result['front']['media_gallery']['imageUrl']);
        self::assertSame($result['front']['media_gallery']['imageUrl'], $result['shared']['media_gallery']['imageUrl']);
        self::assertSame(['placeholder' => true], $result['missing']['media_gallery']['imageUrl']);
        self::assertSame(['image:/m/b/mb01.jpg', 'image:/missing.jpg'], $files);
        foreach ($result as $key => $row) {
            unset($row['media_gallery']['imageUrl']);
            self::assertSame($rows[$key], $row);
        }
    }

    public function testGalleryPathsUseEachStoreMediaBaseUrl(): void
    {
        $files = [];
        $provider = $this->provider($files, ['/m/b/mb01.jpg' => true], [
            'default' => self::BASE_URL,
            'second' => 'https://second.example/assets/',
        ]);
        $result = $provider->afterGet($this->createMock(MediaGallery::class), [
            ['productId' => '1', 'storeViewCode' => 'default', 'media_gallery' => ['url' => self::BASE_URL . 'catalog/product/m/b/mb01.jpg']],
            ['productId' => '1', 'storeViewCode' => 'second', 'media_gallery' => ['url' => 'https://second.example/assets/catalog/product/m/b/mb01.jpg']],
        ]);

        self::assertSame(['mediaPath' => 'catalog/product/image/m/b/mb01.jpg'], $result[0]['media_gallery']['imageUrl']);
        self::assertSame($result[0]['media_gallery']['imageUrl'], $result[1]['media_gallery']['imageUrl']);
        self::assertSame(['image:/m/b/mb01.jpg', 'image:/m/b/mb01.jpg'], $files);
    }

    public function testSeparateImageAndVideoRecordsKeepTheirFields(): void
    {
        $files = [];
        $provider = $this->provider($files, [], []);
        $rows = [
            ['productId' => '1', 'storeViewCode' => 'default', 'images' => ['url' => self::BASE_URL . 'catalog/product/front.jpg']],
            ['productId' => '1', 'storeViewCode' => 'default', 'videos' => ['url' => self::BASE_URL . 'catalog/product/video.jpg']],
        ];

        self::assertSame($rows, $provider->afterGet($this->createMock(MediaGallery::class), $rows));
        self::assertSame([], $files);
    }

    /**
     * @param array<string, bool> $exists the base files that resolve to an image, the others to a placeholder
     */
    private function provider(array &$files, array $exists, array $baseUrls = ['default' => self::BASE_URL]): ImageUrls
    {
        $mediaBaseUrl = '';
        $factory = $this->createMock(ImageFactory::class);
        $factory->method('create')->willReturnCallback(function () use (&$files, $exists, &$mediaBaseUrl): Image {
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
                static fn(): string => $mediaBaseUrl . 'catalog/product/' . $state->type . $state->file
            );

            return $image;
        });

        $stores = $this->createMock(StoreManagerInterface::class);
        $stores->method('getStore')->willReturnCallback(function (string $code) use ($baseUrls, &$mediaBaseUrl): Store {
            $mediaBaseUrl = $baseUrls[$code];
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn(array_search($code, array_keys($baseUrls), true) + 1);
            $store->method('getBaseUrl')->willReturn($mediaBaseUrl);

            return $store;
        });
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects(self::exactly(count($baseUrls)))->method('startEnvironmentEmulation')
            ->with(self::greaterThan(0), 'frontend', true);
        $emulation->expects(self::exactly(count($baseUrls)))->method('stopEnvironmentEmulation');

        return new ImageUrls($emulation, $stores, $factory);
    }
}
