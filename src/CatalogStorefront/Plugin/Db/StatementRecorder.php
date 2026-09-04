<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Db;

use GraphCommerce\CatalogStorefront\Model\Strict;
use Magento\Framework\DB\Adapter\Pdo\Mysql;

/**
 * Records every SQL statement of the request for the strict mode report.
 */
class StatementRecorder
{
    public function __construct(
        private readonly Strict $strict,
    ) {
    }

    public function beforeQuery(Mysql $subject, $sql, $bind = []): ?array
    {
        if ($this->strict->enabled()) {
            $this->strict->statement((string)$sql);
        }

        return null;
    }
}
