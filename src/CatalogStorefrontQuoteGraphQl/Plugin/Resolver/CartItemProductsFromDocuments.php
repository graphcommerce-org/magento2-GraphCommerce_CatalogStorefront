<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontQuoteGraphQl\Plugin\Resolver;

use GraphCommerce\CatalogStorefront\Model\Mode;
use GraphCommerce\CatalogStorefront\Model\Strict;
use GraphCommerce\CatalogStorefrontGraphQl\Model\Query\SelectedProductFields;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\HydrationInterface;
use GraphCommerce\CatalogStorefrontGraphQlApi\Read\PrefillerInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\ContextInterface;

/**
 * Merges the display fields of the documents into the product values of the
 * cart items, in one document request for the whole cart.
 *
 * The quote computes an item's row price from its product model at every
 * totals collection, so the model stays the one the quote loaded; it takes
 * the document and the prefilled fields on top, which routes the display
 * fields and the per-field document plugins to the document. The item's own
 * prices and quantity come from the quote and stay untouched. A configurable
 * item carries the ordered child next to the parent, and both take the
 * document, so `configured_variant` is served as well.
 */
class CartItemProductsFromDocuments
{
    /** The quote item option under which a configurable keeps the ordered child. */
    private const VARIANT_OPTION = 'simple_product';

    public function __construct(
        private readonly Mode $mode,
        private readonly HydrationInterface $hydration,
        private readonly SelectedProductFields $selectedFields,
        private readonly Strict $strict,
    ) {
    }

    public function afterResolve(
        ResolverInterface $subject,
        $result,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        if (!$this->mode->documents() || !is_array($result) || !$context instanceof ContextInterface) {
            return $result;
        }
        $paginated = array_key_exists('items', $result);
        $items = $paginated ? $result['items'] : $result;

        $models = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $products = [
                $item['product']['model'] ?? null,
                ($item['model'] ?? null)?->getOptionByCode(self::VARIANT_OPTION)?->getProduct(),
            ];
            foreach ($products as $product) {
                if ($product instanceof Product) {
                    $models[(int)$product->getId()] = $product;
                }
            }
        }
        if (!$models) {
            return $result;
        }

        try {
            $store = $context->getExtensionAttributes()->getStore();
            $documents = $this->hydration->documents($store->getCode(), array_keys($models));
            foreach (array_keys(array_diff_key($models, $documents)) as $id) {
                $this->strict->fallback(self::class, 'no document for product ' . $id);
            }
            $served = array_intersect_key($models, $documents);
            foreach ($served as $id => $product) {
                $product->setData(HydrationInterface::DOCUMENT_KEY, $documents[$id]);
            }
            $this->hydration->prefill($store, $context, $served, $documents, $this->selectedFields->of($info));
        } catch (\Throwable $e) {
            $this->strict->exception(self::class, $e);

            return $result;
        }

        foreach ($items as $index => $item) {
            $id = is_array($item) ? (int)(($item['product']['model'] ?? null)?->getId()) : 0;
            if (isset($served[$id])) {
                $items[$index]['product'][PrefillerInterface::KEY] = $served[$id]->getData(PrefillerInterface::KEY);
            }
        }

        if (!$paginated) {
            return $items;
        }
        $result['items'] = $items;

        return $result;
    }
}
