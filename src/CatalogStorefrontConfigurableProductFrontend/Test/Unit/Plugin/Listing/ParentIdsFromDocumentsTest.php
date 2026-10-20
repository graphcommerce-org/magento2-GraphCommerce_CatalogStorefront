<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Test\Unit\Plugin\Listing;

use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Model\Read\VariantDocuments;
use GraphCommerce\CatalogStorefrontConfigurableProductFrontend\Plugin\Listing\ParentIdsFromDocuments;
use GraphCommerce\CatalogStorefrontProductFrontend\Model\Read\ListingDocuments;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ParentIdsFromDocumentsTest extends TestCase
{
    private int $coreCalls = 0;

    /** @var int[][] the child ids each variant read asked for */
    private array $variantReads = [];

    /**
     * @param array<int, array>|null $variants what the variant read answers
     */
    private function plugin(ListingDocuments $page, ?array $variants = null): ParentIdsFromDocuments
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $variantDocuments = $this->createMock(VariantDocuments::class);
        $variantDocuments->method('documents')->willReturnCallback(
            function ($store, array $childIds) use ($variants): ?array {
                $this->variantReads[] = $childIds;

                return $variants === null ? null : array_intersect_key($variants, array_flip($childIds));
            }
        );

        return new ParentIdsFromDocuments($page, $variantDocuments, $storeManager);
    }

    private function page(array $documents, string $storeViewCode = 'default'): ListingDocuments
    {
        $page = new ListingDocuments();
        $page->add($storeViewCode, $documents);

        return $page;
    }

    private function call(ParentIdsFromDocuments $plugin, $childId): array
    {
        return $plugin->aroundGetParentIdsByChild(
            $this->createMock(Configurable::class),
            function () {
                $this->coreCalls++;

                return ['core'];
            },
            $childId
        );
    }

    public function testCardAnswersFromItsDocument(): void
    {
        $plugin = $this->plugin($this->page([10 => ['parentIds' => [7, 8]]]));

        $this->assertSame(['7', '8'], $this->call($plugin, '10'));
        $this->assertSame([], $this->variantReads);
        $this->assertSame(0, $this->coreCalls);
    }

    public function testCardWithoutParentsAnswersEmpty(): void
    {
        $plugin = $this->plugin($this->page([10 => ['sku' => 'simple']]));

        $this->assertSame([], $this->call($plugin, 10));
        $this->assertSame(0, $this->coreCalls);
    }

    public function testVariantOfACardAnswersFromTheVariantDocuments(): void
    {
        $plugin = $this->plugin($this->page([7 => ['variantIds' => [21]]]), [21 => ['parentIds' => [7]]]);

        $this->assertSame(['7'], $this->call($plugin, 21));
        $this->assertSame([[21]], $this->variantReads);
        $this->assertSame(0, $this->coreCalls);
    }

    public function testSeveralProductsMergeTheirParentsOnce(): void
    {
        $plugin = $this->plugin(
            $this->page([10 => ['parentIds' => [7]]]),
            [21 => ['parentIds' => [7, 9]]]
        );

        $this->assertSame(['7', '9'], $this->call($plugin, [10, 21]));
        $this->assertSame([[21]], $this->variantReads);
    }

    public function testProductWithoutADocumentGoesToCore(): void
    {
        $plugin = $this->plugin($this->page([10 => ['parentIds' => [7]]]), [21 => ['parentIds' => [7]]]);

        $this->assertSame(['core'], $this->call($plugin, [10, 22]));
        $this->assertSame(1, $this->coreCalls);
    }

    public function testVariantReadThatCoversNothingGoesToCore(): void
    {
        $plugin = $this->plugin($this->page([7 => ['variantIds' => [21]]]));

        $this->assertSame(['core'], $this->call($plugin, 21));
        $this->assertSame(1, $this->coreCalls);
    }

    public function testRequestWithoutListingDocumentsGoesToCore(): void
    {
        $this->assertSame(['core'], $this->call($this->plugin(new ListingDocuments()), 10));
        $this->assertSame(['core'], $this->call($this->plugin($this->page([10 => []], 'other')), 10));
        $this->assertSame(2, $this->coreCalls);
        $this->assertSame([], $this->variantReads);
    }
}
