#!/bin/sh
set -eu

: "${CATALOG_VIEWS_NAMESPACE:?Set CATALOG_VIEWS_NAMESPACE to the target namespace}"
: "${CATALOG_VIEWS_POD:?Set CATALOG_VIEWS_POD to the already verified writer pod}"
: "${CATALOG_VIEWS_EXPECTED_POD_UID:?Set CATALOG_VIEWS_EXPECTED_POD_UID to the verified pod UID}"
: "${CATALOG_VIEWS_EXPECTED_IMAGE_ID:?Set CATALOG_VIEWS_EXPECTED_IMAGE_ID to the exact pulled image ID}"
: "${CATALOG_VIEWS_PACKAGE_REFERENCE:?Set CATALOG_VIEWS_PACKAGE_REFERENCE to the exact package commit}"
: "${CATALOG_VIEWS_CONTAINER:=php}"
: "${CATALOG_VIEWS_MAGENTO_ROOT:=/var/www/html}"

pod=$CATALOG_VIEWS_POD

identity() {
    pod_json=$(kubectl -n "$CATALOG_VIEWS_NAMESPACE" get pod "$pod" -o json) || return 1
    printf '%s' "$pod_json" | jq -ce --arg container "$CATALOG_VIEWS_CONTAINER" '{
        uid: .metadata.uid,
        deletionTimestamp: (.metadata.deletionTimestamp // null),
        phase: .status.phase,
        podReady: any(.status.conditions[]?; .type == "Ready" and .status == "True"),
        image: ([.spec.containers[] | select(.name == $container) | .image][0] // null),
        imageID: ([.status.containerStatuses[] | select(.name == $container) | .imageID][0] // null),
        containerID: ([.status.containerStatuses[] | select(.name == $container) | .containerID][0] // null),
        containerReady: ([.status.containerStatuses[] | select(.name == $container) | .ready][0] // false),
        restartCount: ([.status.containerStatuses[] | select(.name == $container) | .restartCount][0] // null)
    }'
}

before=$(identity)
if [ "$(printf '%s' "$before" | jq -r '.deletionTimestamp == null and .phase == "Running" and .podReady and .containerReady')" != "true" ]; then
    echo "Writer pod is not stable and Ready" >&2
    exit 1
fi
if [ "$(printf '%s' "$before" | jq -r '.uid')" != "$CATALOG_VIEWS_EXPECTED_POD_UID" ]; then
    echo "Writer pod UID does not match CATALOG_VIEWS_EXPECTED_POD_UID" >&2
    exit 1
fi
if [ "$(printf '%s' "$before" | jq -r '.imageID')" != "$CATALOG_VIEWS_EXPECTED_IMAGE_ID" ]; then
    echo "Writer image ID does not match CATALOG_VIEWS_EXPECTED_IMAGE_ID" >&2
    exit 1
fi

script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
set +e
report=$(kubectl -n "$CATALOG_VIEWS_NAMESPACE" exec -i "$pod" -c "$CATALOG_VIEWS_CONTAINER" -- \
    env CATALOG_VIEWS_SMOKE=read-only \
        CATALOG_VIEWS_PACKAGE_REFERENCE="$CATALOG_VIEWS_PACKAGE_REFERENCE" \
        MAGENTO_ROOT="$CATALOG_VIEWS_MAGENTO_ROOT" php -- \
    < "$script_dir/smoke.php")
smoke_status=$?
after=$(identity)
after_status=$?
set -e

if ! printf '%s' "$report" | jq -e 'type == "object" and has("passed") and has("checks")' >/dev/null 2>&1; then
    report=$(jq -cn --argjson status "$smoke_status" '{
        schemaVersion: 1,
        passed: false,
        checks: {smokeProcess: {passed: false, reason: "smoke did not emit a valid JSON report", exitStatus: $status}}
    }')
fi
if [ "$after_status" -ne 0 ] || ! printf '%s' "$after" | jq -e 'type == "object"' >/dev/null 2>&1; then
    after='{"lookupPassed":false}'
fi

combined=$(printf '%s\n%s\n%s\n' "$report" "$before" "$after" | jq -s \
    --arg namespace "$CATALOG_VIEWS_NAMESPACE" \
    --arg pod "$pod" \
    --arg container "$CATALOG_VIEWS_CONTAINER" \
    --arg expectedPodUID "$CATALOG_VIEWS_EXPECTED_POD_UID" \
    --arg expectedImageID "$CATALOG_VIEWS_EXPECTED_IMAGE_ID" \
    --arg packageReference "$CATALOG_VIEWS_PACKAGE_REFERENCE" \
    --argjson smokeExitStatus "$smoke_status" '
    .[0] as $smoke | .[1] as $before | .[2] as $after |
    ($before.uid == $expectedPodUID
        and $after.uid == $expectedPodUID
        and $before.imageID == $expectedImageID
        and $after.imageID == $expectedImageID
        and ($before.containerID | type) == "string"
        and $before.containerID == $after.containerID
        and ($before.restartCount | type) == "number"
        and $before.restartCount == $after.restartCount
        and $before.deletionTimestamp == null
        and $after.deletionTimestamp == null
        and $after.phase == "Running"
        and $after.podReady
        and $after.containerReady) as $identityStable |
    {
        schemaVersion: 1,
        passed: ($smokeExitStatus == 0 and $smoke.passed and $identityStable),
        runtime: {
            namespace: $namespace,
            pod: $pod,
            container: $container,
            uid: $after.uid,
            image: $after.image,
            imageID: $after.imageID,
            containerIDBefore: $before.containerID,
            containerIDAfter: $after.containerID,
            restartCountBefore: $before.restartCount,
            restartCountAfter: $after.restartCount,
            identityStable: $identityStable
        },
        packageReference: $packageReference,
        moduleSourceSha256: ($smoke.checks.module.sourceSha256 // null),
        smokeExitStatus: $smokeExitStatus,
        checks: $smoke.checks
    }')

printf '%s\n' "$combined"
[ "$(printf '%s' "$combined" | jq -r '.passed')" = true ]
