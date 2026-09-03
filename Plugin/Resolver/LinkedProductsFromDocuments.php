<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefront\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Read\DocumentHydration;
use GraphCommerce\CatalogStorefront\Model\Read\ProductModelBuilder;
use GraphCommerce\CatalogStorefront\Model\Storage\ProductDocumentStorage;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductFieldsSelector;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts;
use Psr\Log\LoggerInterface;

/**
 * Serves related_products, upsell_products and crosssell_products from the
 * links slice of the document.
 *
 * The linked documents are fetched by sku in one request per link type and
 * rebuilt as models, so every field of a linked product is served the same
 * way as a listed one. As the core data provider does, only enabled products
 * visible in the catalog that are available are returned, in link position
 * order.
 */
class LinkedProductsFromDocuments
{
    private const LINK_TYPES = [
        'related_products' => 'related',
        'upsell_products' => 'upsell',
        'crosssell_products' => 'crosssell',
    ];

    public function __construct(
        private readonly DocumentHydration $hydration,
        private readonly ProductDocumentStorage $storage,
        private readonly ProductFieldsSelector $productFieldsSelector,
        private readonly Visibility $visibility,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function aroundResolve(
        AbstractLikedProducts $subject,
        \Closure $proceed,
        ContextInterface $context,
        Field $field,
        array $requests
    ): BatchResponse {
        $linkType = self::LINK_TYPES[$field->getName()] ?? null;
        $documents = [];
        foreach ($requests as $request) {
            $document = ($request->getValue()['model'] ?? null)?->getData(ProductModelBuilder::DOCUMENT_KEY);
            if ($linkType === null || !is_array($document)) {
                return $proceed($context, $field, $requests);
            }
            $documents[] = $document;
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $requestedFields = array_unique(array_merge([], ...array_map(
                fn($request) => $this->productFieldsSelector->getProductFieldsFromInfo(
                    $request->getInfo(),
                    $field->getName()
                ),
                $requests
            )));

            $skusByProduct = [];
            foreach ($documents as $document) {
                $links = array_values(array_filter(
                    (array)($document['links'] ?? []),
                    static fn($link) => ($link['type'] ?? null) === $linkType
                ));
                usort($links, static fn(array $a, array $b) =>
                    [(int)($a['position'] ?? 0), $a['sku']] <=> [(int)($b['position'] ?? 0), $b['sku']]);
                $skusByProduct[(int)$document['productId']] = array_column($links, 'sku');
            }

            $linked = [];
            $skus = array_values(array_unique(array_merge([], ...array_values($skusByProduct))));
            if ($skus) {
                $linkedDocuments = [];
                foreach ($this->storage->findBySku($store->getCode(), $skus) as $entry) {
                    $linkedDocuments[(int)$entry->getId()] = $entry->getData();
                }
                $visibleIds = $this->visibility->getVisibleInCatalogIds();
                foreach ($this->hydration->buildModels($store, $linkedDocuments, $requestedFields) as $model) {
                    if ((int)$model->getStatus() === Status::STATUS_ENABLED
                        && in_array((int)$model->getVisibility(), $visibleIds, true)
                        && $model->isAvailable()
                    ) {
                        $linked[$model->getSku()] = $model;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('catalog-storefront linked products fallback: ' . $e->getMessage());

            return $proceed($context, $field, $requests);
        }

        $response = new BatchResponse();
        foreach ($requests as $request) {
            $productId = (int)$request->getValue()['model']->getId();
            $result = [];
            foreach ($skusByProduct[$productId] ?? [] as $sku) {
                if (isset($linked[$sku])) {
                    $result[] = ['model' => $linked[$sku]] + $linked[$sku]->getData();
                }
            }
            $response->addResponse($request, $result);
        }

        return $response;
    }
}
