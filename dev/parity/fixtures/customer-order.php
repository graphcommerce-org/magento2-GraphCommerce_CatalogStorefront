<?php
/**
 * One placed order with a simple and a bundle item for the gate's customer,
 * so the order query has items to compare. Run from the Magento root with
 * the customer email as the first argument. Places nothing when the customer
 * already has an order.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');

$customer = $objectManager->get(CustomerRepositoryInterface::class)->get($argv[1] ?? 'customer@example.com');
$orders = $objectManager->get(OrderRepositoryInterface::class)->getList(
    $objectManager->get(SearchCriteriaBuilder::class)->addFilter('customer_id', $customer->getId())->create()
);
if ($orders->getTotalCount() > 0) {
    printf("Customer %d has order %s\n", $customer->getId(), current($orders->getItems())->getIncrementId());
    exit(0);
}

$store = $objectManager->get(StoreManagerInterface::class)->getStore();
$products = $objectManager->get(ProductRepositoryInterface::class);
$address = $objectManager->get(AddressRepositoryInterface::class)->getById((int)$customer->getDefaultShipping());

$quote = $objectManager->get(QuoteFactory::class)->create();
$quote->setStoreId($store->getId())->setIsActive(true)->setIsMultiShipping(false);
$quote->assignCustomer($customer);
$bundle = $products->get('24-WG080');
$quote->addProduct($products->get('24-MB01'), new DataObject(['qty' => 2]));
$quote->addProduct($bundle, (require __DIR__ . '/bundle-buy-request.php')($bundle));
$quote->getBillingAddress()->importCustomerAddressData($address);
$quote->getShippingAddress()->importCustomerAddressData($address)
    ->setCollectShippingRates(true)
    ->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');
$quote->getPayment()->setQuote($quote)->importData(['method' => 'checkmo']);
$quote->collectTotals();
$objectManager->get(CartRepositoryInterface::class)->save($quote);

$orderId = $objectManager->get(CartManagementInterface::class)->placeOrder((int)$quote->getId());
printf("Placed order %s for customer %d\n", $objectManager->get(OrderRepositoryInterface::class)->get($orderId)->getIncrementId(), $customer->getId());
