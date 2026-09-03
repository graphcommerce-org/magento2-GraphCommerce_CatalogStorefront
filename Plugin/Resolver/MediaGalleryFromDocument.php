<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves media_gallery straight from the feed document, skipping the model's
 * media gallery processing. Falls through to the core resolver for any product
 * not served from a document.
 */
class MediaGalleryFromDocument
{
    public function aroundResolve(
        MediaGallery $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $product = $value['model'] ?? null;
        $document = $product?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document) || !array_key_exists('media_gallery', $document)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $gallery = $document['media_gallery'] ?? [];
        usort($gallery, static fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        $entries = [];
        foreach ($gallery as $index => $entry) {
            $entries[] = [
                'file' => $this->toFile($entry['url'] ?? ''),
                'label' => $entry['label'] ?? $product->getName(),
                'position' => $entry['sort_order'] ?? $index + 1,
                'disabled' => false,
                'media_type' => 'image',
                'model' => $product,
            ];
        }

        return $entries;
    }

    private function toFile(string $url): string
    {
        $marker = '/catalog/product';
        $position = strpos($url, $marker);

        return $position === false ? $url : substr($url, $position + strlen($marker));
    }
}
