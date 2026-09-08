<?php
/**
 * A fixed product tax attribute "Eco tax" on every attribute set, with a
 * US amount for all regions and a Michigan amount for website 1 on two
 * simple products and one variant of WJ02: a destination in Michigan sees
 * both rows, as core's weee query lists them, and a composite falls back.
 * Run from the Magento root, then reindex. Does nothing when the attribute
 * exists.
 */
declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\NoSuchEntityException;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

$attributes = $objectManager->get(ProductAttributeRepositoryInterface::class);
try {
    $attributes->get('eco_tax');
    echo "The eco_tax attribute exists\n";
    exit(0);
} catch (NoSuchEntityException) {
}

$attribute = $objectManager->get(ProductAttributeInterfaceFactory::class)->create();
$attribute->setAttributeCode('eco_tax')
    ->setFrontendInput('weee')
    ->setDefaultFrontendLabel('Eco tax')
    ->setIsUserDefined(true)
    ->setIsGlobal(1)
    ->setIsRequired(false);
$attribute = $attributes->save($attribute);

$criteria = $objectManager->get(SearchCriteriaBuilder::class)->addFilter('entity_type_id', 4)->create();
$management = $objectManager->get(ProductAttributeManagementInterface::class);
foreach ($objectManager->get(AttributeSetRepositoryInterface::class)->getList($criteria)->getItems() as $set) {
    $management->assign((int)$set->getAttributeSetId(), (int)$set->getDefaultGroupId(), 'eco_tax', 100);
}

$products = $objectManager->get(ProductRepositoryInterface::class);
foreach (['24-WG080', '24-MB01', 'WJ02-XS-Blue'] as $sku) {
    $product = $products->get($sku);
    $product->setData('eco_tax', [
        ['website_id' => 0, 'country' => 'US', 'state' => 0, 'value' => 5.0],
        ['website_id' => 1, 'country' => 'US', 'state' => 33, 'value' => 7.5],
    ]);
    $products->save($product);
}
printf("Attribute %d eco_tax on 24-WG080, 24-MB01 and WJ02-XS-Blue\n", $attribute->getAttributeId());
