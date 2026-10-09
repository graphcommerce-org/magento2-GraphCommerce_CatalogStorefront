<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Plugin\Strict;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontStatementGate\Model\Statements;

/**
 * The request's SQL statements under `sql` in the report.
 */
class StatementsInReport
{
    public function __construct(
        private readonly Statements $statements,
    ) {
    }

    public function afterReport(Strict $subject, array $report): array
    {
        return $report + ['sql' => $this->statements->counts()];
    }
}
