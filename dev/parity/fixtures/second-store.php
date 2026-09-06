<?php
/**
 * A second website with one store view, `second`, that sells every product
 * under the same root category: the parity gate runs against it with
 * `--header "Store: second"`, so the per-website fan-out of the price and
 * stock slices is covered. Run from the Magento root, then reindex. Does
 * nothing when the website exists.
 */
declare(strict_types=1);

use Magento\Catalog\Model\Product\Action;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

$storeManager = $objectManager->get(StoreManagerInterface::class);
foreach ($storeManager->getWebsites() as $existing) {
    if ($existing->getCode() === 'second') {
        echo "The second website exists\n";
        exit(0);
    }
}

$website = $objectManager->create(Website::class);
$website->setCode('second')->setName('Second')->save();
$group = $objectManager->create(Group::class);
$group->setWebsiteId($website->getId())->setCode('second')->setName('Second')->setRootCategoryId(2)->save();
$store = $objectManager->create(Store::class);
$store->setCode('second')->setName('Second')->setWebsiteId($website->getId())->setGroupId($group->getId())->setIsActive(1)->save();
$group->setDefaultStoreId($store->getId())->save();
$website->setDefaultGroupId($group->getId())->save();

$connection = $objectManager->get(ResourceConnection::class)->getConnection();
$productIds = $connection->fetchCol($connection->select()->from($connection->getTableName('catalog_product_entity'), 'entity_id'));
$objectManager->get(Action::class)->updateWebsites($productIds, [(int)$website->getId()], 'add');
printf("Created website %d, store view %d, %d products assigned\n", $website->getId(), $store->getId(), count($productIds));
