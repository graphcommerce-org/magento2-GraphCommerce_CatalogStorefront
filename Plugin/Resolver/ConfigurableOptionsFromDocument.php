<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProductGraphQl\Model\Resolver\Options;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

/**
 * Serves configurable_options as built at index time by
 * ConfigurableOptionsBuilder. The feed carries no super attribute id or
 * use_default flag, so a selection of those fields keeps the core resolver.
 */
class ConfigurableOptionsFromDocument
{

    public function aroundResolve(
        Options $subject,
        \Closure $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
        if (!is_array($document)
            || ($value['type_id'] ?? null) !== Configurable::TYPE_CODE
            || !isset($document['configurableOptions'])
        ) {
            return $proceed($field, $context, $info, $value, $args);
        }

        return $document['configurableOptions'];
    }
}
