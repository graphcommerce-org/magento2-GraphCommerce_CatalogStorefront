<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Read;

use Magento\Catalog\Model\Product\ImageFactory;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Image\Placeholder;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Product image URL per store, image type and file, as the core media gallery
 * URL resolver builds it, remembered per process: the URL is a derivation of
 * the media configuration, and a changed configuration reaches a worker at
 * its next restart.
 */
class ImageUrl
{
    private const LIMIT = 20000;

    /** @var array<string, string> */
    private array $urls = [];

    public function __construct(
        private readonly ImageFactory $imageFactory,
        private readonly Placeholder $placeholder,
    ) {
    }

    public function get(StoreInterface $store, string $imageType, ?string $file): string
    {
        $key = $store->getId() . ':' . $imageType . ':' . ($file ?? '');
        if (!isset($this->urls[$key])) {
            if (count($this->urls) >= self::LIMIT) {
                $this->urls = [];
            }
            $image = $this->imageFactory->create();
            $image->setDestinationSubdir($imageType)->setBaseFile($file);
            $this->urls[$key] = $image->isBaseFilePlaceholder()
                ? $this->placeholder->getPlaceholder($imageType)
                : $image->getUrl();
        }

        return $this->urls[$key];
    }
}
