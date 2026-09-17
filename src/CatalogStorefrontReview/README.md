# GraphCommerce_CatalogStorefrontReview

Reviews and ratings in the documents. It depends on Magento_Review and
Magento_ProductReviewDataExporter.

- `Model/DataExporter/Processor/` makes the reviews and rating metadata feeds export during
  indexing like the modern feeds; [etc/db_schema.xml](etc/db_schema.xml) adds the modern
  columns to the exporter's own tables and [etc/di.xml](etc/di.xml) gives both indexers the
  generic serializer.
- The feed carries the review date, the rating scales, the sku of the reviewed product and
  the approved count and average per product and store view.
- [`Model/Document/Writer/Reviews`](Model/Document/Writer/Reviews.php) writes one document
  per review and store view where it is visible, with the vote percents over the scale of
  its rating. [`Writer/Ratings`](Model/Document/Writer/Ratings.php) writes the rating
  metadata. The product document holds no review.

`bin/magento catalog-storefront:rebuild review rating` rebuilds both entities.
