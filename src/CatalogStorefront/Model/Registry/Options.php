<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\Registry;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Framework\Locale\ConfigInterface as CurrencyConfig;

class Options
{
    public function __construct(
        private readonly StoreManagerInterface $stores,
        private readonly GroupManagementInterface $groups,
        private readonly CurrencyConfig $currencies
    ) {
    }
    public function get(string $name): array
    {
        $result = [];
        if ($name === 'native_stores') {
            foreach ($this->stores->getStores() as $store) {
                $result[(int)$store->getId()] = $store->getName() . ' (' . $store->getCode() . ')';
            }
        } elseif ($name === 'native_websites') {
            foreach ($this->stores->getWebsites() as $website) {
                $result[(int)$website->getId()] = $website->getName() . ' (' . $website->getCode() . ')';
            }
        } elseif ($name === 'native_website_currencies') {
            foreach ($this->stores->getWebsites() as $website) $result[(int)$website->getId()] = (string)$website->getBaseCurrencyCode();
        } elseif ($name === 'native_groups') {
            foreach (array_merge([$this->groups->getNotLoggedInGroup()], $this->groups->getLoggedInGroups()) as $group) {
                $result[(int)$group->getId()] = $group->getCode();
            }
        } elseif ($name === 'currencies') {
            foreach ($this->currencies->getAllowedCurrencies() as $code) {
                $result[$code] = $code;
            }
        }
        return $result;
    }
}
