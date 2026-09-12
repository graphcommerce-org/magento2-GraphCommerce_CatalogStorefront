<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefrontAdminhtml\Model;

final class ResourceLabels
{
    public static function name(array $row): string
    {
        $name = (string)($row['name'] ?? '');
        return ($row['type'] ?? null) === 'platform_customer_group' && str_contains($name, ' / ')
            ? explode(' / ', $name, 2)[1] : $name;
    }
}
