# OpenSearch requests

`get.os.http` holds the requests in the Dev Tools syntax the VS Code OpenSearch extension runs, one per block, in this order. The store keeps one index per entity and store view behind an alias; the alias is the stable name, the index carries the version. Replace `default` for another store view. The `magento2_*` indices are core's search indexes, not ours.

1. Every index with its document count and size
2. Alias to index, which is the blue/green state of the store
3. Mapping of the product index: only the filtered and aggregated fields are mapped
4. Mapping of the review index: the fields the review module declares
5. One product document by product id
6. A product document by sku
7. The keys a product document holds, without the large ones
8. Products by type, to see how the catalog is composed
9. Products with the prices or the stock slice still missing
10. Salable products only, with their guest price index
11. The variants of a configurable, through the parent id list on the children
12. The price range of a configurable, as the listing aggregates it
13. Every category document of the store view
14. One attribute document by code
15. The rating documents: the scales the review percents are computed on
16. The reviews of one product, newest first, as the reviews field pages them
17. The rating summary of a page of products, as the listing computes it
18. The products with the most reviews
19. The refresh interval that bounds how soon a write is visible to aggregations
