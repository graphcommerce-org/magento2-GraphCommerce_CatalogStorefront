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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the documents of an entity from scratch: stages a fresh index per
 * store view that takes the writes while the reads keep the current
 * documents, truncates the feed tables of the feeds that write it
 * (`Model\Feeds`), so the exporter re-exports every row instead of skipping
 * the unchanged ones, runs those feed indexers, and promotes the fresh
 * indices when they are through. A rebuild that fails leaves the reads where
 * they were; the next rebuild replaces the staged index, or `--promote`
 * promotes what the feeds filled since, when the export finished by another
 * road (a reindex that pulled the feeds along as dependencies).
 */
class Rebuild extends Command
{
    private const ENTITIES = 'entities';
    private const PROMOTE = 'promote';
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
            ->addArgument(self::ENTITIES, InputArgument::IS_ARRAY, 'Entity names')
            ->addOption(self::PROMOTE, null, InputOption::VALUE_NONE, 'Only promote the staged indices the feeds filled since an interrupted rebuild');
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
        if ($input->getOption(self::PROMOTE)) {
            $this->promote($entities, $output);

            return Command::SUCCESS;
        }
        $connection = $this->resourceConnection->getConnection();
        $indexers = [];
        foreach ($entities as $entity) {
            foreach ($this->storeManager->getStores() as $store) {
                $entity === self::PRODUCT
                    ? $this->products->stage($store->getCode())
                    : $this->metadata->stage($entity, $store->getCode());
            }
            $output->writeln(sprintf('Staged a fresh index for the %s documents', $entity));
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
        $this->promote($entities, $output);

        return Command::SUCCESS;
    }

    /**
     * @param string[] $entities
     */
    private function promote(array $entities, OutputInterface $output): void
    {
        foreach ($entities as $entity) {
            foreach ($this->storeManager->getStores() as $store) {
                $entity === self::PRODUCT
                    ? $this->products->promote($store->getCode())
                    : $this->metadata->promote($entity, $store->getCode());
            }
            $output->writeln(sprintf('The fresh %s documents serve the reads', $entity));
        }
    }
}
