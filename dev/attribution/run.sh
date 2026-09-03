#!/bin/sh
# Measures one GraphQL query on the worker and prints the latency stack.
#
# usage: run.sh <container> <proxy graphql url> <query file> [variables json] [repeats]
#
# Requires the GraphCommerce_CatalogStorefrontAttribution module enabled and
# the worker restarted on it (see CLAUDE.md, Operations). Warms both serving
# threads first, then times the request through the proxy chain and directly
# at the worker port inside the container, and reads the per-request
# attribution the module logs.
set -e
CONTAINER=$1
URL=$2
QUERY=$3
VARIABLES=${4:-'{"pageSize":200,"currentPage":1}'}
REPEATS=${5:-12}
DIR=$(cd "$(dirname "$0")" && pwd)
TMP=$(mktemp -d)

python3 - "$QUERY" "$VARIABLES" "$TMP/body.json" <<'EOF'
import json, sys
json.dump({'query': open(sys.argv[1]).read(), 'variables': json.loads(sys.argv[2])}, open(sys.argv[3], 'w'))
EOF
docker cp "$TMP/body.json" "$CONTAINER:/tmp/attribution_body.json" >/dev/null

FMT='%{time_connect} %{time_appconnect} %{time_starttransfer} %{time_total}\n'
i=0
while [ $i -lt 4 ]; do
  curl -sk -o /dev/null -H 'Content-Type: application/json' --data-binary "@$TMP/body.json" "$URL"
  i=$((i+1))
done
: > "$TMP/proxy.txt"; : > "$TMP/direct.txt"
i=0
while [ $i -lt "$REPEATS" ]; do
  curl -sk -o /dev/null -w "$FMT" -H 'Content-Type: application/json' --data-binary "@$TMP/body.json" "$URL" >> "$TMP/proxy.txt"
  docker exec "$CONTAINER" curl -s -o /dev/null -w "$FMT" -H 'Content-Type: application/json' --data-binary @/tmp/attribution_body.json http://localhost:8080/graphql >> "$TMP/direct.txt"
  i=$((i+1))
done
python3 - "$TMP" <<'EOF'
import json, statistics, sys
d = sys.argv[1]
def load(p):
    rows = [list(map(float, l.split())) for l in open(p) if l.strip()]
    return {k: statistics.median(r[i] for r in rows) * 1000 for i, k in enumerate(['connect', 'tls', 'ttfb', 'total'])}
proxy, direct = load(d + '/proxy.txt'), load(d + '/direct.txt')
json.dump({'proxy_total': proxy['total'], 'proxy_tls': proxy['tls'], 'direct_total': direct['total']}, open(d + '/client.json', 'w'))
EOF
python3 "$DIR/report.py" "$CONTAINER" 4 "$TMP/client.json"
rm -rf "$TMP"
