<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\ImageFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Area;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;

/**
 * Adds to a products feed row, at index time and under the store view's
 * environment, what the read side otherwise derives per request: the image
 * URLs per image type as core's media gallery URL resolver builds them
 * (the media base URL left off, a placeholder marked as such), the option ids
 * behind the select attribute labels, and the tax class id behind its label. A changed media configuration,
 * option or tax class reaches the documents with the next export of the
 * products feed.
 */
class ProductDocumentEnricher
{
    private const IMAGE_TYPES = ['image' => 'image', 'small_image' => 'smallImage', 'thumbnail' => 'thumbnail'];

    private array $imageUrls = [];

    private array $optionIds = [];

    private ?array $taxClassIds = null;

    public function __construct(
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ImageFactory $imageFactory,
        private readonly EavConfig $eavConfig,
        private readonly TaxClassCollectionFactory $taxClassCollectionFactory,
    ) {
    }

    /**
     * @param array[] $rows products feed rows of one store view, keyed by product id
     * @return array[] the rows with imageUrls, attributes[].valueId and taxClassNumericId
     */
    public function enrich(string $storeViewCode, array $rows): array
    {
        $store = $this->storeManager->getStore($storeViewCode);
        $this->emulation->startEnvironmentEmulation((int)$store->getId(), Area::AREA_FRONTEND, true);
        try {
            $mediaBaseUrl = $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            foreach ($rows as &$row) {
                foreach (self::IMAGE_TYPES as $type => $key) {
                    // The media path travels without the store's media base URL, so a hostname change needs no
                    // export; a placeholder is resolved on read, its URL carries the static content version.
                    $url = $this->imageUrl($storeViewCode, $type, $this->mediaFile($row[$key]['url'] ?? null));
                    $row['imageUrls'][$type] = $url === null ? ['placeholder' => true] : ['mediaPath' => substr($url, strlen($mediaBaseUrl))];
                }
                foreach ((array)($row['attributes'] ?? []) as $index => $attribute) {
                    if (!isset($attribute['attributeCode'])) {
                        continue;
                    }
                    $row['attributes'][$index]['valueId'] = array_map(
                        fn($label) => $this->optionId($storeViewCode, $attribute['attributeCode'], (string)$label),
                        (array)($attribute['value'] ?? [])
                    );
                }
                if (isset($row['taxClassId'])) {
                    $row['taxClassNumericId'] = $this->taxClassId((string)$row['taxClassId']);
                }
            }
        } finally {
            $this->emulation->stopEnvironmentEmulation();
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

    /**
     * Select and multiselect attributes come as option labels; the model holds
     * option ids. An attribute without a source keeps the label.
     */
    private function optionId(string $storeViewCode, string $code, string $label): ?string
    {
        $key = $storeViewCode . ':' . $code . ':' . $label;
        if (!array_key_exists($key, $this->optionIds)) {
            $this->optionIds[$key] = null;
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
                if ($attribute->getId() && $attribute->usesSource()) {
                    $optionId = $attribute->getSource()->getOptionId($label);
                    $this->optionIds[$key] = $optionId !== null ? (string)$optionId : null;
                }
            } catch (\Throwable) {
            }
        }

        return $this->optionIds[$key];
    }

    private function taxClassId(string $label): ?int
    {
        if ($this->taxClassIds === null) {
            $this->taxClassIds = [];
            foreach ($this->taxClassCollectionFactory->create() as $taxClass) {
                $this->taxClassIds[$taxClass->getClassName()] = (int)$taxClass->getId();
            }
        }

        return $this->taxClassIds[$label] ?? null;
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
