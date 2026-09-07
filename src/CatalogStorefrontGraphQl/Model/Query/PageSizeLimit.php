<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Query;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\Resolver\Argument\ValidatorInterface;

/**
 * A page of at most MAX items, on both paths. A full document decodes to
 * about 65 KB: 10 000 of them hold 650 MB and 50 000 exhausted a 2 GB worker.
 * Core's own page size limit is not wired for GraphQL in Mage-OS, so this
 * validator sits in the composite validator every resolver argument passes.
 */
class PageSizeLimit implements ValidatorInterface
{
    public const MAX = 2000;

    public function validate(Field $field, $args): void
    {
        if (isset($args['pageSize']) && (int)$args['pageSize'] > self::MAX) {
            throw new GraphQlInputException(__('Maximum pageSize is %1', self::MAX));
        }
    }
}
