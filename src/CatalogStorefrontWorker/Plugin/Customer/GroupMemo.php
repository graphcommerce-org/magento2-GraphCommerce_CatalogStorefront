<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Plugin\Customer;

use GraphCommerce\CatalogStorefrontWorker\Model\Generation;
use GraphCommerce\CatalogStorefrontWorker\Model\Memo;
use GraphCommerce\CatalogStorefrontWorker\Model\MemoFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;

/**
 * Remembers a customer group by id under the tax generation: the tax price
 * of every product asks the group for its tax class.
 */
class GroupMemo
{
    private readonly Memo $groups;

    public function __construct(MemoFactory $memoFactory)
    {
        $this->groups = $memoFactory->create(['name' => Generation::TAX]);
    }

    public function aroundGetById(GroupRepositoryInterface $subject, \Closure $proceed, $id): GroupInterface
    {
        return $this->groups->get((string)$id, static fn(): GroupInterface => $proceed($id));
    }
}
