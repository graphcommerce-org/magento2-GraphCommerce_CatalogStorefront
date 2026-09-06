# GraphCommerce_CatalogStorefrontGraphQlApi

The contracts of the GraphQL read path.

- `Read\PrefillerInterface`: fills product fields on the value the executor hands to child fields.
- `Read\PrefillRequest`: a document context plus the fields the query selects.
- `Read\HydrationInterface`: product models built from documents and prefilled for a query.
- `Parity\JudgeInterface`: one more verdict of the parity gate on a query.
