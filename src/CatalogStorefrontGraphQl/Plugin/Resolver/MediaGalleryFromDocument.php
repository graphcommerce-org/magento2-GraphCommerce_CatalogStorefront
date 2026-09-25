<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Image\Placeholder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Serves gallery entries and their exported image URLs from product documents. */
class MediaGalleryFromDocument
{
    public function __construct(
        private readonly Strict $strict,
        private readonly StoreManagerInterface $storeManager,
        private readonly Placeholder $placeholder,
        private readonly Mode $mode,
    ) {
    }

    public function aroundResolve(
        MediaGallery $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->mode->documents()) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $product = $value['model'] ?? null;
        $document = $product?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document)) {
            return $proceed($field, $context, $info, $value, $args);
        }
        if (!array_key_exists('media_gallery', $document)) {
            $this->strict->fallback(self::class, 'document without media_gallery');

            return $proceed($field, $context, $info, $value, $args);
        }

        $gallery = $document['media_gallery'] ?? [];
        usort($gallery, static fn($a, $b) => ($a['sort_order'] ?? 0) <=> ($b['sort_order'] ?? 0));

        $entries = [];
        $mediaBaseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $placeholder = null;
        foreach ($gallery as $index => $entry) {
            $prefilled = [];
            if (isset($entry['mediaPath'])) {
                $prefilled['url'] = $mediaBaseUrl . $entry['mediaPath'];
            } elseif (!empty($entry['placeholder'])) {
                $prefilled['url'] = $placeholder ??= $this->placeholder->getPlaceholder('image');
            } elseif (isset($info->getFieldSelection()['url'])) {
                $this->strict->fallback(self::class, 'gallery entry without image URL');
            }
            $entries[] = [
                PrefillerInterface::KEY => $prefilled,
                'file' => $entry['file'] ?? '',
                'label' => $entry['label'] ?? $product->getName(),
                'position' => $entry['sort_order'] ?? $index + 1,
                'disabled' => false,
                'types' => (array)($entry['types'] ?? []),
                'media_type' => 'image',
                'model' => $product,
            ];
        }

        return $entries;
    }
}
