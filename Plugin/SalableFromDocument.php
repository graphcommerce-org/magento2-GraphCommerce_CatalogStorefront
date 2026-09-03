<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type\AbstractType;

/**
 * Answers the salability check of composite product types from the document.
 *
 * The core checks load the child products of every configurable or bundle to
 * find a salable one. The inventory feed already exports the parent's
 * salability, derived from its children by the stock index, so a document
 * served product answers from that instead.
 */
class SalableFromDocument
{
    public function aroundIsSalable(AbstractType $subject, \Closure $proceed, Product $product): bool
    {
        $document = $product->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document)) {
            return (bool)$proceed($product);
        }

        return (int)$product->getStatus() === Status::STATUS_ENABLED
            && (bool)($document['stock']['isSalable'] ?? $document['inStock'] ?? false);
    }
}
