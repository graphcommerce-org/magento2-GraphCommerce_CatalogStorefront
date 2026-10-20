<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontCategoryFrontend\Plugin;

use GraphCommerce\CatalogStorefrontCategoryFrontend\Model\Read\CategoryDocuments;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Filter\Price\Range;
use Magento\Framework\Registry;

/**
 * Answers the price step of the layered navigation from the document of the filtered category, which
 * the page built the category from. It runs before the base module's plugin, which reads the category
 * document from the store again. A category that the page did not build from a document goes on to
 * that plugin.
 */
class PriceStepFromCategory
{
    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetPriceRange(Range $subject, \Closure $proceed)
    {
        $category = $this->registry->registry('current_category_filter');
        $document = $category instanceof Category ? $category->getData(CategoryDocuments::DOCUMENT_KEY) : null;
        if (!is_array($document) || !array_key_exists('filterPriceRange', $document)) {
            return $proceed();
        }

        return $document['filterPriceRange'];
    }
}
