<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Query;

use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefront\Model\Mode;
use Magento\Framework\GraphQl\Query\QueryProcessor;

/**
 * In strict mode, the response extensions carry the request's path, its
 * fallbacks and its SQL statements under `catalogStorefront`.
 */
class StrictReport
{
    public function __construct(
        private readonly Strict $strict,
        private readonly Mode $mode,
    ) {
    }

    public function afterProcess(QueryProcessor $subject, array $result): array
    {
        if ($this->strict->enabled()) {
            $result['extensions']['catalogStorefront'] = ['mode' => $this->mode->name()] + $this->strict->report();
        }

        return $result;
    }
}
