<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReview\Test\Unit\Model\Document\Writer;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\RatingId;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews;
use PHPUnit\Framework\TestCase;

/**
 * The document contract of the reviews feed: one document per store view the
 * review is visible in, the votes by rating id and their percents over the
 * scale the row carries, and the document removed where the review is not
 * visible.
 */
class ReviewsTest extends TestCase
{
    public function testThePercentsComeFromTheScaleOnTheRow(): void
    {
        [$storage, $upserts, $deletes] = $this->storage();

        $this->writer($storage)->write([
            [
                'reviewId' => 1,
                'productId' => 7,
                'title' => 'Good',
                'text' => 'Fine',
                'nickname' => 'Chi',
                'createdAt' => '2026-09-10 21:37:37',
                'visibility' => ['default'],
                'ratings' => [['ratingId' => base64_encode('4'), 'value' => '2']],
                'ratingScales' => [['ratingId' => 4, 'scale' => 5]],
            ],
            ['reviewId' => 2, 'productId' => 7, 'visibility' => ['second'], 'ratings' => []],
        ]);

        self::assertSame(['review', 'default', [1 => [
            'reviewId' => 1,
            'productId' => '7',
            'title' => 'Good',
            'text' => 'Fine',
            'nickname' => 'Chi',
            'createdAt' => '2026-09-10 21:37:37',
            'votes' => [4 => 2],
            'percents' => [40.0],
        ]]], $upserts->calls[0]);
        self::assertSame([2], array_keys($upserts->calls[1][2]));
        self::assertSame([['review', 'default', [2]], ['review', 'second', [1]]], $deletes->calls);
    }

    public function testAVoteOfARatingTheRowHasNoScaleForCountsAsNoPercent(): void
    {
        [$storage, $upserts] = $this->storage();

        $this->writer($storage)->write([[
            'reviewId' => 1,
            'productId' => 7,
            'visibility' => ['default'],
            'ratings' => [['ratingId' => '4', 'value' => '2']],
        ]]);

        self::assertSame([], $upserts->calls[0][2][1]['percents']);
        self::assertSame([4 => 2], $upserts->calls[0][2][1]['votes']);
    }

    private function writer(MetadataDocumentStorageInterface $storage): Reviews
    {
        $scopes = $this->createMock(Scopes::class);
        $scopes->method('storeViews')->willReturn(['default', 'second']);

        return new Reviews($storage, $scopes, new RatingId());
    }

    private function storage(): array
    {
        $upserts = (object)['calls' => []];
        $deletes = (object)['calls' => []];
        $storage = $this->createMock(MetadataDocumentStorageInterface::class);
        $storage->method('upsert')->willReturnCallback(
            static function (string $entity, string $store, array $documents) use ($upserts): void {
                $upserts->calls[] = [$entity, $store, $documents];
            }
        );
        $storage->method('delete')->willReturnCallback(
            static function (string $entity, string $store, array $ids) use ($deletes): void {
                $deletes->calls[] = [$entity, $store, $ids];
            }
        );

        return [$storage, $upserts, $deletes];
    }
}
