<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/** OSS can explain native resources, but contains no generic storage or mutation implementation. */
class ReadOnlyConfiguration implements ConfigurationInterface
{
    public function __construct(private readonly NativeResources $native, private readonly Definition $definition) {}
    public function capabilities(): array
    {
        return ['mode' => 'native', 'can_manage' => false,
            'message' => 'This shows your current native catalog configuration. Connect Catalog Cloud to create and manage independent catalog resources.'];
    }
    public function all(string $kind): array
    {
        $this->definition->table($kind);
        return $this->native->snapshot()[$kind];
    }
    public function get(string $kind, int $id): array
    {
        foreach ($this->all($kind) as $row) if ($row['id'] === $id) return $row;
        throw new NoSuchEntityException(__('This native catalog resource no longer exists.'));
    }
    public function save(string $kind, array $data): array
    {
        throw new LocalizedException(__('Connect Catalog Cloud to create and manage independent catalog resources.'));
    }
    public function delete(string $kind, int $id, int $version): void
    {
        throw new LocalizedException(__('Native catalog resources cannot be deleted here.'));
    }
    public function bookCurrency(int $bookId): string { return (string)$this->get('books', $bookId)['effective_currency']; }
}
