<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Provider;

use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds to a website row of the scopes feed, per store view, the media base URL
 * its product rows leave off and the placeholder URL of every image type. A
 * product row carries the media path only, so a changed host or a fresh static
 * content version reaches a reader through this feed instead of through every
 * product. The store emulation runs inside an area emulation, so the feed also
 * syncs from a process without an area code.
 */
class StoreViewMedia
{
    private const IMAGE_TYPES = ['image', 'small_image', 'thumbnail'];

    public function __construct(
        private readonly AppState $appState,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageFactory $imageFactory,
    ) {
    }

    public function get(array $values): array
    {
        $websiteIds = array_unique(array_map(static fn(array $value): int => (int)$value['websiteId'], $values));

        return $this->appState->emulateAreaCode(Area::AREA_FRONTEND, function () use ($websiteIds): array {
            $output = [];
            foreach ($websiteIds as $websiteId) {
                foreach ($this->storeManager->getWebsite($websiteId)->getStores() as $store) {
                    $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
                    try {
                        $placeholders = [];
                        foreach (self::IMAGE_TYPES as $type) {
                            $image = $this->imageFactory->create();
                            $image->setDestinationSubdir($type)->setBaseFile('no_selection');
                            $placeholders[$type] = $image->getUrl();
                        }
                        $output[$websiteId . '_' . $store->getCode()] = ['websiteId' => $websiteId, 'storeViewMedia' => [
                            'storeViewCode' => (string)$store->getCode(),
                            'mediaBaseUrl' => $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
                            'imagePlaceholders' => $placeholders,
                        ]];
                    } finally {
                        $this->emulation->stopEnvironmentEmulation();
                    }
                }
            }

            return $output;
        });
    }
}
