<?php
declare(strict_types=1);

use GraphCommerce\CatalogStorefront\Model\Config;
use GraphCommerce\CatalogStorefront\Model\Document\Delivery;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriteAcceptanceInterface;
use GraphCommerce\CatalogStorefrontApi\Document\FeedWriterInterface;
use Magento\DataExporter\Lock\FeedLockManager;
use Magento\DataExporter\Model\Batch\BatchGeneratorInterface;
use Magento\DataExporter\Model\Batch\BatchIteratorInterface;
use Magento\DataExporter\Model\Batch\BatchLocator;
use Magento\DataExporter\Model\Batch\BatchTable;
use Magento\DataExporter\Model\Batch\FeedChangeLog\Iterator;
use Magento\DataExporter\Model\FeedExportStatus;
use Magento\DataExporter\Model\FeedExportStatusBuilder;
use Magento\DataExporter\Model\FeedPool;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadataProviderInterface;
use Magento\DataExporter\Model\Indexer\ViewMaterializer;
use Magento\DataExporter\Model\Logging\CommerceDataExportLoggerInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;
use Magento\Framework\Mview\ActionFactory;
use Magento\Framework\Mview\ActionInterface;
use Magento\Framework\Mview\View\ChangelogInterface;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\ProcessManagerFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Real local SQL retry proof in isolated tables; no catalog or feed data is changed. */
final class FeedWriteAcceptanceRetryIntegrationTest extends TestCase
{
    public function testScheduledMaterializerDurablyRetriesAnAcceptanceFailure(): void
    {
        if (getenv('CATALOG_ACCEPTANCE_LOCAL_DATABASE') !== '1') {
            self::markTestSkipped('Set CATALOG_ACCEPTANCE_LOCAL_DATABASE=1 for the local Magento database.');
        }
        $root = getenv('MAGENTO_ROOT');
        self::assertIsString($root);
        $env = require $root . '/app/etc/env.php';
        self::assertContains($env['db']['connection']['default']['host'], ['127.0.0.1', 'localhost']);
        require_once $root . '/app/bootstrap.php';
        $resource = Bootstrap::create(BP, $_SERVER)->getObjectManager()->get(ResourceConnection::class);
        $connection = $resource->getConnection();
        $prefix = 'catalog_acceptance_retry_' . bin2hex(random_bytes(6));
        $sourceTable = $prefix . '_cl';
        $batchTableName = $prefix . '_batches';
        $sequenceTable = $prefix . '_sequence';

        try {
            $connection->createTable($connection->newTable($sourceTable)
                ->addColumn('version_id', Table::TYPE_BIGINT, null, [
                    'identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true,
                ])
                ->addColumn('entity_id', Table::TYPE_INTEGER, null, [
                    'unsigned' => true, 'nullable' => false,
                ]));
            $connection->createTable($connection->newTable($batchTableName)
                ->addColumn('batch_number', Table::TYPE_INTEGER, null, ['nullable' => false])
                ->addColumn('entity_id', Table::TYPE_INTEGER, null, [
                    'unsigned' => true, 'nullable' => false,
                ])
                ->addIndex('PRIMARY', ['batch_number', 'entity_id'], ['type' => 'primary']));
            $connection->insert($sourceTable, ['entity_id' => 424242]);
            $connection->insert($batchTableName, ['batch_number' => 1, 'entity_id' => 424242]);

            $locator = new BatchLocator($resource, $sequenceTable);
            $locator->init();
            $batchTable = new BatchTable(
                $resource,
                $batchTableName,
                $sourceTable,
                ['entity_id'],
                $this->createStub(CommerceDataExportLoggerInterface::class),
            );
            $iterator = new Iterator($resource, $locator, $batchTable, $sourceTable, 'entity_id');
            $generator = new class($iterator) implements BatchGeneratorInterface {
                public function __construct(private readonly BatchIteratorInterface $iterator)
                {
                }

                public function generate(FeedIndexMetadata $metadata, array $args = []): BatchIteratorInterface
                {
                    return $this->iterator;
                }
            };

            $metadata = $this->createStub(FeedIndexMetadata::class);
            $metadata->method('getFeedName')->willReturn('products');
            $metadata->method('getThreadCount')->willReturn(1);
            $attempt = (object)['writes' => 0, 'accepts' => 0, 'fail' => true, 'ids' => []];
            $writer = new class($attempt) implements FeedWriterInterface {
                public function __construct(private readonly object $attempt)
                {
                }

                public function write(array $rows): void
                {
                    ++$this->attempt->writes;
                }
            };
            $acceptor = new class($attempt) implements FeedWriteAcceptanceInterface {
                public function __construct(private readonly object $attempt)
                {
                }

                public function accept(array $rows, FeedIndexMetadata $metadata): void
                {
                    ++$this->attempt->accepts;
                    if ($this->attempt->fail) {
                        throw new \RuntimeException('intentional acceptance refusal');
                    }
                }
            };
            $config = $this->createStub(Config::class);
            $config->method('indexing')->willReturn(true);
            $statusBuilder = $this->createStub(FeedExportStatusBuilder::class);
            $statusBuilder->method('build')->willReturn($this->createStub(FeedExportStatus::class));
            $delivery = new Delivery(
                $statusBuilder,
                $config,
                $this->createStub(LoggerInterface::class),
                $this->createStub(CacheContextFactory::class),
                $this->createStub(ManagerInterface::class),
                $this->createStub(CacheInterface::class),
                ['products' => $writer],
                [],
                ['products' => $acceptor],
            );
            $action = new class($delivery, $metadata, $attempt) implements
                ActionInterface,
                FeedIndexMetadataProviderInterface {
                public function __construct(
                    private readonly Delivery $delivery,
                    private readonly FeedIndexMetadata $metadata,
                    private readonly object $attempt,
                ) {
                }

                public function execute($ids): void
                {
                    $ids = array_map('intval', $ids);
                    $this->attempt->ids[] = $ids;
                    $this->delivery->export(array_map(
                        static fn(int $id): array => ['productId' => $id, 'storeViewCode' => 'probe'],
                        $ids,
                    ), $this->metadata);
                }

                public function getFeedIndexMetadata(): FeedIndexMetadata
                {
                    return $this->metadata;
                }
            };

            $stateVersion = 0;
            $stateStatus = StateInterface::STATUS_IDLE;
            $state = $this->createStub(StateInterface::class);
            $state->method('getVersionId')->willReturnCallback(
                static function () use (&$stateVersion): int {
                    return $stateVersion;
                },
            );
            $state->method('getStatus')->willReturnCallback(
                static function () use (&$stateStatus): string {
                    return $stateStatus;
                },
            );
            $state->method('setVersionId')->willReturnCallback(
                static function (int $version) use (&$stateVersion, $state): StateInterface {
                    $stateVersion = $version;
                    return $state;
                },
            );
            $state->method('setStatus')->willReturnCallback(
                static function (string $status) use (&$stateStatus, $state): StateInterface {
                    $stateStatus = $status;
                    return $state;
                },
            );
            $state->method('loadByView')->willReturn($state);
            $state->method('save')->willReturn($state);

            $changelog = $this->createStub(ChangelogInterface::class);
            $changelog->method('getVersion')->willReturnCallback(static fn(): int =>
                (int)$connection->fetchOne($connection->select()
                    ->from($sourceTable, new Zend_Db_Expr('MAX(version_id)'))));
            $view = $this->createStub(ViewInterface::class);
            $view->method('getId')->willReturn($prefix);
            $view->method('getActionClass')->willReturn($action::class);
            $view->method('getState')->willReturn($state);
            $view->method('getChangelog')->willReturn($changelog);
            $view->method('isEnabled')->willReturn(true);
            $view->method('isIdle')->willReturnCallback(
                static function () use (&$stateStatus): bool {
                    return $stateStatus === StateInterface::STATUS_IDLE;
                },
            );

            $actionFactory = new class($action) extends ActionFactory {
                public function __construct(private readonly ActionInterface $action)
                {
                }

                public function get($className): ActionInterface
                {
                    return $this->action;
                }
            };
            $processes = $this->createStub(ProcessManagerFactory::class);
            $processes->method('create')->willReturn(new class {
                public function execute(iterable $functions): void
                {
                    foreach ($functions as $function) {
                        $function();
                    }
                }
            });
            $locks = $this->createStub(FeedLockManager::class);
            $locks->method('lock')->willReturn(true);
            $locks->method('unlock')->willReturn(true);
            $materializer = new ViewMaterializer(
                $actionFactory,
                $this->createStub(CommerceDataExportLoggerInterface::class),
                $generator,
                $processes,
                $this->createStub(FeedPool::class),
                $locks,
            );

            $materializer->execute($view);
            self::assertSame(1, $stateVersion);
            self::assertSame(2, $changelog->getVersion());
            self::assertSame([424242, 424242], array_map('intval', $connection->fetchCol(
                $connection->select()->from($sourceTable, ['entity_id'])->order('version_id ASC'),
            )));
            self::assertSame(1, $attempt->writes);
            self::assertSame(1, $attempt->accepts);

            // The iterator also allocates the empty terminal batch number after each pass.
            $connection->insert($batchTableName, ['batch_number' => 3, 'entity_id' => 424242]);
            $attempt->fail = false;
            $materializer->execute($view);
            self::assertSame(2, $stateVersion);
            self::assertSame(2, $changelog->getVersion());
            self::assertSame(2, $attempt->writes);
            self::assertSame(2, $attempt->accepts);
            self::assertSame([[424242], [424242]], $attempt->ids);
        } finally {
            foreach ([$batchTableName, $sequenceTable, $sourceTable] as $table) {
                if ($connection->isTableExists($table)) {
                    $connection->dropTable($table);
                }
            }
        }
    }
}
