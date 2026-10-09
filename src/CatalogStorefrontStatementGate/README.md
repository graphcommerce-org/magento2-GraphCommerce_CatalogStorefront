# GraphCommerce_CatalogStorefrontStatementGate

The rule of a read side without a catalog database, enforced. The module records every SQL
statement of a request that carries the storefront key, puts them under `sql` in the
`catalogStorefront` report of the response, and adds a verdict to
`catalog-storefront:parity` that fails a document-path query which ran a lookup. A write is
printed.

- A statement that names a table under `signIn` on the judge proves who the request is: a
  signed-in request checks its token against the revoked list, which holds no catalog data
  and which a read side without a catalog database still reads. The judge prints such a
  statement with what it proves and fails no query for it.
- A query whose subject is a database entity is listed under `subjects` on the judge in
  [etc/di.xml](etc/di.xml): the cart, the wish list and the order queries read their own
  entity on both paths. The judge lists those statements with their subject and fails no
  query for them. A profiler trace tells them from the statements of a product field.
