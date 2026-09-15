<?php
/**
 * A simple and a bundle product on the wish list of the gate's customer, so
 * the wish list query has items to compare. Run from the Magento root with
 * the customer email as the first argument. Adds nothing when the wish list
 * already holds the products.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\WishlistFactory;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');

$customer = $objectManager->get(CustomerRepositoryInterface::class)->get($argv[1] ?? 'customer@example.com');
$products = $objectManager->get(ProductRepositoryInterface::class);
$storeId = (int)$objectManager->get(StoreManagerInterface::class)->getStore()->getId();

$wishlist = $objectManager->get(WishlistFactory::class)->create();
$wishlist->loadByCustomerId((int)$customer->getId(), true);
$wanted = ['24-MB01', '24-WG080'];
foreach ($wishlist->getItemCollection() as $item) {
    $wanted = array_diff($wanted, [$item->getProduct()->getSku()]);
}
$bundleRequest = require __DIR__ . '/bundle-buy-request.php';
foreach ($wanted as $sku) {
    $product = $products->get($sku, false, $storeId);
    $wishlist->addNewItem(
        $product,
        $product->getTypeId() === 'bundle' ? $bundleRequest($product) : new DataObject(['qty' => 1])
    );
}
$wishlist->save();
printf("Wish list %d of customer %d holds %d items\n", $wishlist->getId(), $customer->getId(), count($wishlist->getItemCollection()));
