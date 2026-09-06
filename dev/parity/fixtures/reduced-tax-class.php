<?php
/**
 * A product tax class "Reduced Goods", taxed at 2% in Michigan for retail
 * customers, on one variant of the configurable WJ02: the including-tax gates
 * then see a composite whose children carry different tax classes. Run from
 * the Magento root, then reindex. Does nothing when the class exists.
 */
declare(strict_types=1);

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Tax\Api\Data\TaxClassInterfaceFactory;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\ClassModel;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

$criteria = $objectManager->get(SearchCriteriaBuilder::class);
$classes = $objectManager->get(TaxClassRepositoryInterface::class);
if ($classes->getList($criteria->addFilter('class_name', 'Reduced Goods')->create())->getTotalCount() > 0) {
    echo "The reduced tax class exists\n";
    exit(0);
}
$class = $objectManager->get(TaxClassInterfaceFactory::class)->create();
$class->setClassName('Reduced Goods')->setClassType(ClassModel::TAX_CLASS_TYPE_PRODUCT);
$classId = (int)$classes->save($class);

$rate = $objectManager->get(TaxRateInterfaceFactory::class)->create();
$rate->setCode('US-MI-*-Reduced')->setTaxCountryId('US')->setTaxRegionId(33)->setTaxPostcode('*')->setRate(2.0);
$rate = $objectManager->get(TaxRateRepositoryInterface::class)->save($rate);

$rule = $objectManager->get(TaxRuleInterfaceFactory::class)->create();
$rule->setCode('Reduced')->setCustomerTaxClassIds([3])->setProductTaxClassIds([$classId])
    ->setTaxRateIds([(int)$rate->getId()])->setPriority(0)->setPosition(0);
$objectManager->get(TaxRuleRepositoryInterface::class)->save($rule);

$products = $objectManager->get(ProductRepositoryInterface::class);
$variant = $products->get('WJ02-XS-Blue');
$variant->setTaxClassId($classId);
$products->save($variant);
printf("Tax class %d on WJ02-XS-Blue, rate %s\n", $classId, $rate->getCode());
