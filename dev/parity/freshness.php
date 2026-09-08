<?php
/**
 * The freshness gate: a price and a fixed product tax change on 24-MB01,
 * the scheduled feed views updated the way the indexer cron updates them,
 * then the document must carry both. Run from the Magento root with the
 * feed indexers on schedule. Exits 1 when the document is stale.
 */
declare(strict_types=1);

use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Mview\ProcessorInterface;

require getcwd() . '/app/bootstrap.php';
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(FrontNameResolver::AREA_CODE);

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
exit($regular === $price && $taxes === [$price / 10] ? 0 : 1);
