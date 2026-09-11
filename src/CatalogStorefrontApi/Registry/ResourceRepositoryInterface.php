<?php

declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Registry;

interface ResourceRepositoryInterface
{
    public function get(string $kind, int $id): array;
    public function all(string $kind): array;
    public function save(string $kind, array $data): array;
    public function delete(string $kind, int $id, int $version): void;
}
