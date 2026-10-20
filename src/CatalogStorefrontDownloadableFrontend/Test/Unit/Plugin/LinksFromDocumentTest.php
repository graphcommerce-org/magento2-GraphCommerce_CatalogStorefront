<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontDownloadableFrontend\Test\Unit\Plugin;

use GraphCommerce\CatalogStorefront\Model\DocumentReadException;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontDownloadableFrontend\Plugin\LinksFromDocument;
use Magento\Catalog\Model\Product;
use Magento\Downloadable\Model\Link;
use Magento\Downloadable\Model\LinkFactory;
use Magento\Downloadable\Model\Product\Type;
use Magento\Downloadable\Model\Sample;
use Magento\Downloadable\Model\SampleFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Data\CollectionFactory;
use Magento\Framework\GraphQl\Query\Uid;
use PHPUnit\Framework\TestCase;

class LinksFromDocumentTest extends TestCase
{
    private function plugin(): LinksFromDocument
    {
        $linkFactory = $this->createStub(LinkFactory::class);
        $linkFactory->method('create')->willReturnCallback(static function () {
            $link = (new \ReflectionClass(Link::class))->newInstanceWithoutConstructor();
            $link->setIdFieldName('link_id');

            return $link;
        });
        $sampleFactory = $this->createStub(SampleFactory::class);
        $sampleFactory->method('create')->willReturnCallback(static function () {
            $sample = (new \ReflectionClass(Sample::class))->newInstanceWithoutConstructor();
            $sample->setIdFieldName('sample_id');

            return $sample;
        });
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(
            fn() => new Collection($this->createStub(EntityFactoryInterface::class))
        );
        $uid = $this->createStub(Uid::class);
        $uid->method('decode')->willReturnCallback('base64_decode');

        return new LinksFromDocument($linkFactory, $sampleFactory, $collectionFactory, $uid);
    }

    private function product(?array $document): Product
    {
        $product = $this->getMockBuilder(Product::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $product->setIdFieldName('entity_id');
        $product->setData(['entity_id' => '42']);
        if ($document !== null) {
            $product->setData(ProductDocumentsInterface::DOCUMENT_KEY, $document);
        }

        return $product;
    }

    private static function link(int $id, int $sortOrder, ?string $infoUrl): array
    {
        return [
            'id' => base64_encode('downloadable/' . $id),
            'label' => 'Link ' . $id,
            'sortOrder' => $sortOrder,
            'price' => 2.5,
            'qty' => 3,
            'infoUrl' => $infoUrl,
        ];
    }

    public function testLinksComeFromTheDownloadableOptionInSortOrder(): void
    {
        $product = $this->product(['optionsV2' => [
            ['type' => 'downloadable', 'values' => [self::link(5, 2, null), self::link(4, 1, 'https://shop/sample/4')]],
        ]]);
        $core = static fn() => self::fail('The core link reader was called.');

        $links = $this->plugin()->aroundGetLinks($this->createStub(Type::class), $core, $product);

        self::assertSame(['4', '5'], array_map('strval', array_keys($links)));
        self::assertSame('Link 4', $links['4']->getTitle());
        self::assertSame('2.5', $links['4']->getPrice());
        self::assertSame('3', $links['4']->getNumberOfDownloads());
        self::assertSame('https://shop/sample/4', $links['4']->getSampleUrl());
        self::assertNull($links['5']->getSampleUrl());
        self::assertSame($product, $links['4']->getProduct());
        self::assertSame($links, $product->getDownloadableLinks());
    }

    public function testInvalidLinkUidStopsTheRead(): void
    {
        $product = $this->product(['optionsV2' => [
            ['type' => 'downloadable', 'values' => [['id' => base64_encode('bundle/1/2/3')]]],
        ]]);

        $this->expectException(DocumentReadException::class);
        $this->plugin()->aroundGetLinks($this->createStub(Type::class), static fn() => [], $product);
    }

    public function testSamplesComeFromTheDocumentInSortOrder(): void
    {
        $product = $this->product(['samples' => [
            ['resource' => ['label' => 'Second', 'url' => 'https://shop/downloadable/download/sample/sample_id/12'], 'sortOrder' => '2'],
            ['resource' => ['label' => 'First', 'url' => 'https://shop/downloadable/download/sample/sample_id/13'], 'sortOrder' => '1'],
        ]]);
        $core = static fn() => self::fail('The core sample reader was called.');

        $samples = $this->plugin()->aroundGetSamples($this->createStub(Type::class), $core, $product);

        self::assertSame([13, 12], array_map('intval', $samples->getAllIds()));
        self::assertSame('First', $samples->getFirstItem()->getTitle());
        self::assertSame($samples, $product->getDownloadableSamples());
    }

    public function testSampleUrlWithoutIdStopsTheRead(): void
    {
        $product = $this->product(['samples' => [['resource' => ['url' => 'https://shop/other']]]]);

        $this->expectException(DocumentReadException::class);
        $this->plugin()->aroundGetSamples($this->createStub(Type::class), static fn() => null, $product);
    }

    public function testProductWithoutADocumentGoesToCore(): void
    {
        $product = $this->product(null);
        $type = $this->createStub(Type::class);

        self::assertSame(['core'], $this->plugin()->aroundGetLinks($type, static fn() => ['core'], $product));
        self::assertSame('core', $this->plugin()->aroundGetSamples($type, static fn() => 'core', $product));
    }
}
