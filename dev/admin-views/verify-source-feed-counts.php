<?php
declare(strict_types=1);

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontAdminhtml\Model\SourceFeedCounts;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

// Local fixture only: all mutations remain in this connection's transaction and are rolled back.
$root = getenv('MAGENTO_ROOT');
if (!$root || !is_file($root . '/app/bootstrap.php')) throw new RuntimeException('Set MAGENTO_ROOT to the local development checkout.');
require $root . '/app/bootstrap.php';
$o = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$r = $o->get(ResourceConnection::class);$db = $r->getConnection();$feeds = $o->get(Feeds::class);
$read = fn() => (new SourceFeedCounts($r, $feeds))->get();
$table = $r->getTableName($feeds->byEntity()['product']['catalog_data_exporter_products']->getFeedTableName());
$scope = $argv[1] ?? 'default';
$ids = $db->fetchCol($db->select()->from($table, ['id'])->where("JSON_UNQUOTE(JSON_EXTRACT(feed_data, '$.storeViewCode')) = ?", $scope)->where('status = 200 AND is_deleted = 0')->limit(2));
if (count($ids) !== 2) throw new RuntimeException('This probe requires two accepted product feed records.');
$before = $read();$imported = (int)str_replace(',', '', $before[$scope]['feedProducts']['value']);
$db->beginTransaction();
try {
    $db->update($table, ['status' => 0, 'errors' => ''], ['id = ?' => $ids[0]]);
    $db->update($table, ['status' => 500, 'errors' => '["Local rollback-only verification"]', 'is_deleted' => 1], ['id = ?' => $ids[1]]);
    $during = $read();$cell = $during[$scope]['feedProducts'];
    if ($cell !== SourceFeedCounts::cell($imported - 2, 1, 1)) throw new RuntimeException('Pending/failed deletion counters differ from expected.');
    foreach ($before as $code => $values) {
        if ($code !== $scope && $during[$code] !== $values) throw new RuntimeException('Other source counts changed.');
    }
    foreach (['feedCategories', 'feedAttributes'] as $field) {
        if (($before[$scope][$field] ?? null) !== ($during[$scope][$field] ?? null)) throw new RuntimeException('Other entity counts changed.');
    }
    $db->update($table, ['status' => 200, 'errors' => ''], ['id = ?' => $ids[1]]);
    if ($read()[$scope]['feedProducts'] !== SourceFeedCounts::cell($imported - 2, 1, 0)) throw new RuntimeException('Acknowledged deletion must leave the backlog and stay outside imported totals.');
} finally {
    $db->rollBack();
}
if ($read() !== $before) throw new RuntimeException('Rollback did not restore original counts.');
echo json_encode(['source' => $scope, 'before' => $before[$scope], 'during' => $cell, 'rollbackVerified' => true], JSON_PRETTY_PRINT), "\n";
