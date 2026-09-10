<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontConfigurableProduct\Model\Read\ConfigurableOptions;
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable\AttributeFactory;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * Builds a configurable's super attributes from the document.
 *
 * Core loads an attribute collection, then its labels, then runs one more query per super
 * attribute to fetch the options. On a listing page that happens for every card — and before the
 * card's block cache is even consulted, because the swatch block's cache key is built from these
 * attributes.
 *
 * configurableOptions expanded from the document carries everything the read path
 * asks of these models: the attribute id, position, label, and the option list with value_index
 * and label. The only field it lacks, product_super_attribute_id, is a join key used inside the
 * resource layer and read by nothing on this path.
 *
 * The EAV attribute behind each one comes from EavConfig by code, which memoises, so repeat cards
 * cost nothing.
 */
class ConfigurableAttributesFromDocument
{
    /** Core's own memo key, kept in step for anything reading the product's data directly. */
    private const CACHE_KEY = '_cache_instance_configurable_attributes';

    public function __construct(
        private readonly AttributeFactory $attributeFactory,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly ConfigurableOptions $configurableOptions,
    ) {
    }

    /**
     * @param Configurable $subject
     * @param \Closure $proceed
     * @param Product $product
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundGetConfigurableAttributes(Configurable $subject, \Closure $proceed, $product)
    {
        if ($product->hasData(self::CACHE_KEY)) {
            return $product->getData(self::CACHE_KEY);
        }

        $document = $product->getData(ProductDocumentsInterface::DOCUMENT_KEY);
        $options = is_array($document) ? $this->configurableOptions->attributes($document) : null;
        if ($options === null) {
            return $proceed($product);
        }

        try {
            $attributes = $this->build($options);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'catalog-storefront configurable attribute fallback: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $proceed($product);
        }

        if ($attributes === null) {
            return $proceed($product);
        }

        $product->setData(self::CACHE_KEY, $attributes);

        return $attributes;
    }

    /**
     * @return \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Attribute[]|null
     *         null when an attribute cannot be resolved, so the caller falls back whole
     */
    private function build(array $options): ?array
    {
        $attributes = [];

        foreach ($options as $option) {
            $code = (string)($option['attribute_code'] ?? '');
            if ($code === '') {
                return null;
            }

            $productAttribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            if (!$productAttribute || !$productAttribute->getId()) {
                return null;
            }

            $attribute = $this->attributeFactory->create();
            $attribute->setData([
                'attribute_id' => $option['attribute_id'] ?? $productAttribute->getId(),
                // String, not int: the database returns these as strings and getJsonConfig()
                // passes the value straight into JSON, so an int changes the rendered output.
                'position' => (string)($option['position'] ?? 0),
                'label' => $option['label'] ?? $productAttribute->getStoreLabel(),
                'options' => array_values((array)($option['values'] ?? [])),
            ]);
            $attribute->setProductAttribute($productAttribute);

            $attributes[] = $attribute;
        }

        return $attributes;
    }
}
