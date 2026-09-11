# Catalog resource registry

The Open Source package provides the six native Admin listings and forms, backed
by a read-only presentation of the installed platform configuration. It does not
ship the generic registry tables, CRUD implementation, View binding compiler or
layer assembler. Changing a UI capability cannot enable an absent service.

`ConfigurationInterface` and `SourceMetadataInterface` connect these UI components
to their provider using arrays and scalar IDs. The Open Source provider rejects
mutations and reports unavailable generic source metadata. Native presentation
IDs are snapshot references, not persisted generic resource IDs.

With the Premium service installed, Views, Sources, Price Books, Stocks, Layers
and Policies become independently stored configuration entities. The following
relationships describe that service-backed UI. Its schema and implementation
live in `GraphCommerce_CatalogStorefrontService`, retaining existing table names
and data during upgrades. These configuration tables do not store feed payloads.

A resource has an integer ID, unique code within its kind, name, adapter type,
enabled flag, edit version, typed configuration and timestamps. Adapter type IDs
are stable across platforms. The Admin labels use **MageOS** when the installed
base distribution is MageOS, and **Magento** otherwise. Optional MageOS modules
installed on Magento do not change the platform label.

## Relationships

- A View selects one Catalog Source and an optional Stock. Price-book selection
  supports all, selected or one book. Private Views require exactly one book in
  single-book mode, enforced by both the native UI and repository. View-to-book and View-to-policy links have
  foreign keys. View-to-layer links persist their resolution order.
- A Price Book has an optional parent. Empty child books exist independently of
  price rows and inherit currency. Root books require currency. Cycles and
  incompatible explicit child currencies are rejected.
- A Stock has its own identity. Generic Stocks hold Inventory Source references;
  the MSI adapter reads native Stock and Inventory Source assignments. A website
  is an adapter binding, not a catalog-domain resource.
- A Layer declares its owned fields and merge/override operation. Global Layers
  are available to every View; other Layers use explicit ordered View links.
  Locale and default priority belong to the Layer. Source attribute metadata
  still controls discoverability; a Layer does not declare search capabilities.
- A generic Policy belongs to one Source and declares an imported filterable
  attribute, operator and static values or a named trigger. Views cannot attach
  a generic Policy from another Source.
  The native stock-visibility type reads Show Out of Stock Products from its
  bound store view. It remains an explicit stored Policy record.

Deleting a linked resource is refused. Deleting a View removes only that View's
links. Deleting a parent book requires moving or deleting its children first.
Edits and deletes require the current version to prevent overwriting another
administrator's changes. The shared mutation lock and database transaction cover
validation, resource writes and links.

## Generic and native types

| Resource | Generic | Native adapter types |
|---|---|---|
| View | Independent configuration | Store View Binding |
| Source | Locale and external source identity | Store View Catalog |
| Price Book | Currency and parent hierarchy | Root, Website, Customer Group |
| Stock | Inventory Source references | MSI Stock |
| Layer | Owned fields, locale, priority, availability | Rating Summary |
| Policy | Attribute filter with static or trigger values | Stock Visibility |

The Rating Summary type owns `rating_summary` and `review_count` metadata. It does
not turn every installed review module into a Layer, import review entities, or
claim a new review-ingestion implementation.

The initial data patch imports native sources, views, stock bindings, the price
hierarchy and stock-visibility policies once. It adds no fabricated layers.
Native resources remain editable/deletable configuration. A Store View Binding
is managed: its form exposes the native Store View selector; saving derives and
persists its name, code, enabled state, Source, Stock, Books, and stock Policy.
The View type is immutable after creation. Generic Views retain independent
configuration. Reads never recreate deleted rows or rewrite configuration. Native references must exist when
saved. The internal explicit import is idempotent and refuses code collisions
with another adapter binding.

## Admin and publication boundary

**Catalog > Catalog Storefront** contains the six existing native UI listings.
Each Add/Create action opens a native UI form. Edit actions open stored records;
Save persists them; Delete uses the native confirmation and a POST controller.
Read, create/edit and delete permissions are separate ACL resources. Native form
keys and Admin authentication remain in force. Dynamic rows edit Inventory Source
references, layer fields and ordered View Layers.

Source counts are accepted base-feed records with pending/failed counts, selected
by the native Source binding. Stock counts remain on Stocks. Generic resources
without an ingestion receipt show unknown counts; resource identity is not proof
of imported data. Price counts are not inferred from resolved search-price rows.

Saving configuration and serving a publication are separate service operations.
The Premium publication worker coalesces changes and publishes a validated
snapshot; the reader continues serving the last successful publication until
then. Public published Views require no preview key. Private Views retain their
current server-key gate. The network transport and standalone service deployment
are subsequent work; the present service binding is in process.

The native UI is shared across these providers. The service owns validation and
mutations; Admin controllers do not import native resource records or resolve
book hierarchies themselves.

## Local verification

```sh
CATALOG_REGISTRY_TEST=local MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront-premium/dev/registry/acceptance.php
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php overview
MAGENTO_ROOT="$PWD" php vendor/graphcommerce/magento-catalog-storefront/dev/registry/render.php views
```

The CRUD acceptance script requires the Premium service. It creates uniquely named local records, exercises generic and
native CRUD and rejected writes, then deletes its fixtures. The renderer compiles
the real Admin UI configuration and component output. Browser interaction and
visual verification are separate from this server-side check.

Price book changes validate the affected descendants inside the same transaction. Parent type, native website binding, and inherited currency changes cannot strand existing native children; rejected edits leave the prior record and version intact. Unrelated native configuration drift does not prevent creating a repair root.

## Cloud metadata boundary

The policy attribute picker, option picker and category picker read the selected
Source's imported attribute/category documents through
`MetadataDocumentStorageInterface`. A native Source persists its producer's
store-view code as `document_scope`; an external Source uses its own code.
The Source's display name is not used to resolve metadata.

No EAV collection, attribute repository, native option source model, or default
store metadata may be used as a fallback. A missing feed produces an explicit
unavailable picker. Cloud transport failures are surfaced. Saving validates the
attribute and values against the same imported feed; an arbitrary option ID from
another Source is refused. Changing Source clears prior picker values and ignores
late responses from the previous Source request.

Native configuration reads belong to producer adapters. `NativeViewBinding`
computes managed configuration and `NativeResources::synchronize()` can refresh
existing bindings for a publication job; neither is a storefront runtime reader.
The stock policy stores the native producer's current Show Out of Stock setting.
The runtime publication boundary above still applies.

Focused checks added on 2026-09-11: cloud-only fixture attributes that do not exist
in EAV, an empty Source with no native fallback, only a service Source-configuration lookup in SQL during an imported
metadata read (no native EAV reads), managed binding derivation, mode immutability, private/single-book
constraints, and stale asynchronous picker responses. Run UI rule tests with
`node dev/registry/form-rules.test.cjs`. Server-render checks do not substitute for
authenticated browser interaction.
