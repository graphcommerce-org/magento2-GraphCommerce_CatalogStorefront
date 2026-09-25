<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Product\ImageFactory;
use Magento\CatalogDataExporter\Model\Provider\Product\MediaGallery;
use Magento\Framework\App\Area;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/** Exports media paths for product image roles and gallery entries. */
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

    public function afterGet(MediaGallery $subject, array $rows): array
    {
        $rowsByStore = [];
        foreach ($rows as $key => $row) {
            if (isset($row['media_gallery'])) {
                $rowsByStore[$row['storeViewCode']][$key] = $row;
            }
        }
        foreach ($rowsByStore as $storeViewCode => $storeRows) {
            $store = $this->storeManager->getStore($storeViewCode);
            $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
            try {
                $mediaBaseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
                foreach ($storeRows as $key => $row) {
                    $url = $this->imageUrl($storeViewCode, 'image', $this->mediaFile($row['media_gallery']['url'] ?? null));
                    $rows[$key]['media_gallery']['imageUrl'] = $url === null
                        ? ['placeholder' => true]
                        : ['mediaPath' => substr($url, strlen($mediaBaseUrl))];
                }
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        return $rows;
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
