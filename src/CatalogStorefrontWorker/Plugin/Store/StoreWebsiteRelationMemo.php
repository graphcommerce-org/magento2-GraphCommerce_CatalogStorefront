<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Store;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Store\Model\ResourceModel\StoreWebsiteRelation;

/**
 * Keeps the store, group and website relation rows under the config generation.
 */
class StoreWebsiteRelationMemo
{
    private readonly Memo $relations;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->relations = $memoFactory->create(['name' => Generation::CONFIG]);
    }

    public function aroundGetWebsiteStores(
        StoreWebsiteRelation $subject,
        \Closure $proceed,
        int $websiteId,
        bool $available = false,
        ?int $storeGroupId = null,
        ?int $storeId = null,
    ): array {
        $key = json_encode([$websiteId, $available, $storeGroupId, $storeId], JSON_THROW_ON_ERROR);

        return $this->relations->get(
            $key,
            static fn(): array => $proceed($websiteId, $available, $storeGroupId, $storeId),
        );
    }
}
