<?php
declare(strict_types=1);

namespace GraphCommerce\CatalogStorefrontApi\Document;

/**
 * Writes the rows of one commerce-data-export feed into a store.
 * The delivery maps a feed name to the writers of that feed (di.xml `writers`)
 * and calls each of them with the batch; a feed without one is accepted and only
 * persisted in its feed table. A writer MAY read the database: it runs at index time.
 */
interface FeedWriterInterface
{
    /**
     * @param array[] $rows the feed batch as the exporter hands it over
     */
    public function write(array $rows): void;
}
