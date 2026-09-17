# GraphCommerce_CatalogStorefrontGraphQlApi

The contracts of the GraphQL read path. It depends on Magento_GraphQl and
GraphCommerce_CatalogStorefrontApi.

- [`Read\PrefillerInterface`](Read/PrefillerInterface.php): fills product fields on the
  value the executor hands to the child fields, so the executor returns them without a
  resolver call. The owning module lists its fields under `prefilledFields`.
- [`Read\PrefillRequest`](Read/PrefillRequest.php): the document context plus the product
  fields the query selects. `selects()` says whether the prefiller has work.
- [`Read\HydrationInterface`](Read/HydrationInterface.php): product models built from
  documents and prefilled for one query.
- [`Parity\JudgeInterface`](Parity/JudgeInterface.php): one more verdict per query of the
  parity gate, with both responses in hand. Register it under `judges` on the command.
