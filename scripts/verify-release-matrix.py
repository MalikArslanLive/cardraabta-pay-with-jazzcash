#!/usr/bin/env python3
"""Fail unless every inventoried release has one passing, nonzero test result."""
import csv,json,pathlib,sys
root=pathlib.Path(__file__).resolve().parents[1];manifest=json.loads((root/'tests/compatibility/versions.json').read_text())
targets=[(kind,v) for kind in ('wordpress','woocommerce') for v in manifest[kind]]
expected=set(targets)
rows=[]
for p in pathlib.Path(sys.argv[1]).rglob('results.json'):rows.extend(json.loads(p.read_text()))
keys=[(r['kind'],r['version']) for r in rows]
assert len(keys)==len(set(keys)), 'Duplicate release outcomes'
assert set(keys)==expected, f'Missing/extra release outcomes: {expected.symmetric_difference(keys)}'
assert all(r['status']=='passed' and r.get('assertions',0)>0 for r in rows), 'Failed or zero-test release'
by_target={(r['kind'],r['version']):r for r in rows}
with open(sys.argv[2],'w',newline='') as f:
 w=csv.DictWriter(f,fieldnames=['kind','version','wordpress','woocommerce','php','database','status','assertions'],lineterminator='\n');w.writeheader();w.writerows(by_target[target] for target in targets)
print(f'{len(rows)} releases passed; {sum(r["assertions"] for r in rows)} assertions; no missing outcomes')
