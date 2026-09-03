<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\Prefill;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use GraphCommerce\CatalogStorefront\Model\Read\RatingMetadata;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Review\Model\Review\Config as ReviewsConfig;
use Magento\ReviewGraphQl\Model\Resolver\Product\Reviews;
use Psr\Log\LoggerInterface;

/**
 * Serves a product's reviews from the reviews slice of its document: the
 * reviews visible in the store view, newest first, paged as asked. Each
 * item carries its average rating and rating breakdown pre-filled (the vote
 * percents over the rating's scale, the rating names from the rating
 * documents) and the product's own model, which the core product resolver
 * takes as is. A page past the end keeps the core path, which reports it.
 */
class ReviewsFromDocument
{
    public function __construct(
        private readonly ReviewsConfig $reviewsConfig,
        private readonly RatingMetadata $ratingMetadata,
        private readonly LoggerInterface $logger,
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
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        $pageSize = (int)($args['pageSize'] ?? 0);
        $currentPage = (int)($args['currentPage'] ?? 0);
        if (!is_array($document) || !$this->reviewsConfig->isEnabled() || $pageSize < 1 || $currentPage < 1) {
            return $proceed($field, $context, $info, $value, $args);
        }

        try {
            $storeViewCode = $context->getExtensionAttributes()->getStore()->getCode();
            $reviews = [];
            foreach ((array)($document['reviews'] ?? []) as $key => $review) {
                if (is_array($review)) {
                    $reviews[(int)substr((string)$key, 1)] = $review;
                }
            }
            uksort($reviews, static fn(int $a, int $b) =>
                [$reviews[$b]['createdAt'] ?? '', $a] <=> [$reviews[$a]['createdAt'] ?? '', $b]);
            $total = count($reviews);
            $maxPages = (int)ceil($total / $pageSize);
            if ($currentPage > $maxPages && $total > 0) {
                return $proceed($field, $context, $info, $value, $args);
            }

            $items = [];
            $sku = $value['model']->getSku();
            foreach (array_slice($reviews, ($currentPage - 1) * $pageSize, $pageSize, true) as $review) {
                $percents = [];
                $breakdown = [];
                foreach ((array)($review['votes'] ?? []) as $ratingId => $vote) {
                    $scale = $this->ratingMetadata->scale($storeViewCode, (int)$ratingId);
                    if ($scale) {
                        $percents[] = (int)((int)$vote / $scale * 100);
                    }
                    $breakdown[] = ['name' => $this->ratingMetadata->name($storeViewCode, (int)$ratingId), 'value' => (string)$vote];
                }
                $items[] = [
                    'summary' => $review['title'] ?? '',
                    'text' => $review['text'] ?? '',
                    'nickname' => $review['nickname'] ?? '',
                    'created_at' => $review['createdAt'] ?? '',
                    'sku' => $sku,
                    'product' => ['model' => $value['model'], 'sku' => $sku],
                    Prefill::KEY => [
                        'average_rating' => $percents ? (float)number_format(array_sum($percents) / count($percents), 2) : 0.0,
                        'ratings_breakdown' => $breakdown,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront reviews fallback: ' . $e->getMessage());

            return $proceed($field, $context, $info, $value, $args);
        }

        return [
            'total_count' => $total,
            'items' => $items,
            'page_info' => ['page_size' => $pageSize, 'current_page' => $currentPage, 'total_pages' => $maxPages],
        ];
    }
}
