<?php
/**
 * A manual price step of 50 on category 23, the category of the aggregations
 * query: with manual price navigation the price buckets follow it on both
 * paths. Run from the Magento root, then reindex the categories feed.
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

$categories = $objectManager->get(CategoryRepositoryInterface::class);
$category = $categories->get(23, 0);
$category->setData('filter_price_range', 50);
$categories->save($category);
echo "price step 50 on category 23\n";
