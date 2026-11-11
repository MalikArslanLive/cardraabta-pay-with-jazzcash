#!/usr/bin/env python3
"""Build a deterministic runtime-only WordPress plugin ZIP."""
import hashlib
from pathlib import Path
import re
import sys
import zipfile
root = Path(__file__).resolve().parents[1]
slug = 'cardraabta-pay-with-jazzcash'
version = re.search(r'\* Version: (\S+)', (root / (slug + '.php')).read_text()).group(1)
assert version == '1.0.0', 'Review the release version before packaging'
assert f'Stable tag: {version}' in (root / 'readme.txt').read_text()
out = Path(sys.argv[1] if len(sys.argv) > 1 else 'dist')
out.mkdir(parents=True, exist_ok=True)
files = [root / x for x in ['LICENSE', 'README.md', 'SECURITY.md', 'readme.txt', slug + '.php', 'blocks.js']]
files += sorted((root / 'includes').rglob('*.php')) + sorted((root / 'assets').glob('*'))
archive = out / f'{slug}-{version}.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for path in sorted(files):
        info = zipfile.ZipInfo(slug + '/' + path.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, path.read_bytes())
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{checksum}  {archive.name}\n')
print(archive)
print(checksum)
