<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductListing\Plugin\Listing;

use Magento\Catalog\Model\Layer\Search\ItemCollectionProvider;
use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Marks the collection a listing page is built from, so ListingHydration knows to act on it.
 *
 * Lots of collections extend Product\Collection — a configurable's children, related products,
 * upsells, bundle selections. A plugin on that class fires on all of them, and hydrating a child
 * collection costs more than it saves. Requiring this flag means only the listing qualifies, and
 * nested collections are excluded because nobody marked them.
 *
 * ElasticSuite routes category browsing through a virtualType of this class, so plugging the
 * concrete class covers both layers, with ElasticSuite and without it.
 */
class CollectionFlag
{
    public const FLAG = 'gc_storefront_listing';

    /**
     * @param ItemCollectionProvider $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetCollection(ItemCollectionProvider $subject, $result)
    {
        if ($result instanceof Collection) {
            $result->setFlag(self::FLAG, true);
        }

        return $result;
    }
}
