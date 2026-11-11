#!/usr/bin/env python3
"""Fail unless every inventoried release has one passing, nonzero test result."""
import csv,json,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[1];manifest=json.loads((root/'tests/compatibility/versions.json').read_text())
expected={(kind,v) for kind in ('wordpress','woocommerce') for v in manifest[kind]}
rows=[]
for p in pathlib.Path(sys.argv[1]).rglob('results.json'):rows.extend(json.loads(p.read_text()))
keys=[(r['kind'],r['version']) for r in rows]
assert len(keys)==len(set(keys)), 'Duplicate release outcomes'
assert set(keys)==expected, f'Missing/extra release outcomes: {expected.symmetric_difference(keys)}'
assert all(r['status']=='passed' and r.get('assertions',0)>0 for r in rows), 'Failed or zero-test release'
with open(sys.argv[2],'w') as f:
 w=csv.DictWriter(f,fieldnames=['kind','version','wordpress','woocommerce','php','database','status','assertions']);w.writeheader();w.writerows(rows)
print(f'{len(rows)} releases passed; {sum(r["assertions"] for r in rows)} assertions; no missing outcomes')
