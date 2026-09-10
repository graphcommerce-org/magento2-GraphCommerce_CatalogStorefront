# Admin Views smoke

`smoke.php` boots Magento's Admin area and checks the read-only **Catalog >
Catalog Storefront Views** surface without authenticating a user or issuing an
HTTP request. It verifies the merged route and ACL, the GET-only controller,
the factual data sources, the exact merged layout declaration, and independently
constructs its block to render the four page sections with the Admin theme.
Its JSON report contains hashes and public identifiers, not rendered HTML,
Admin URLs, session data or credentials.

Run it against a store-backed Magento installation:

```sh
CATALOG_VIEWS_SMOKE=read-only \
MAGENTO_ROOT=/var/www/html \
php dev/admin-views/smoke.php
```

Strict data checks are the default. `CATALOG_VIEWS_REQUIRE_DATA=relaxed` keeps
the data check informational for an incomplete development database, while
route, ACL and render failures still fail the command. Set
`CATALOG_VIEWS_PACKAGE_REFERENCE` to require an exact Composer source commit.
The report always records the installed reference when Composer provides one.

The native smoke does not prove that an authenticated HTTP dispatch attaches the
declared block to the full Admin page; use the browser check below for that.
Booting the Admin area may warm normal Magento configuration and layout caches.
The smoke does not save configuration or products, run indexers, query catalog
documents, create an Admin user, or print a resolved Admin URL.

## Kubernetes identity wrapper

`online.sh` streams `smoke.php` into an existing writer container. It binds the
result to an explicitly selected namespace, pod UID, pulled image ID and package
commit, and checks the same pod identity before and after the smoke. The wrapper
requires `kubectl` and `jq`:

```sh
CATALOG_VIEWS_NAMESPACE=m2gc-catalog-cloud \
CATALOG_VIEWS_POD=magento-web-REQUIRED \
CATALOG_VIEWS_EXPECTED_POD_UID=REQUIRED \
CATALOG_VIEWS_EXPECTED_IMAGE_ID='docker-pullable://ghcr.io/ho-nl/project-backend@sha256:REQUIRED' \
CATALOG_VIEWS_PACKAGE_REFERENCE=REQUIRED \
dev/admin-views/online.sh > catalog-views-admin.json
```

Resolve the pod UID and `status.containerStatuses[].imageID` independently
after the intended release is Ready. A changing or replaced pod fails the
report; an Environment Ready condition alone is not release identity proof.
The external deployment workflow owns those expected values. The OSS smoke has
no environment name, image repository or release-tag assumption.

## Browser validation

An authenticated browser remains the visual and authorization check. Using an
existing Admin session, open **Catalog > Catalog Storefront Views** and compare
the page with the smoke report:

1. Store-view rows contain the Website > Store > Store View hierarchy, locale,
   base/default/allowed currencies and root category.
2. Document modes and the search provider match Magento's store-scoped
   configuration.
3. Customer groups are described as derived document price keys, including the
   `all` fallback; they are not presented as independent price books.
4. Feed indexer rows are statuses only and make no completeness, freshness or
   Cloud-health claim.
5. The GraphQL API link is present only when MageOS GraphQL Admin HTML is
   enabled and the current administrator has its ACL.
6. The Cloud roadmap has no connected, active, create or external management
   control.

Do not create a user or reset a password for this check. If the existing session
opens the sign-in screen, record that authenticated browser evidence is
unavailable. A screenshot should contain only the page content, excluding the
address bar, cookies and other session-bearing browser UI.
