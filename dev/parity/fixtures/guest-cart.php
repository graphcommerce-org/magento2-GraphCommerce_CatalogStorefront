<?php
/**
 * A guest cart with a simple and a bundle item under a fixed masked id, so
 * the cart queries of the gate name the same cart on every run. Run from the
 * Magento root. Prints the masked id and leaves an existing cart untouched.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;

const MASKED_ID = 'paritygateguestcart000000000000a';

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('frontend');

$connection = $objectManager->get(ResourceConnection::class)->getConnection();
$maskTable = $connection->getTableName('quote_id_mask');
$quoteId = $connection->fetchOne(
    $connection->select()->from($maskTable, 'quote_id')->where('masked_id = ?', MASKED_ID)
);
if ($quoteId && $connection->fetchOne(
    $connection->select()->from($connection->getTableName('quote'), 'is_active')->where('entity_id = ?', $quoteId)
)) {
    echo MASKED_ID, "\n";
    exit(0);
}

$store = $objectManager->get(StoreManagerInterface::class)->getStore();
$products = $objectManager->get(ProductRepositoryInterface::class);
$quote = $objectManager->get(QuoteFactory::class)->create();
$quote->setStoreId($store->getId())->setIsActive(true)->setIsMultiShipping(false);
$bundle = $products->get('24-WG080');
$quote->addProduct($products->get('24-MB01'), new DataObject(['qty' => 2]));
$quote->addProduct($bundle, (require __DIR__ . '/bundle-buy-request.php')($bundle));
$quote->collectTotals();
$objectManager->get(CartRepositoryInterface::class)->save($quote);

$connection->delete($maskTable, ['masked_id = ?' => MASKED_ID]);
$connection->insert($maskTable, ['quote_id' => (int)$quote->getId(), 'masked_id' => MASKED_ID]);
printf("%s\n", MASKED_ID);
