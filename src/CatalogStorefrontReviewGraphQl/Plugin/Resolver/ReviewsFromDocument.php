<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontReviewGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontReview\Model\Document\Writer\Reviews as ReviewDocuments;
use GraphCommerce\CatalogStorefrontReview\Model\Read\RatingDocuments;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Review\Model\Review\Config as ReviewsConfig;
use Magento\ReviewGraphQl\Model\Resolver\Product\Reviews;
use GraphCommerce\CatalogStorefront\Model\Strict;

/**
 * Serves a product's reviews from the review documents: the reviews visible
 * in the store view, newest first, paged by the store. Each item carries
 * its average rating and rating breakdown pre-filled (the vote percents over
 * the rating's scale, the rating names from the rating documents) and the
 * product's own model, which the core product resolver takes as is. A page
 * past the end keeps the core path, which reports it.
 */
class ReviewsFromDocument
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
        private readonly MetadataDocumentStorageInterface $reviews,
        private readonly RatingDocuments $ratingDocuments,
        private readonly Strict $strict,
    ) {
    }

    public function aroundResolve(
        Reviews $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        $pageSize = (int)($args['pageSize'] ?? 0);
        $currentPage = (int)($args['currentPage'] ?? 0);
        if (!is_array($document) || !$this->reviewsConfig->isEnabled() || $pageSize < 1 || $currentPage < 1) {
            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $storeViewCode = $context->getExtensionAttributes()->getStore()->getCode();
            ['documents' => $reviews, 'total' => $total] = $this->reviews->find(
                ReviewDocuments::ENTITY,
                $storeViewCode,
                ['productId' => (string)$value['model']->getId()],
                [['createdAt', 'desc'], ['reviewId', 'asc']],
                ($currentPage - 1) * $pageSize,
                $pageSize
            );
            $maxPages = (int)ceil($total / $pageSize);
            if ($currentPage > $maxPages && $total > 0) {
                $this->strict->fallback(self::class, 'page past the last page');
                return $proceed($field, $context, $info, $value, $args);
            }

            $items = [];
            $sku = $value['model']->getSku();
            foreach ($reviews as $review) {
                $percents = [];
                $breakdown = [];
                foreach ((array)($review['votes'] ?? []) as $ratingId => $vote) {
                    $scale = $this->ratingDocuments->scale($storeViewCode, (int)$ratingId);
                    if ($scale) {
                        $percents[] = (int)((int)$vote / $scale * 100);
                    }
                    $breakdown[] = ['name' => $this->ratingDocuments->name($storeViewCode, (int)$ratingId), 'value' => (string)$vote];
                }
                $items[] = [
                    'summary' => $review['title'] ?? '',
                    'text' => $review['text'] ?? '',
                    'nickname' => $review['nickname'] ?? '',
                    'created_at' => $review['createdAt'] ?? '',
                    'sku' => $sku,
                    'product' => ['model' => $value['model'], 'sku' => $sku],
                    PrefillerInterface::KEY => [
                        'average_rating' => $percents ? (float)number_format(array_sum($percents) / count($percents), 2) : 0.0,
                        'ratings_breakdown' => $breakdown,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $proceed($field, $context, $info, $value, $args);
        }

        return [
            'total_count' => $total,
            'items' => $items,
            'page_info' => ['page_size' => $pageSize, 'current_page' => $currentPage, 'total_pages' => $maxPages],
        ];
    }
}
