<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use Magento\CatalogGraphQl\Model\Resolver\Product\MediaGallery\Url;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Remembers image URLs per process. The core resolver builds an image model
 * and stats the file for every product on every request, while the URL is a
 * function of the image type, the file path and the store: the same input
 * gives the same URL until the process restarts, which any media or theme
 * configuration change needs on a worker anyway.
 */
class ImageUrlMemo
{
    private const LIMIT = 20000;

    /** @var array<string, string> */
    private array $urls = [];

    public function aroundResolve(
        Url $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $product = $value['model'] ?? null;
        $imageType = $value['image_type'] ?? null;
        $file = $imageType !== null ? $product?->getData($imageType) : ($value['file'] ?? null);
        if (!is_string($file) || $file === '') {
            return $proceed($field, $context, $info, $value, $args);
        }
        $key = $context->getExtensionAttributes()->getStore()->getId() . ':' . ($imageType ?? 'image') . ':' . $file;
        if (!isset($this->urls[$key])) {
            if (count($this->urls) >= self::LIMIT) {
                $this->urls = [];
            }
            $this->urls[$key] = $proceed($field, $context, $info, $value, $args);
        }

        return $this->urls[$key];
    }
}
