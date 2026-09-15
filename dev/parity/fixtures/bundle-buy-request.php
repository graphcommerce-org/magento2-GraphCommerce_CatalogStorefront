<?php
/**
 * The buy request that picks one selection of every option of a bundle, for
 * the fixtures that put a bundle in a cart, a wish list or an order. The
 * option and selection ids differ per installation, so they are read from the
 * product.
 */
declare(strict_types=1);

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\DataObject;

return static function (ProductInterface $bundle, float $qty = 1.0): DataObject {
    /** @var BundleType $type */
    $type = $bundle->getTypeInstance();
    $options = $type->getOptionsCollection($bundle);
    $selections = $type->getSelectionsCollection($options->getAllIds(), $bundle);
    $chosen = [];
    $quantities = [];
    foreach ($options as $option) {
        foreach ($selections as $selection) {
            if ((int)$selection->getOptionId() === (int)$option->getId()) {
                $chosen[(int)$option->getId()] = (int)$selection->getSelectionId();
                $quantities[(int)$option->getId()] = 1;
                break;
            }
        }
    }

    return new DataObject(['qty' => $qty, 'bundle_option' => $chosen, 'bundle_option_qty' => $quantities]);
};
