<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Model\DataExporter\Processor;

use Magento\DataExporter\Export\DataProcessorInterface;
use Magento\DataExporter\Export\Request\Info;
use Magento\DataExporter\Export\Request\Node;
use Magento\DataExporter\Model\Indexer\FeedIndexMetadata;
use Magento\DataExporter\Model\Provider\QueryDataProvider;

/**
 * Lets the scopes feeds export during indexing. Immediate export requires a
 * DataProcessorInterface provider, and the query provider only offers the
 * batch get() form, so this adapts it. The query is the one the field is
 * named after. Declared as the provider of the Export.scopesWebsite and
 * Export.scopesCustomerGroup fields in etc/et_schema.xml.
 */
class Scopes implements DataProcessorInterface
{
    public function __construct(
        private readonly QueryDataProvider $queryDataProvider,
    ) {
    }

    public function execute(
        array $arguments,
        callable $dataProcessorCallback,
        FeedIndexMetadata $metadata,
        ?Node $node = null,
        ?Info $info = null
    ): void {
        $dataProcessorCallback($this->queryDataProvider->get($arguments, $node, $info));
    }
}
