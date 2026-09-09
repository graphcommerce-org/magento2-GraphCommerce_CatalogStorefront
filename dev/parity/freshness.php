<?php
/**
 * The freshness gate. Run from the Magento root with the feed indexers on
 * schedule; exits 1 when a document is stale.
 *
 * A price and a fixed product tax change on 24-MB01 reach the product document
 * through the scheduled feed views, updated as the indexer cron does. Then
 * with out of stock products hidden, 24-WG02 goes out of stock and the
 * categories feed is rebuilt: its category's count drops. The display setting
 * then flips through the config model the admin saves with: the products and
 * the categories feed indexers turn invalid, the invalid ones are rebuilt as
 * the indexer cron does, and the count holds the product again. The stock and
 * the setting are restored at the end.
 */
declare(strict_types=1);

use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Config\Model\Config;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Mview\ProcessorInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(FrontNameResolver::AREA_CODE);
$stale = [];

$products = $objectManager->get(ProductRepositoryInterface::class);
$product = $products->get('24-MB01');
$price = round((float)$product->getPrice() + 1, 2);
$product->setPrice($price);
$product->setData('eco_tax', [['website_id' => 0, 'country' => 'US', 'state' => 0, 'value' => $price / 10]]);
$products->save($product);

$objectManager->get(ProcessorInterface::class)->update();
sleep(2);

$document = $objectManager->get(ProductDocumentStorageInterface::class)
    ->get('default', [(int)$product->getId()], ['prices', 'fixedProductTaxes'])[(int)$product->getId()] ?? [];
$regular = null;
foreach ((array)($document['prices'] ?? []) as $row) {
    if (($row['group'] ?? null) === 'all') {
        $regular = (float)$row['regular'];
    }
}
$taxes = array_column((array)($document['fixedProductTaxes'] ?? []), 'value');
printf("price %s -> document %s, fixed product tax %s -> document %s\n", $price, var_export($regular, true), $price / 10, implode(',', $taxes));
if ($regular !== $price || $taxes !== [$price / 10]) {
    $stale[] = 'product';
}

$indexers = $objectManager->get(IndexerRegistry::class);
$metadata = $objectManager->get(MetadataDocumentStorageInterface::class);
$stockRegistry = $objectManager->get(StockRegistryInterface::class);
$categoryId = (int)current($products->get('24-WG02')->getCategoryIds());
$count = static fn(): int => (int)($metadata->get('category', 'default', [$categoryId], ['productCount'])[$categoryId]['productCount'] ?? -1);
$stock = static function (float $qty, bool $inStock) use ($stockRegistry): void {
    $item = $stockRegistry->getStockItemBySku('24-WG02');
    $item->setQty($qty);
    $item->setIsInStock($inStock);
    $stockRegistry->updateStockItemBySku('24-WG02', $item);
};
$display = static function (string $value) use ($objectManager, $indexers): array {
    $config = $objectManager->create(Config::class, ['data' => ['scope' => 'default', 'scope_code' => null]]);
    $config->setDataByPath('cataloginventory/options/show_out_of_stock', $value);
    $config->save();
    $rebuilt = [];
    foreach (['catalog_data_exporter_products', 'catalog_data_exporter_categories'] as $id) {
        if ($indexers->get($id)->isInvalid()) {
            $indexers->get($id)->reindexAll();
            $rebuilt[] = $id;
        }
    }
    return $rebuilt;
};

$setting = (string)(int)$objectManager->get(ScopeConfigInterface::class)->isSetFlag('cataloginventory/options/show_out_of_stock');
$display('0');
$before = $count();
$stock(0, false);
$indexers->get('catalog_data_exporter_categories')->reindexAll();
$hidden = $count();
$rebuilt = $display('1');
$shown = $count();
printf("category %d count %d -> %d with 24-WG02 out of stock -> %d shown; the setting rebuilt %s\n", $categoryId, $before, $hidden, $shown, implode(',', $rebuilt) ?: 'nothing');
if ($hidden !== $before - 1 || $shown !== $before || count($rebuilt) !== 2) {
    $stale[] = 'category';
}

$display($setting);
$stock(100, true);
$indexers->get('catalog_data_exporter_categories')->reindexAll();

echo $stale ? 'stale: ' . implode(', ', $stale) . "\n" : "fresh\n";
exit($stale ? 1 : 0);
