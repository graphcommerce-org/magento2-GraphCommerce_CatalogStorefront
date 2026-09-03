<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use Magento\EavGraphQl\Model\TypeResolver\AttributeValue;

/**
 * Answers the attribute value type for an item the document path built,
 * which names its type; core loads the attribute to decide.
 */
class AttributeValueTypeFromDocument
{
    public const KEY = '_gc_type';

    public function aroundResolveType(AttributeValue $subject, \Closure $proceed, array $data): string
    {
        return $data[self::KEY] ?? $proceed($data);
    }
}
