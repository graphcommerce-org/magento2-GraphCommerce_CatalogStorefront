<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Console\Command;

use GraphCommerce\CatalogStorefront\Model\Document\Scopes;
use GraphCommerce\CatalogStorefront\Model\Feeds;
use GraphCommerce\CatalogStorefrontApi\Storage\MetadataDocumentStorageInterface;
use GraphCommerce\CatalogStorefrontApi\Storage\ProductDocumentStorageInterface;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
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
 * indices when they are through. The scope documents are rebuilt the same way
 * first (di.xml `scopeFeeds`) and promoted at once, since every other writer
 * fans its rows out over them: a feed table copied with the database says
 * its rows went out while the document store of the copy never had them. A
 * rebuild that fails leaves the reads where
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
        private readonly Scopes $scopes,
        private readonly array $scopeFeeds = [],
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
        foreach ($this->scopeFeeds as $entity => $entityFeeds) {
            $this->metadata->stage($entity, Scopes::SCOPE);
            if (!$this->export($entityFeeds, $output)) {
                return Command::FAILURE;
            }
            $this->metadata->promote($entity, Scopes::SCOPE);
        }
        $this->scopes->_resetState();
        $output->writeln('The fresh scope documents serve the reads');
        $indexers = [];
        foreach ($entities as $entity) {
            foreach ($this->storeManager->getStores() as $store) {
                $entity === self::PRODUCT
                    ? $this->products->stage($store->getCode())
                    : $this->metadata->stage($entity, $store->getCode());
            }
            $output->writeln(sprintf('Staged a fresh index for the %s documents', $entity));
            $indexers += $feeds[$entity];
        }
        if (!$this->export($indexers, $output)) {
            return Command::FAILURE;
        }
        $this->promote($entities, $output);

        return Command::SUCCESS;
    }

    /**
     * Truncates the feed tables, then runs the feed indexers in order.
     *
     * @param array<string, FeedIndexMetadata> $feeds by indexer id
     */
    private function export(array $feeds, OutputInterface $output): bool
    {
        $connection = $this->resourceConnection->getConnection();
        foreach ($feeds as $feed) {
            $connection->truncateTable($this->resourceConnection->getTableName($feed->getFeedTableName()));
        }
        foreach ($feeds as $indexerId => $feed) {
            $indexer = $this->indexerRegistry->get($indexerId);
            // A reindex of a working indexer returns at once; an interrupted run leaves that state behind.
            if ($indexer->isWorking()) {
                $output->writeln(sprintf('<error>The %s indexer is working: bin/magento indexer:reset %s</error>', $feed->getFeedName(), $indexerId));

                return false;
            }
            $started = microtime(true);
            $indexer->reindexAll();
            $output->writeln(sprintf('Exported the %s feed in %.1fs', $feed->getFeedName(), microtime(true) - $started));
        }

        return true;
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
