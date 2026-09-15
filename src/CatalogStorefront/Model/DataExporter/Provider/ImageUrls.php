<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Framework\App\Area;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Exports the image URL per image type as core's media gallery URL resolver
 * builds it, under the store view's environment: the media base URL left off,
 * a placeholder marked as such. A changed media configuration reaches the
 * documents with the next export of the products feed.
 */
class ImageUrls
{
    private const IMAGE_TYPES = ['image' => 'image', 'small_image' => 'smallImage', 'thumbnail' => 'thumbnail'];

    private array $imageUrls = [];

    public function __construct(
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageFactory $imageFactory,
    ) {
    }

    public function get(array $values): array
    {
        $rowsByStore = [];
        foreach ($values as $value) {
            $rowsByStore[$value['storeViewCode']][] = $value;
        }
        $output = [];
        foreach ($rowsByStore as $storeViewCode => $rows) {
            $store = $this->storeManager->getStore($storeViewCode);
            $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
            try {
                $mediaBaseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
                foreach ($rows as $row) {
                    $imageUrls = [];
                    foreach (self::IMAGE_TYPES as $type => $key) {
                        // The media path travels without the store's media base URL, so a hostname change needs no
                        // export; a placeholder is resolved on read, its URL carries the static content version.
                        $url = $this->imageUrl($storeViewCode, $type, $this->mediaFile($row[$key]['url'] ?? null));
                        $imageUrls[$type] = $url === null
                            ? ['placeholder' => true]
                            : ['mediaPath' => substr($url, strlen($mediaBaseUrl))];
                    }
                    $output[$storeViewCode . '_' . $row['productId']] = [
                        'productId' => $row['productId'],
                        'storeViewCode' => $storeViewCode,
                        'imageUrls' => $imageUrls,
                    ];
                }
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        return $output;
    }

    /**
     * @return string|null the resized image URL, null for a placeholder
     */
    private function imageUrl(string $storeViewCode, string $type, string $file): ?string
    {
        $key = $storeViewCode . ':' . $type . ':' . $file;
        if (!array_key_exists($key, $this->imageUrls)) {
            $image = $this->imageFactory->create();
            $image->setDestinationSubdir($type)->setBaseFile($file);
            $this->imageUrls[$key] = $image->isBaseFilePlaceholder() ? null : $image->getUrl();
        }

        return $this->imageUrls[$key];
    }

    private function mediaFile(?string $url): string
    {
        if ($url === null) {
            return 'no_selection';
        }
        $position = strpos($url, '/catalog/product');

        return $position === false ? $url : substr($url, $position + strlen('/catalog/product'));
    }
}
