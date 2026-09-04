<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Feed;

/**
 * Writes the rows of one commerce-data-export feed into the document store.
 * The export feed maps feed names to appliers (di.xml `appliers`); a feed
 * without one is accepted and only persisted in its feed table. An applier
 * MAY read the database: it runs at index time.
 */
interface FeedApplierInterface
{
    /**
     * @param array[] $rows the feed batch as the exporter hands it over
     */
    public function apply(array $rows): void;
}
