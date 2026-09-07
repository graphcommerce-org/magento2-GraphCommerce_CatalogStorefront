<?php
/**
 * A custom category attribute, `seo_text`, with a value on the Women category
 * (id 20): the categoryList queries then select a field GraphQL adds for a
 * category attribute. Run from the Magento root, then reindex the categories
 * feed and flush the cache (the GraphQL schema changes). Does nothing when the
 * attribute exists.
 */
declare(strict_types=1);

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Setup\CategorySetup;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode('adminhtml');

if ($objectManager->get(EavConfig::class)->getAttribute(Category::ENTITY, 'seo_text')->getId()) {
    echo "The seo_text attribute exists\n";
    exit(0);
}
$objectManager->get(CategorySetup::class)->addAttribute(Category::ENTITY, 'seo_text', [
    'type' => 'text',
    'label' => 'SEO text',
    'input' => 'textarea',
    'required' => false,
    'user_defined' => true,
    'global' => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
    'group' => 'General Information',
]);
$objectManager->get(EavConfig::class)->clear();
$categories = $objectManager->get(CategoryRepositoryInterface::class);
$category = $categories->get(20, 0);
$category->setCustomAttribute('seo_text', 'Women\'s fashion for every season');
$categories->save($category);
echo "seo_text on category 20\n";
