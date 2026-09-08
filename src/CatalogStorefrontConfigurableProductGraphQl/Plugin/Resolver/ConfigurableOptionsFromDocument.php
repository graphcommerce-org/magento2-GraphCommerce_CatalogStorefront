<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontConfigurableProductGraphQl\Model\Read\ConfigurableOptions;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use GraphCommerce\CatalogStorefront\Model\Strict;
use Magento\ConfigurableProductGraphQl\Model\Resolver\Options;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Swatches\Helper\Media as SwatchMedia;
use Magento\Swatches\Model\Swatch;

/**
 * Serves configurable_options expanded from the document. An image swatch's
 * thumbnail URL is built here from the swatch file on the document and the
 * store's media base URL, so it carries the host of the request, as core's does.
 */
class ConfigurableOptionsFromDocument
{
    public function __construct(
        private readonly Strict $strict,
        private readonly SwatchMedia $swatchMedia,
        private readonly ConfigurableOptions $options,
    ) {
    }

    public function aroundResolve(
        Options $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document) || ($value['type_id'] ?? null) !== Configurable::TYPE_CODE) {
            return $proceed($field, $context, $info, $value, $args);
        }
        $options = $this->options->expand($document);
        if ($options === null) {
            $this->strict->fallback(self::class, 'configurable document without configurableOptions');

            return $proceed($field, $context, $info, $value, $args);
        }

        $thumbnailBase = $this->swatchMedia->getSwatchMediaUrl() . '/' . Swatch::SWATCH_THUMBNAIL_NAME . '/'
            . $this->swatchMedia->getFolderNameSize(Swatch::SWATCH_THUMBNAIL_NAME);
        $withThumbnail = static function (array $optionValue) use ($thumbnailBase): array {
            $swatch = $optionValue[PrefillerInterface::KEY]['swatch_data'] ?? null;
            if (($swatch['type'] ?? null) === Swatch::SWATCH_TYPE_VISUAL_IMAGE) {
                $optionValue[PrefillerInterface::KEY]['swatch_data']['thumbnail'] = $thumbnailBase . $swatch['value'];
            }

            return $optionValue;
        };

        return array_map(
            static fn(array $option) => ['values' => array_map($withThumbnail, $option['values'])] + $option,
            $options
        );
    }
}
