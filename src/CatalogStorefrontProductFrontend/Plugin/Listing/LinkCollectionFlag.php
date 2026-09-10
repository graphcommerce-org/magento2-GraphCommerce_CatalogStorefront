<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontProductFrontend\Plugin\Listing;

use Magento\Catalog\Model\Product\Link as LinkType;
use Magento\Catalog\Model\ResourceModel\Product\Link\Product\Collection;

/**
 * Marks the collection a related, upsell or crosssell row is built from, so ListingHydration acts
 * on it as it does on a listing.
 *
 * Those rows are product cards, built the way a listing builds them: the block gives the collection
 * the minimal price, the final price, the tax percents, the listing attributes and the url rewrites
 * before it loads. What a card then costs is what a listing card costs, and the document answers it.
 *
 * A grouped product's children come from this same class, and they are not a row of cards but the
 * product itself, so only the three link types that render as cards are marked. Nothing else is,
 * which is what keeps a nested collection out of reach.
 */
class LinkCollectionFlag
{
    private const CARD_LINK_TYPES = [
        LinkType::LINK_TYPE_RELATED,
        LinkType::LINK_TYPE_UPSELL,
        LinkType::LINK_TYPE_CROSSSELL,
    ];

    /**
     * @param Collection $subject
     * @param Collection $result
     * @return Collection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSetLinkModel(Collection $subject, $result)
    {
        // The link model carries its type before the collection is built, because the factory sets
        // the type and asks for the collection after it.
        $linkTypeId = (int)$subject->getLinkModel()->getLinkTypeId();
        if (in_array($linkTypeId, self::CARD_LINK_TYPES, true)) {
            $subject->setFlag(CollectionFlag::FLAG, true);
        }

        return $result;
    }
}
