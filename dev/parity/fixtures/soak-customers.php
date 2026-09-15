<?php
/**
 * One customer per customer group, so a soak run has a token for every group
 * and for more than one tax destination. Run from the Magento root. A group
 * that already holds a customer keeps it; a group without one gets
 * `parity-group-<id>@example.com` with an address in the region the argument
 * list names, or the next region of the rotation below. Prints the email and
 * the group of every customer the run may use.
 *
 * The regions differ on purpose: the demo tax rule covers Michigan only, so a
 * memo that keeps one customer's rate answers another customer's request with
 * a rate the parity gate sees.
 */
declare(strict_types=1);

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');

$customers = $objectManager->get(CustomerRepositoryInterface::class);
$criteria = $objectManager->get(SearchCriteriaBuilder::class);
$store = $objectManager->get(StoreManagerInterface::class)->getStore();
$regions = [['MI', 33, '49628-7978', 'Calder'], ['CA', 12, '90001', 'Los Angeles'], ['NY', 43, '10001', 'New York']];

$groups = $objectManager->get(GroupRepositoryInterface::class)
    ->getList($criteria->create())
    ->getItems();
$position = 0;
foreach ($groups as $group) {
    $id = (int)$group->getId();
    if ($id === 0) {
        continue;
    }
    $found = $customers->getList($criteria->addFilter('group_id', $id)->create())->getItems();
    $criteria->create();
    if ($found) {
        $customer = reset($found);
        printf("group %d %s: %s\n", $id, $group->getCode(), $customer->getEmail());
        $position++;
        continue;
    }
    [$regionCode, $regionId, $postcode, $city] = $regions[$position % count($regions)];
    $position++;
    $email = sprintf('parity-group-%d@example.com', $id);

    $customer = $objectManager->get(CustomerInterfaceFactory::class)->create();
    $customer->setWebsiteId((int)$store->getWebsiteId())
        ->setStoreId((int)$store->getId())
        ->setGroupId($id)
        ->setEmail($email)
        ->setFirstname('Parity')
        ->setLastname($group->getCode());
    $customer = $objectManager->get(AccountManagementInterface::class)->createAccount($customer, 'Parity-soak-1');

    $region = $objectManager->get(RegionInterfaceFactory::class)->create();
    $region->setRegionCode($regionCode)->setRegionId($regionId);
    $address = $objectManager->get(AddressInterfaceFactory::class)->create();
    $address->setCustomerId((int)$customer->getId())
        ->setCountryId('US')
        ->setRegion($region)
        ->setRegionId($regionId)
        ->setPostcode($postcode)
        ->setCity($city)
        ->setStreet(['1 Parity Street'])
        ->setTelephone('0000000000')
        ->setFirstname('Parity')
        ->setLastname($group->getCode())
        ->setIsDefaultBilling(true)
        ->setIsDefaultShipping(true);
    $objectManager->get(AddressRepositoryInterface::class)->save($address);

    printf("group %d %s: %s created in %s\n", $id, $group->getCode(), $email, $regionCode);
}
