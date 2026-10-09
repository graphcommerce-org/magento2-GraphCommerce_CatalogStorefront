<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontStatementGate\Plugin\Db;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontStatementGate\Model\Statements;
use Magento\Framework\DB\Adapter\Pdo\Mysql;

/**
 * Records every SQL statement of a keyed request. The key check itself reads
 * the configuration, which runs SQL on a cold cache; that read is not
 * recorded.
 */
class StatementRecorder
{
    private bool $checking = false;

    public function __construct(
        private readonly Strict $strict,
        private readonly Statements $statements,
    ) {
    }

    public function beforeQuery(Mysql $subject, $sql, $bind = []): ?array
    {
        if ($this->checking) {
            return null;
        }
        $this->checking = true;
        try {
            if ($this->strict->enabled()) {
                $this->statements->record((string)$sql);
            }
        } finally {
            $this->checking = false;
        }

        return null;
    }
}
