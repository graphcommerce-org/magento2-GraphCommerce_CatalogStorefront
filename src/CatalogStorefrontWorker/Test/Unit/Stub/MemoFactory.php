<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontWorker\Model;

/**
 * Stands in for the generated factory in the unit tests.
 */
class MemoFactory
{
    public function create(array $data = []): Memo
    {
        return new Memo($data['generations'], $data['name'], $data['limit'] ?? 5000);
    }
}
