# GraphCommerce_CatalogStorefrontReviewGraphQl

Reviews in GraphQL from the review documents. It plugs into Magento_ReviewGraphQl.

- `rating_summary` and `review_count` are one aggregation over the review documents for the
  whole page.
- `reviews` is one query per page: the reviews visible in the store view, newest first, each
  with its average and its rating breakdown pre-filled from the vote percents and the
  rating names.
