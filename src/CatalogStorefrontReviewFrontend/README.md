# GraphCommerce_CatalogStorefrontReviewFrontend

What this package changes about core's rendered review blocks. It depends on Magento_Review
alone, reads no document and runs on a shop that serves nothing from documents.

- [`Plugin/RatingsMemo`](Plugin/RatingsMemo.php) holds the rating collection of the review
  form for the request. The block builds the collection, loads it and loads its options
  again on every call, and the form template asks seven times: once to decide whether to
  render, once for the count, once per loop and again for the script that validates the
  answers. That is 17 queries for one list of ratings, on every page that carries the form.
