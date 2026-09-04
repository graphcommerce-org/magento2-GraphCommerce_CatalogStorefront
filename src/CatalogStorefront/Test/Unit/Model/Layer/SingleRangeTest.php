<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Test\Unit\Model\Layer;

use GraphCommerce\CatalogStorefront\Model\Layer\SingleRange;
use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Request\BucketInterface;
use PHPUnit\Framework\TestCase;

class SingleRangeTest extends TestCase
{
    public function testOneRangeFromTheStats(): void
    {
        self::assertSame(
            [['from' => 0.5, 'to' => 89.99, 'count' => 191]],
            SingleRange::range(['count' => 191, 'min' => 0.5, 'max' => 89.98999786376953])
        );
        self::assertSame([['from' => 12.0, 'to' => 12.0, 'count' => 3]], SingleRange::range(['count' => 3, 'min' => 12, 'max' => 12]));
        self::assertSame([], SingleRange::range(['count' => 0, 'min' => null, 'max' => null]));
    }

    public function testAsksTheDataProviderOnlyForANonEmptyResult(): void
    {
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $dataProvider->expects(self::once())->method('getAggregations')
            ->willReturn(['count' => 2, 'min' => 5, 'max' => 7, 'std' => 1]);
        $algorithm = new SingleRange($dataProvider);
        $bucket = $this->createMock(BucketInterface::class);

        self::assertSame([], $algorithm->getItems($bucket, [], new EntityStorage([])));
        self::assertSame([['from' => 5.0, 'to' => 7.0, 'count' => 2]], $algorithm->getItems($bucket, [], new EntityStorage([1, 2])));
    }
}
