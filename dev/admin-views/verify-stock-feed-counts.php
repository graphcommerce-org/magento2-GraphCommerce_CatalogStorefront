<?php
declare(strict_types=1);

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\SourceFeedCounts;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

// Local fixture probe: feed mutations stay in one transaction and are always rolled back.
$root = getenv('MAGENTO_ROOT');
if (!$root || !is_file($root . '/app/bootstrap.php')) throw new RuntimeException('Set MAGENTO_ROOT.');
require $root . '/app/bootstrap.php';
$o = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$r = $o->get(ResourceConnection::class); $db = $r->getConnection(); $feeds = $o->get(Feeds::class);
$read = fn() => (new SourceFeedCounts($r, $feeds))->stocks();
$table = $r->getTableName($feeds->byEntity()['product']['inventory_data_exporter_stock_status']->getFeedTableName());
$scope = $argv[1] ?? '1'; $other = '2147483646';
$rows = $db->fetchAll($db->select()->from($table, ['id', 'feed_data'])->where("JSON_UNQUOTE(JSON_EXTRACT(feed_data, '$.stockId')) = ?", $scope)->where('status = 200 AND is_deleted = 0')->limit(2));
if (count($rows) !== 2) throw new RuntimeException('Requires two accepted stock feed records.');
$before = $read();
if (isset($before[$other])) throw new RuntimeException('Probe scope already exists.');
$imported = (int)str_replace(',', '', $before[$scope]['value']);
if ($before[$scope]['badges'] !== []) throw new RuntimeException('Requires a clean fixture scope.');
$sourceBefore = (new SourceFeedCounts($r, $feeds))->get();
$db->beginTransaction();
try {
    $payload = json_decode($rows[0]['feed_data'], true, 512, JSON_THROW_ON_ERROR);
    $payload['stockId'] = (int)$other;
    $db->update($table, ['feed_data' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 0, 'errors' => ''], ['id = ?' => $rows[0]['id']]);
    $db->update($table, ['status' => 500, 'errors' => '["Rollback-only stock receipt probe"]', 'is_deleted' => 1], ['id = ?' => $rows[1]['id']]);
    $during = $read();
    if ($during[$scope] !== SourceFeedCounts::cell($imported - 2, 0, 1)) throw new RuntimeException('Original Stock receipt count mismatch.');
    if ($during[$other] !== SourceFeedCounts::cell(0, 1, 0)) throw new RuntimeException('Pending receipt leaked across Stock IDs.');
    if ((new SourceFeedCounts($r, $feeds))->get() !== $sourceBefore) throw new RuntimeException('Stock rows changed Catalog Source totals.');
    $db->update($table, ['status' => 200, 'errors' => ''], ['id = ?' => $rows[1]['id']]);
    if ($read()[$scope] !== SourceFeedCounts::cell($imported - 2, 0, 0)) throw new RuntimeException('Accepted delete still counted or failed.');
} finally {
    $db->rollBack();
}
if ($read() !== $before) throw new RuntimeException('Rollback did not restore Stock receipts.');
echo json_encode(['stock' => $scope, 'before' => $before[$scope], 'during' => $during[$scope], 'isolatedPending' => $during[$other], 'catalogSourcesUnchanged' => true, 'rollbackVerified' => true], JSON_PRETTY_PRINT), "\n";
