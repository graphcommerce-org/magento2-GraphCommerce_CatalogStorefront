<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Prefill;

use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillRequest;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Image\Placeholder;
use Magento\Framework\GraphQl\Query\Uid;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The product fields that derive from the model and the base document alone:
 * uid, id, the new-from and new-to dates, the website, the media gallery
 * entries and the three image types.
 */
class ProductFields implements PrefillerInterface
{
    private const IMAGE_TYPES = ['image', 'small_image', 'thumbnail'];

    /** The products query hands a date field over translated to its attribute code. */
    private const DATE_ATTRIBUTES = ['new_from_date' => 'news_from_date', 'new_to_date' => 'news_to_date'];

    public function __construct(
        private readonly Uid $uidEncoder,
        private readonly Placeholder $placeholder,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function fill(array $models, array $documents, PrefillRequest $request): array
    {
        $mediaBaseUrl = $request->store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $placeholders = [];
        $output = [];
        foreach ($models as $id => $product) {
            $document = $documents[$id] ?? [];
            $filled = [];
            if ($request->selects('uid')) {
                $filled['uid'] = $this->uidEncoder->encode((string)$id);
            }
            if ($request->selects('id')) {
                $filled['id'] = (int)$id;
            }
            foreach (self::DATE_ATTRIBUTES as $field => $attribute) {
                if ($request->selects($field, $attribute)) {
                    $filled[$field] = $product->getData($attribute) ?: null;
                }
            }
            if ($request->selects('websites') && isset($document['websiteCode'])) {
                $website = $this->storeManager->getWebsite($document['websiteCode']);
                $filled['websites'] = [[
                    'id' => (int)$website->getId(),
                    'name' => $website->getName(),
                    'code' => $website->getCode(),
                    'sort_order' => $website->getSortOrder(),
                    'default_group_id' => $website->getDefaultGroupId(),
                    'is_default' => $website->getIsDefault(),
                ]];
            }
            if ($request->selects('media_gallery_entries')) {
                // The entries as the model holds them, plus the uid core encodes from the id.
                $filled['media_gallery_entries'] = array_map(
                    fn(array $entry) => $entry + ['id' => $entry['value_id'], 'uid' => $this->uidEncoder->encode((string)$entry['value_id']), 'content' => null, 'video_content' => null],
                    (array)($product->getData('media_gallery')['images'] ?? [])
                );
            }
            foreach (self::IMAGE_TYPES as $type) {
                $image = $document['imageUrls'][$type] ?? null;
                if ($request->selects($type) && is_array($image)) {
                    $filled[$type] = [
                        self::KEY => ['url' => isset($image['mediaPath'])
                            ? $mediaBaseUrl . $image['mediaPath']
                            : $placeholders[$type] ??= $this->placeholder->getPlaceholder($type)],
                        'label' => $product->getData($type . '_label') ?: $product->getData('name'),
                    ];
                }
            }
            $output[$id] = $filled;
        }

        return $output;
    }
}
