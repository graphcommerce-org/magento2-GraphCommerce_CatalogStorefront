<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the document store holds against what the catalog has: per store view
 * the documents of every entity, with the products assigned to the store's
 * website next to the product documents, and per feed the rows, the rows the
 * store did not accept (a retry is pending), the last export and the
 * indexer's state. Fails when a feed has rows waiting or an indexer is
 * invalid, so a deployment check can call it.
 */
class Status extends Command
{
    private const PRODUCT = 'product';
    private const ACCEPTED = 200;

    public function __construct(
        private readonly ProductDocumentStorageInterface $products,
        private readonly MetadataDocumentStorageInterface $metadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly Feeds $feeds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog-storefront:status')
            ->setDescription('Shows the documents per store view and the state of every feed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->resourceConnection->getConnection();
        $feeds = $this->feeds->byEntity();

        $documents = new Table($output);
        $documents->setHeaders(array_merge(['Store view'], array_map(
            static fn(string $entity) => $entity === self::PRODUCT ? 'product documents / products' : $entity . ' documents',
            array_keys($feeds)
        )));
        foreach ($this->storeManager->getStores() as $store) {
            $row = [$store->getCode()];
            foreach (array_keys($feeds) as $entity) {
                if ($entity === self::PRODUCT) {
                    $products = (int)$connection->fetchOne(
                        $connection->select()
                            ->from($this->resourceConnection->getTableName('catalog_product_website'), 'COUNT(*)')
                            ->where('website_id = ?', (int)$store->getWebsiteId())
                    );
                    $row[] = sprintf('%d / %d', $this->products->count($store->getCode()), $products);
                } else {
                    $row[] = (string)$this->metadata->count($entity, $store->getCode());
                }
            }
            $documents->addRow($row);
        }
        $documents->render();

        $problems = 0;
        $table = new Table($output);
        $table->setHeaders(['Feed', 'Rows', 'Waiting', 'Last export', 'Indexer']);
        foreach ($feeds as $entityFeeds) {
            foreach ($entityFeeds as $indexerId => $feed) {
                $stats = $connection->fetchRow($connection->select()
                    ->from($this->resourceConnection->getTableName($feed->getFeedTableName()), [
                        'rows' => 'COUNT(*)',
                        'waiting' => new \Zend_Db_Expr('SUM(status <> ' . self::ACCEPTED . ')'),
                        'last' => 'MAX(modified_at)',
                    ]));
                $indexer = $this->indexerRegistry->get($indexerId);
                $state = $indexer->getStatus() . ($indexer->isScheduled() ? ', by schedule' : ', on save');
                $problems += (int)$stats['waiting'] > 0 || $indexer->isInvalid() ? 1 : 0;
                $table->addRow([$feed->getFeedName(), $stats['rows'], $stats['waiting'] ?? 0, $stats['last'] ?? '-', $state]);
            }
        }
        $table->render();

        return $problems > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
