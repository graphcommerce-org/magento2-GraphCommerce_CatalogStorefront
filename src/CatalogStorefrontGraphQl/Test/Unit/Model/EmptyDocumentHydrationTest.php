<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontGraphQl\Test\Unit\Model;

use GraphCommerce\CatalogStorefront\Model\{Mode,ProductPrice,Strict};
use GraphCommerce\CatalogStorefrontApi\Read\ProductDocumentsInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontGraphQl\Model\DocumentHydration;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchResults;
use PHPUnit\Framework\TestCase;

final class EmptyDocumentHydrationTest extends TestCase
{
    public function testEmptySearchPageKeepsTotalWithoutNativeOrDocumentReads(): void
    {
        $products=$this->createMock(ProductDocumentsInterface::class); $products->expects(self::never())->method('build');
        $storage=$this->createMock(ProductDocumentStorageInterface::class); $storage->expects(self::never())->method('listing');
        $stores=$this->createMock(\Magento\Store\Model\StoreManagerInterface::class); $stores->expects(self::never())->method('getStore');
        $customer=$this->createStub(\Magento\Customer\Model\Session::class);
        $factory=$this->createMock(ProductSearchResultsInterfaceFactory::class); $factory->expects(self::once())->method('create')->willReturn(new \Magento\Catalog\Model\ProductSearchResults());
        $hydration=new DocumentHydration($products,$storage,$this->createStub(Mode::class),$factory,$stores,$customer,new ProductPrice(),$this->createStub(Strict::class));
        $criteria=new SearchCriteria(); $result=$hydration->rebuildFromIds([],25,$criteria,null);
        self::assertSame([],$result->getItems()); self::assertSame(25,$result->getTotalCount()); self::assertSame($criteria,$result->getSearchCriteria());
    }
}
