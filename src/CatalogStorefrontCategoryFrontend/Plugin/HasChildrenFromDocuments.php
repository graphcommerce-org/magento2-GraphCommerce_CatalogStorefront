<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use Magento\Catalog\Model\Category;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Whether a category built from a document has an active descendant, read level by level
 * from the documents. Core counts every active category under the path.
 */
class HasChildrenFromDocuments
{
    public function __construct(
        private readonly CategoryDocuments $documents,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function aroundHasChildren(Category $subject, \Closure $proceed): bool
    {
        $document = $subject->getData(CategoryDocuments::DOCUMENT_KEY);
        if (!is_array($document)) {
            return (bool)$proceed();
        }
        $storeViewCode = (string)$this->storeManager->getStore((int)$subject->getStoreId())->getCode();
        $ids = array_map('intval', (array)($document['children'] ?? []));
        while ($ids !== []) {
            $level = $this->documents->documents($storeViewCode, $ids);
            $ids = [];
            foreach ($level as $child) {
                if (!empty($child['isActive'])) {
                    return true;
                }
                $ids = array_merge($ids, array_map('intval', (array)($child['children'] ?? [])));
            }
        }

        return false;
    }
}
