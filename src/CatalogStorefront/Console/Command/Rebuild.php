<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the documents of an entity from scratch: drops its indices of
 * every store view, truncates the feed tables of the feeds that write it
 * (`Model\Feeds`), so the exporter re-exports
 * every row instead of skipping the unchanged ones, and runs those feed
 * indexers. The read path falls back to core while the documents are away.
 */
class Rebuild extends Command
{
    private const ENTITIES = 'entities';
    private const PRODUCT = 'product';

    public function __construct(
        private readonly ProductDocumentStorageInterface $products,
        private readonly MetadataDocumentStorageInterface $metadata,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly State $appState,
        private readonly Feeds $feeds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('catalog-storefront:rebuild')
            ->setDescription('Rebuilds the document store of the given entities (' . implode(', ', array_keys($this->feeds->byEntity())) . '), all by default')
            ->addArgument(self::ENTITIES, InputArgument::IS_ARRAY, 'Entity names');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $feeds = $this->feeds->byEntity();
        $entities = $input->getArgument(self::ENTITIES) ?: array_keys($feeds);
        $unknown = array_diff($entities, array_keys($feeds));
        if ($unknown) {
            $output->writeln('<error>Unknown entities: ' . implode(', ', $unknown) . '</error>');

            return Command::FAILURE;
        }
        // The feed providers emulate store views, which needs an area, as indexer:reindex sets one.
        try {
            $this->appState->setAreaCode(FrontNameResolver::AREA_CODE);
        } catch (LocalizedException) {
        }
        $connection = $this->resourceConnection->getConnection();
        $indexers = [];
        foreach ($entities as $entity) {
            foreach ($this->storeManager->getStores() as $store) {
                $entity === self::PRODUCT
                    ? $this->products->drop($store->getCode())
                    : $this->metadata->drop($entity, $store->getCode());
            }
            $output->writeln(sprintf('Dropped the %s documents', $entity));
            foreach ($feeds[$entity] as $indexerId => $feed) {
                $connection->truncateTable($this->resourceConnection->getTableName($feed->getFeedTableName()));
                $indexers[$indexerId] = $feed->getFeedName();
            }
        }
        foreach ($indexers as $indexerId => $feedName) {
            $started = microtime(true);
            $this->indexerRegistry->get($indexerId)->reindexAll();
            $output->writeln(sprintf('Exported the %s feed in %.1fs', $feedName, microtime(true) - $started));
        }

        return Command::SUCCESS;
    }
}
