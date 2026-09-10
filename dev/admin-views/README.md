# Admin Views smoke

`smoke.php` boots Magento's Admin area and checks the read-only **Catalog >
Catalog Storefront Views** surface without authenticating a user or issuing an
HTTP request. It verifies the merged route and ACL, the GET-only controller,
the factual data sources, the exact merged layout declaration, and renders each
of the five native Magento UI listings with the Admin theme. This exercises the
listing data providers without issuing an HTTP request.
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

The native smoke does not prove the final authenticated HTTP presentation or
authorization response; use the browser check below for that.
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

1. The page contains exactly Catalog Views, Catalog Sources, Price Books,
   Catalog Layers and Catalog Policies, in that order.
2. Views and Sources contain one row per active Magento store view; locale and
   scope identifiers match Magento configuration.
3. Price Books contains the real `all` fallback followed by customer-group
   contexts. Product-price counts remain unavailable because the page does not
   scan catalog documents.
4. Catalog Layers reports installed content, inventory and review document
   contributions as module state. It does not present that state as data health.
5. Catalog Policies is an explicit planned empty state because Magento has no
   persisted managed-policy model for this surface.
6. Unavailable management actions are disabled and do not save data.
7. The five sections retain the supplied compact table hierarchy at narrow
   widths without widening the Admin page.

Do not create a user or reset a password for this check. If the existing session
opens the sign-in screen, record that authenticated browser evidence is
unavailable. A screenshot should contain only the page content, excluding the
address bar, cookies and other session-bearing browser UI.
