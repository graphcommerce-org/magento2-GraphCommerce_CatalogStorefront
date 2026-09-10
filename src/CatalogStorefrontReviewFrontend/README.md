# GraphCommerce_CatalogStorefrontReviewFrontend

What the package changes about core's rendered review blocks. It reads no document and depends on
no other module of the package, so it runs on a shop that serves nothing from documents.

- `Plugin/RatingsMemo` holds the rating collection the review form renders. The block builds a
  collection, loads it and loads its options again on every call, and the form template asks seven
  times: once to decide whether to render, once for the count, once per loop and again for the
  script that validates the answers. That is 17 queries for one list of ratings, on every page that
  carries the form.
