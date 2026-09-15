<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Model\Read;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\GraphQl\Model\Query\ContextInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The product values of the items of a list that resolves its product per
 * item (wish list items, order items). The list's resolver registers the
 * product ids of every item it returns, and the first item that asks for its
 * value fetches the documents of all of them in one request. A product
 * without a document has no value, so its resolver answers from core.
 */
class ItemProducts implements ResetAfterRequestInterface
{
    /** @var array<int, int> product ids registered and not fetched */
    private array $pending = [];

    /** @var string[] */
    private array $fields = [];

    /** @var array<int, array> the product value per product id */
    private array $values = [];

    private ?ContextInterface $context = null;

    public function __construct(
        private readonly Mode $mode,
        private readonly HydrationInterface $hydration,
        private readonly StoreManagerInterface $storeManager,
        private readonly Strict $strict,
    ) {
    }

    public function enabled(): bool
    {
        return $this->mode->documents();
    }

    /**
     * @param int[] $productIds
     * @param string[] $requestedFields product fields the query selects; empty selects all
     */
    public function expect(array $productIds, array $requestedFields, mixed $context): void
    {
        $this->context = $context instanceof ContextInterface ? $context : $this->context;
        $this->fields = array_values(array_unique(array_merge($this->fields, $requestedFields)));
        foreach ($productIds as $id) {
            if ($id > 0 && !isset($this->values[$id])) {
                $this->pending[$id] = $id;
            }
        }
    }

    /**
     * The product value built from the document, or null where none answers.
     */
    public function value(int $productId): ?array
    {
        if ($this->pending) {
            $this->fetch();
        }

        return $this->values[$productId] ?? null;
    }

    private function fetch(): void
    {
        $ids = array_values($this->pending);
        $this->pending = [];
        try {
            $store = $this->context?->getExtensionAttributes()->getStore() ?? $this->storeManager->getStore();
            $documents = $this->hydration->documents($store->getCode(), $ids);
            foreach ($this->hydration->models($store, $this->context, $documents, $this->fields) as $id => $model) {
                $value = $model->getData();
                $value['model'] = $model;
                $this->values[$id] = $value;
            }
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);
        }
    }

    public function _resetState(): void
    {
        $this->pending = [];
        $this->fields = [];
        $this->values = [];
        $this->context = null;
    }
}
