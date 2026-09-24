<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontPrice\Model\Read;

use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\DataObject;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * The price including or excluding tax of one amount, as core's catalog helper
 * answers it for a product of quantity one without rounding: the same rate
 * request, the same formulas and the same roundings, without the quote
 * details, the tax details and the calculator objects core builds per amount.
 * The rate request of the visitor is built once per store and request; core's
 * calculation keeps the rates per request key.
 */
class TaxPrice
{
    /** @var array<int, DataObject> the rate request per store id */
    private array $requests = [];

    public function __construct(
        private readonly Calculation $calculation,
        private readonly TaxConfig $taxConfig,
        private readonly Session $customerSession,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory,
    ) {
    }

    public function of(float $amount, bool $includingTax, Product $product, StoreInterface $store): float
    {
        if ($amount == 0.0 || !$this->taxConfig->needPriceConversion($store)) {
            return $amount;
        }
        $storeId = (int)$store->getId();
        $request = $this->request($storeId)->setProductClassId($product->getTaxClassId());
        $rate = (float)$this->calculation->getRate($request);
        if ($this->taxConfig->priceIncludesTax($store)) {
            // The aggregate calculators round the unit price first; the unit based one takes it as it is.
            $priceInclTax = $this->taxConfig->getAlgorithm($storeId) === Calculation::CALC_UNIT_BASE
                ? $amount
                : $this->calculation->round($amount);
            $storeRate = (float)$this->calculation->getStoreRate($request, $storeId);
            if (!$this->taxConfig->crossBorderTradeEnabled($storeId) && abs($rate - $storeRate) >= 0.00001) {
                $priceExclTax = $priceInclTax - $this->calculation->calcTaxAmount($priceInclTax, $storeRate, true, false);
                $priceInclTax = $priceExclTax + $this->calculation->calcTaxAmount($priceExclTax, $rate, false, false);
            }

            return $includingTax
                ? $priceInclTax
                : $priceInclTax - $this->calculation->calcTaxAmount($priceInclTax, $rate, true, false);
        }
        $price = $this->calculation->round($amount);
        if (!$includingTax) {
            return $price;
        }
        // Core applies every rate of the request on its own and sums the parts.
        $taxes = [];
        foreach ($this->calculation->getAppliedRates($request) as $applied) {
            $taxes[] = $this->calculation->calcTaxAmount($price, $applied['percent'], false, false);
        }

        return $price + array_sum($taxes);
    }

    /**
     * The rate request the catalog helper builds: the session's default tax
     * addresses, the tax class of the session's customer group and the
     * session's customer id.
     */
    private function request(int $storeId): DataObject
    {
        if (!isset($this->requests[$storeId])) {
            $groupId = $this->customerSession->getCustomerGroupId();
            $this->requests[$storeId] = $this->calculation->getRateRequest(
                $this->address($this->customerSession->getDefaultTaxShippingAddress()),
                $this->address($this->customerSession->getDefaultTaxBillingAddress()),
                $groupId != null ? $this->groupRepository->getById($groupId)->getTaxClassId() : null,
                $storeId,
                $this->customerSession->getCustomerId()
            );
        }

        return $this->requests[$storeId];
    }

    /**
     * @param array{country_id: string, postcode: ?string, region_id?: int|string|null}|null $taxAddress
     */
    private function address(?array $taxAddress): ?AddressInterface
    {
        if (empty($taxAddress)) {
            return null;
        }
        $address = $this->addressFactory->create()
            ->setCountryId($taxAddress['country_id'])
            ->setPostcode($taxAddress['postcode']);
        if (isset($taxAddress['region_id'])) {
            $address->setRegion($this->regionFactory->create()->setRegionId($taxAddress['region_id']));
        }

        return $address;
    }
}
