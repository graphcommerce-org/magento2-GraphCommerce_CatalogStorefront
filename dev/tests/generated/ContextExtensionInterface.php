<?php
/**
 * The extension interface Magento generates for the GraphQL context, for
 * the unit tests of an installation without generated code.
 */
declare(strict_types=1);

namespace Magento\GraphQl\Model\Query;

interface ContextExtensionInterface extends \Magento\Framework\Api\ExtensionAttributesInterface
{
    /** @return \Magento\Store\Api\Data\StoreInterface|null */
    public function getStore();

    /** @param \Magento\Store\Api\Data\StoreInterface $store */
    public function setStore(\Magento\Store\Api\Data\StoreInterface $store);
}
