<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Plugin\Query;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontStatementGate\Model\Statements;
use Magento\Framework\GraphQl\Query\QueryProcessor;

/**
 * The request's SQL statements under `sql` in the report of a keyed response. It runs after the
 * plugin that writes the report.
 */
class StatementsInReport
{
    public function __construct(
        private readonly Strict $strict,
        private readonly Statements $statements,
    ) {
    }

    public function afterProcess(QueryProcessor $subject, array $result): array
    {
        if ($this->strict->enabled()) {
            $result['extensions']['catalogStorefront']['sql'] = $this->statements->counts();
        }

        return $result;
    }
}
