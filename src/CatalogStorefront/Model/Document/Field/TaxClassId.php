<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Document\Field;

use GraphCommerce\CatalogStorefrontApi\Document\ProductDocumentFieldInterface;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory;

/**
 * The product's tax class id as a field of its own, so a read that leaves the
 * custom attributes slice out still taxes the price. The custom attribute
 * carries the raw id; a feed that carries the class by name maps back to the
 * id through the product classes.
 */
class TaxClassId implements ProductDocumentFieldInterface
{
    /** @var array<string, int>|null */
    private ?array $idsByName = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
    ) {
    }

    public function add(string $storeViewCode, array $documents): array
    {
        foreach ($documents as $id => $document) {
            foreach ((array)($document['customAttributes'] ?? []) as $attribute) {
                if (($attribute['attributeCode'] ?? null) === 'tax_class_id') {
                    $value = (string)($attribute['value'] ?? '');
                    $documents[$id]['taxClassId'] = is_numeric($value) ? (int)$value : ($this->idsByName()[$value] ?? null);
                    break;
                }
            }
        }

        return $documents;
    }

    private function idsByName(): array
    {
        if ($this->idsByName === null) {
            $this->idsByName = [];
            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('class_type', ClassModel::TAX_CLASS_TYPE_PRODUCT);
            foreach ($collection as $class) {
                $this->idsByName[(string)$class->getClassName()] = (int)$class->getId();
            }
        }

        return $this->idsByName;
    }
}
