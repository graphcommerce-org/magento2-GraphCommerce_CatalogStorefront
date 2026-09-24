<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\LoadedAttributeCollectionFactory;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Mode;
use Magento\Catalog\Model\Layer\FilterableAttributeListInterface;
use Magento\Catalog\Model\Layer\Search\FilterableAttributeList as SearchList;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The filterable attributes of the layered navigation from the attribute documents: the
 * category layer lists the attributes with a filterable mode, the search layer the ones
 * filterable in search and visible, both by position. The attribute models come from the
 * EAV config, with the store label of the document. A layer that lists no attribute is an
 * empty list. A store view without attribute documents, or an attribute the EAV config does
 * not know, takes core's select.
 */
class FilterableAttributesFromDocuments implements ResetAfterRequestInterface
{
    private const ENTITY = 'attribute';

    /** @var array<string, array<int|string, array>> the attribute documents read per store view code */
    private array $documents = [];

    public function __construct(
        private readonly MetadataDocumentStorageInterface $storage,
        private readonly EavConfig $eavConfig,
        private readonly Mode $mode,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoadedAttributeCollectionFactory $collectionFactory,
    ) {
    }

    /**
     * @return mixed
     */
    public function aroundGetList(FilterableAttributeListInterface $subject, \Closure $proceed)
    {
        $store = $this->storeManager->getStore();
        if (!$this->mode->listing((int)$store->getId())) {
            return $proceed();
        }
        $storeViewCode = (string)$store->getCode();
        $this->documents[$storeViewCode] ??= $this->storage->all(self::ENTITY, $storeViewCode);
        if ($this->documents[$storeViewCode] === []) {
            return $proceed();
        }
        $search = $subject instanceof SearchList;
        $listed = array_filter(
            $this->documents[$storeViewCode],
            static fn (array $document) => ($document['attributeType'] ?? Product::ENTITY) === Product::ENTITY
                && ($search
                    ? !empty($document['filterableInSearch']) && !empty($document['visible'])
                    : (int)($document['filterableMode'] ?? 0) > 0)
        );
        usort($listed, static fn (array $a, array $b) =>
            [(int)($a['position'] ?? 0), (int)($a['attributeId'] ?? 0)] <=> [(int)($b['position'] ?? 0), (int)($b['attributeId'] ?? 0)]);

        $attributes = [];
        foreach ($listed as $document) {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, (string)($document['attributeCode'] ?? ''));
            if (!$attribute->getId()) {
                return $proceed();
            }
            $attribute->setData('store_label', $document['label'] ?? $attribute->getDefaultFrontendLabel());
            $attributes[] = $attribute;
        }

        return $this->collectionFactory->create()->withItems($attributes);
    }

    public function _resetState(): void
    {
        $this->documents = [];
    }
}
