#!/usr/bin/env python3
"""Execute every inventoried stable WP/WC release on disposable databases in shards."""
import argparse, json, os, pathlib, re, shutil, subprocess, urllib.request, zipfile
parser=argparse.ArgumentParser()
parser.add_argument('--shard',type=int,default=0);parser.add_argument('--shards',type=int,default=1)
parser.add_argument('--work',required=True);parser.add_argument('--dbhost',default='127.0.0.1:3306');parser.add_argument('--dbpass',default='root')
a=parser.parse_args();repo=pathlib.Path(__file__).resolve().parents[1];work=pathlib.Path(a.work).resolve();work.mkdir(parents=True,exist_ok=True)
manifest=json.loads((repo/'tests/compatibility/versions.json').read_text());targets=[('wordpress',v) for v in manifest['wordpress']]+[('woocommerce',v) for v in manifest['woocommerce']]
targets=[(i,k,v) for i,(k,v) in enumerate(targets) if i%a.shards==a.shard]
cache=work/'downloads';cache.mkdir(exist_ok=True);results=[]
def download(url,path):
 if not path.exists():
  with urllib.request.urlopen(url,timeout=120) as r,open(path.with_suffix('.part'),'wb') as f:shutil.copyfileobj(r,f)
  path.with_suffix('.part').rename(path)
 return path

def extract(archive,dest,prefix):
 with zipfile.ZipFile(archive) as z:
  for item in z.infolist():
   p=pathlib.PurePosixPath(item.filename)
   if item.is_dir():continue
   assert p.parts[0]==prefix and '..' not in p.parts and not p.is_absolute()
   out=dest.joinpath(*p.parts[1:]);out.parent.mkdir(parents=True,exist_ok=True);out.write_bytes(z.read(item))

def wp(site,*args,log):
 p=subprocess.run(['wp','--path='+str(site),*args],text=True,stdout=log,stderr=subprocess.STDOUT)
 if p.returncode:raise RuntimeError('WP-CLI failed: '+' '.join(args[:3]))
for index,kind,version in targets:
 site=work/'site';logpath=work/f'{kind}-{version}.log';result={'kind':kind,'version':version,'php':'8.1','database':a.dbhost,'status':'failed'}
 try:
  if site.exists():shutil.rmtree(site)
  site.mkdir();wc=version if kind=='woocommerce' else '8.3.0'
  archive=download('https://downloads.wordpress.org/plugin/woocommerce.'+wc+'.zip',cache/f'woocommerce-{wc}.zip')
  with zipfile.ZipFile(archive) as z:
   header=z.read('woocommerce/woocommerce.php').decode();minimum=re.search(r'Requires at least:\s*([\d.]+)',header).group(1)
  wp_version=version if kind=='wordpress' else (minimum if tuple(map(int,minimum.split('.'))) >= (6,5) else '6.5')
  if wp_version not in manifest['wordpress']:raise RuntimeError('Uninventoried WooCommerce WordPress requirement: '+wp_version)
  result.update(wordpress=wp_version,woocommerce=wc)
  core=download('https://wordpress.org/wordpress-'+wp_version+'.zip',cache/f'wordpress-{wp_version}.zip')
  extract(core,site,'wordpress');extract(archive,site/'wp-content/plugins/woocommerce','woocommerce')
  (site/'wp-content/plugins/cardraabta-pay-with-jazzcash').symlink_to(repo,target_is_directory=True)
  with open(logpath,'w') as log:
   wp(site,'core','verify-checksums',log=log)
   wp(site,'config','create','--dbname=wordpress','--dbuser=root','--dbpass='+a.dbpass,'--dbhost='+a.dbhost,'--dbprefix=crjv'+str(index)+'_',log=log)
   wp(site,'core','install','--url=http://localhost:8080','--title=Compatibility','--admin_user=crjtest','--admin_password=local-test-only-password','--admin_email=test@example.org','--skip-email',log=log)
   wp(site,'plugin','activate','woocommerce','cardraabta-pay-with-jazzcash',log=log)
   wp(site,'plugin','verify-checksums','woocommerce',log=log)
   env=os.environ.copy();env['CRJ_TESTING']='1'
   p=subprocess.run(['wp','--path='+str(site),'eval-file',str(repo/'tests/integration.php')],env=env,stdout=log,stderr=subprocess.STDOUT)
   if p.returncode:raise RuntimeError('Functional regression failed')
  text=logpath.read_text();match=re.search(r'RESULT: (\d+) passed; 0 failed',text)
  if not match:raise RuntimeError('No completed regression outcome')
  result.update(status='passed',assertions=int(match.group(1)))
 except Exception as error:result['error']=str(error)
 results.append(result);(work/'results.json').write_text(json.dumps(results,indent=2)+'\n');print(json.dumps(result),flush=True)
print(f"Completed {len(results)} releases: {sum(r['status']=='passed' for r in results)} passed",flush=True)
if len(results)!=len(targets) or any(r['status']!='passed' for r in results):raise SystemExit(1)
