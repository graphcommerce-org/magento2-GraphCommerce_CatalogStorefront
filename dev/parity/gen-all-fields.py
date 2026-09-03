import json, urllib.request, ssl, sys
ctx=ssl.create_default_context(); ctx.check_hostname=False; ctx.verify_mode=ssl.CERT_NONE
def gql(q):
    r=urllib.request.Request('https://worker.localhost.reachdigital.io/graphql', data=json.dumps({'query':q}).encode(), headers={'Content-Type':'application/json'})
    return json.load(urllib.request.urlopen(r, context=ctx))['data']['__type']
cache={}
def typeinfo(name):
    if name not in cache:
        cache[name]=gql('{ __type(name:"%s") { name kind possibleTypes { name } fields(includeDeprecated:true) { name isDeprecated args { name type { kind ofType { kind } } } type { kind name ofType { kind name ofType { kind name ofType { kind name } } } } } } }' % name)
    return cache[name]
def base(t):
    while t.get('ofType'): t=t['ofType']
    return t
deprecated=set()
SKIP={'products','children','breadcrumbs','product_links','redirect_code','relative_url','type','no_archive','no_follow','no_index','default_sort_by'}
PATH_SKIP={('BundleItem','price_range'),('MediaGalleryEntry','id'),('MediaGalleryEntry','uid'),('GroupedProductItem','qty')}  # category subtrees that page or walk the tree
def selection(tname, depth, path):
    t=typeinfo(tname)
    if t['kind'] in ('SCALAR','ENUM'): return ''
    out=[]
    for f in t.get('fields') or []:
        if any(a['type']['kind']=='NON_NULL' for a in f['args']): continue
        if f['name'] in SKIP or (tname,f['name']) in PATH_SKIP or (f['name']=='websites' and depth>0): continue
        b=base(f['type'])
        if f['isDeprecated']: deprecated.add(f"{tname}.{f['name']}")
        if b['name']=='ProductInterface':
            out.append(f"{f['name']} {{ sku }}"); continue
        if b['kind'] in ('SCALAR','ENUM'):
            out.append(f['name'])
        elif depth<4:
            sub=selection(b['name'], depth+1, path+[f['name']])
            if sub: out.append(f"{f['name']} {{ {sub} }}")
    if t['kind'] in ('INTERFACE','UNION'):
        for p in t.get('possibleTypes') or []:
            pt=typeinfo(p['name'])
            names={f['name'] for f in t.get('fields') or []}
            extra=[]
            for f in pt.get('fields') or []:
                if f['name'] in names or f['name'] in SKIP or any(a['type']['kind']=='NON_NULL' for a in f['args']): continue
                b=base(f['type'])
                if f['isDeprecated']: deprecated.add(f"{p['name']}.{f['name']}")
                alias=f"{p['name'].lower()}_{f['name']}: {f['name']}"
                if b['name']=='ProductInterface': extra.append(f"{alias} {{ sku }}"); continue
                if b['kind'] in ('SCALAR','ENUM'): extra.append(alias)
                elif depth<4:
                    sub=selection(b['name'], depth+1, path+[f['name']])
                    if sub: extra.append(f"{alias} {{ {sub} }}")
            if extra: out.append(f"... on {p['name']} {{ {' '.join(extra)} }}")
    return ' '.join(out)
sel=selection('ProductInterface', 0, [])
skus=sys.argv[1:]
q='{ products(filter: { sku: { in: [%s] } }, pageSize: 20) { items { %s } } }' % (', '.join('"%s"'%s for s in skus), sel)
open(__import__('os').path.join(__import__('os').path.dirname(__file__), 'queries', '19-all-fields.graphql'),'w').write(q+'\n')
print(len(q), 'chars')
print('deprecated:', sorted(deprecated))
