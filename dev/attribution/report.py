#!/usr/bin/env python3
"""Stacks the latency of the steady GraphQL requests of the current worker lifetime.

usage: report.py <container> <skip first N GraphQL requests> [client json from run.sh]

Reads the GCATTR lines the attribution module logs and prints medians as one
stack from the wire down to OpenSearch's own took, then the resolvers by self
time with the plugin chain overhead around each.
"""
import json
import re
import statistics
import subprocess
import sys

text = subprocess.run(['docker', 'logs', sys.argv[1]], capture_output=True, text=True)
text = text.stdout + text.stderr
text = text[text.rfind('FrankenPHP started'):]
skip = int(sys.argv[2])
client = json.load(open(sys.argv[3])) if len(sys.argv) > 3 else {}

attr = []
for m in re.finditer(r'GCATTR (\{.*?\})",', text):
    attr.append(json.loads(m.group(1).replace('\\"', '"').replace('\\\\', '\\')))
attr = [a for a in attr if 'graphql_dispatch' in a][skip:]
n = len(attr)
if not n:
    raise SystemExit('no GraphQL requests logged in this worker lifetime')


def med(key, idx=0):
    vals = [a[key][idx] if isinstance(a[key], list) else a[key] for a in attr if key in a]
    return statistics.median(vals) if vals else 0


launch = med('launch')
dispatch = med('graphql_dispatch')
process = med('graphql_process')
parse = med('graphql_parse')
schema = med('graphql_schema')
resolvers_top = med('resolvers_top_level')

took = {'core': [], 'doc': []}
for a in attr:
    for t in a.get('took', []):
        kind, ms = t.split(':')[0], float(t.split(':')[1].rstrip('ms'))
        took[kind].append(ms)

self_ms, inner_ms = {}, {}
for a in attr:
    for cls, (ms, k) in (a.get('resolver_self') or {}).items():
        self_ms.setdefault(cls, []).append((ms, k))
    for cls, (ms, k) in (a.get('resolver_inner') or {}).items():
        inner_ms.setdefault(cls, []).append(ms)
resolvers = sorted(((statistics.median([m for m, _ in v]), statistics.median([k for _, k in v]), c) for c, v in self_ms.items()), reverse=True)
inner = {c: statistics.median(v) for c, v in inner_ms.items()}
products_cls = 'Magento\\CatalogGraphQl\\Model\\Resolver\\Products'
other_resolvers = sum(ms for ms, _, c in resolvers if c != products_cls)
chain = sum(ms - inner.get(c, 0) for ms, _, c in resolvers if c != products_cls)

wire, direct = client.get('proxy_total'), client.get('direct_total')
rows = []
if wire and direct:
    rows += [('over the wire (proxy chain, TLS)', wire), ('  proxy chain + TLS', wire - direct), ('  direct to the worker port', direct), ('    FrankenPHP outside PHP', direct - launch - med('pre_launch'))]
rows += [
    ('    PHP launch', launch),
    ('      bootstrap before launch', med('pre_launch')),
    ('      front controller and routing', launch - dispatch),
    ('      GraphQL controller dispatch', dispatch),
    ('        plugins around dispatch (page cache, CORS, config change detector)', dispatch - med('graphql_dispatch_inner')),
    ('          config change detector', med('config_change_detect')),
    ('        serialize and build the response', med('graphql_dispatch_inner') - process),
    ('          JSON render of the result', med('json_render')),
    ('          query log data', med('query_log_data')),
    ('          translation area load', med('area_load')),
    ('          request validation', med('validate_request')),
    ('          GraphQL context creation', med('context_create')),
    ('        query parse (cached per process)', parse),
    ('        schema (memo per query shape)', schema),
    ('        execute', process - parse - schema),
    ('          resolvers, top level inclusive', resolvers_top),
    ('            products: search, fetch, build', med('products_get_list')),
    ('              core search adapter', med('search_adapter')),
    ('                OpenSearch client calls', med('os_core_client')),
    ('                  OpenSearch took', sum(took['core']) / n),
    ('              document listing', med('doc_listing')),
    ('                msearch client', med('os_doc_client')),
    ('                  OpenSearch took', sum(took['doc']) / n),
    ('              build product models', med('doc_build_models')),
    ('            other resolvers, self', other_resolvers),
    ('          executor walk and resolver wrapper', process - parse - schema - resolvers_top),
    ('            ResolveInfo objects, one per resolver call', med('resolve_info_create')),
    ('            plugin chain around resolvers', chain),
    ('cross-cutting: SQL', med('sql')),
    ('cross-cutting: Redis loads', med('redis_load')),
]
print(f'{n} steady requests, medians in ms\n')
for label, v in rows:
    print(f'{v:7.1f}  {label}')
print(f'\nper request: sql {med("sql", 1):.0f}, redis loads {med("redis_load", 1):.0f}, OpenSearch core calls {med("os_core_client", 1):.0f}, '
      f'resolver calls {sum(k for _, k, _ in resolvers):.0f}, peak memory {med("mem_peak_mb"):.0f} MB')
statements = {}
for a in attr:
    for sql, k in (a.get('sql_statements') or {}).items():
        statements.setdefault(sql, []).append(k)
if statements:
    print('\nSQL statements, average count per request')
    origins = {}
    for a in attr:
        origins.update(a.get('sql_origins') or {})
    for sql, ks in sorted(statements.items(), key=lambda x: -sum(x[1]))[:20]:
        print(f'{sum(ks) / n:5.1f}  {sql}')
        if origins.get(sql):
            print(f'       from {origins[sql]}')
print('\nresolver: self ms, calls, class | core resolver inside the chain ms | chain and module plugins ms')
for ms, k, cls in resolvers[:25]:
    print(f'{ms:7.2f} {k:6.0f}  {cls.replace("Magento", "M")} | {inner.get(cls, 0):6.2f} | {ms - inner.get(cls, 0):6.2f}')
