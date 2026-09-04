<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefrontGraphQl\Model\CategoryDocuments;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use Magento\CatalogGraphQl\Model\AttributesJoiner;
use Magento\CatalogGraphQl\Model\Resolver\Categories;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves a product's categories from the category documents. The product
 * document's category data holds what the category product index holds for
 * the store view: the assigned categories and their anchor ancestors, so only
 * the store's root category is left out, as core does. Every product of a
 * page registers its ids first and the documents are fetched in one request
 * when the first deferred value resolves, by id ascending as core's
 * collection returns them.
 */
class CategoriesFromDocuments implements ResetAfterRequestInterface
{
    /** @var array<string, array<int, true>> ids to fetch per store view */
    private array $pending = [];

    /** @var array<string, array<int, array|null>> documents fetched per store view */
    private array $loaded = [];

    public function __construct(
        private readonly CategoryDocuments $categoryDocuments,
        private readonly AttributesJoiner $attributesJoiner,
        private readonly ValueFactory $valueFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundResolve(
        Categories $subject,
        \Closure $proceed,
        Field $field,
        ContextInterface $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $document = ($value['model'] ?? null)?->getData(HydrationInterface::DOCUMENT_KEY);
        if (!is_array($document) || in_array('orders', $info->path, true)) {
            return $proceed($field, $context, $info, $value, $args);
        }

        $store = $context->getExtensionAttributes()->getStore();
        $storeViewCode = $store->getCode();
        $rootId = (int)$store->getRootCategoryId();
        $ids = [];
        foreach ((array)($document['categoryData'] ?? []) as $category) {
            $id = (int)($category['categoryId'] ?? 0);
            if ($id && $id !== $rootId) {
                $ids[$id] = $id;
                $this->pending[$storeViewCode][$id] = true;
            }
        }
        sort($ids);

        return $this->valueFactory->create(function () use ($proceed, $field, $context, $info, $value, $args, $store, $storeViewCode, $ids) {
            try {
                if (!empty($this->pending[$storeViewCode])) {
                    $fetched = $this->categoryDocuments->documents($storeViewCode, array_keys($this->pending[$storeViewCode]));
                    foreach (array_keys($this->pending[$storeViewCode]) as $id) {
                        $this->loaded[$storeViewCode][$id] = $fetched[$id] ?? null;
                    }
                    $this->pending[$storeViewCode] = [];
                }
                $documents = [];
                foreach ($ids as $id) {
                    if (isset($this->loaded[$storeViewCode][$id])) {
                        $documents[$id] = $this->loaded[$storeViewCode][$id];
                    }
                }
                $categories = $this->categoryDocuments->hydrate(
                    $store,
                    $documents,
                    $this->attributesJoiner->getQueryFields($info->fieldNodes[0], $info)
                );
            } catch (\Throwable $e) {
                $this->logger->warning('catalog-storefront categories fallback: ' . $e->getMessage());

                return $proceed($field, $context, $info, $value, $args);
            }

            return $categories;
        });
    }

    public function _resetState(): void
    {
        $this->pending = [];
        $this->loaded = [];
    }
}
