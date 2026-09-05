<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPriceGraphQl\Plugin\Tax;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config;

/**
 * A tax rate request for a customer without explicit addresses loads the
 * whole customer twice in core: once per default address, once more for the
 * group's tax class. The address the tax base names is one join read of its
 * three tax columns, held for the request because every taxed amount builds
 * its own rate request, and the tax class follows the session's group.
 */
class CustomerAddressColumns implements ResetAfterRequestInterface
{
    /** @var array<string, DataObject|null> */
    private array $addresses = [];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Session $session,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    public function aroundGetRateRequest(
        Calculation $subject,
        \Closure $proceed,
        $shippingAddress = null,
        $billingAddress = null,
        $customerTaxClass = null,
        $store = null,
        $customerId = null
    ) {
        if (!$customerId || $shippingAddress !== null || $billingAddress !== null) {
            return $proceed($shippingAddress, $billingAddress, $customerTaxClass, $store, $customerId);
        }
        $basedOn = $this->scopeConfig->getValue(Config::CONFIG_XML_PATH_BASED_ON, ScopeInterface::SCOPE_STORE, $store);
        $address = null;
        if ($basedOn === 'shipping' || $basedOn === 'billing') {
            $key = $customerId . ':' . $basedOn;
            if (!array_key_exists($key, $this->addresses)) {
                $connection = $this->resource->getConnection();
                $row = $connection->fetchRow($connection->select()
                    ->from(['c' => $this->resource->getTableName('customer_entity')], [])
                    ->join(
                        ['a' => $this->resource->getTableName('customer_address_entity')],
                        'a.entity_id = c.default_' . $basedOn,
                        ['country_id', 'region_id', 'postcode']
                    )
                    ->where('c.entity_id = ?', (int)$customerId));
                $this->addresses[$key] = $row && $row['country_id'] ? new DataObject($row) : null;
            }
            $address = $this->addresses[$key];
        }
        if ($customerTaxClass === null || $customerTaxClass === false) {
            $customerTaxClass = $this->groupRepository->getById((int)$this->session->getCustomerGroupId())->getTaxClassId();
        }

        return $proceed(
            $basedOn === 'shipping' ? $address : null,
            $basedOn === 'billing' ? $address : null,
            $customerTaxClass,
            $store,
            null
        );
    }

    public function _resetState(): void
    {
        $this->addresses = [];
    }
}
