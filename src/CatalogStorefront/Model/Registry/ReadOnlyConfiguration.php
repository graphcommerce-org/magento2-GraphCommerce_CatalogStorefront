<?php
declare(strict_types=1);
namespace GraphCommerce\CatalogStorefront\Model\Registry;

use GraphCommerce\CatalogStorefrontApi\Service\ConfigurationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/** Presents the catalog resources Magento itself holds. It stores nothing of its own. */
class ReadOnlyConfiguration implements ConfigurationInterface
{
    public function __construct(private readonly NativeResources $native, private readonly Definition $definition) {}
    public function capabilities(): array
    {
        return ['mode' => 'native', 'can_manage' => false,
            'message' => 'This shows the catalog configuration of your Magento store views, websites, customer groups and stocks. It is read only.'];
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
        throw new LocalizedException(__('Catalog resources follow the Magento configuration and cannot be saved here.'));
    }
    public function delete(string $kind, int $id, int $version): void
    {
        throw new LocalizedException(__('Native catalog resources cannot be deleted here.'));
    }
    public function bookCurrency(int $bookId): string { return (string)$this->get('books', $bookId)['effective_currency']; }
}
