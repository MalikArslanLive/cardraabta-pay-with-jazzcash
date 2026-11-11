#!/usr/bin/env python3
"""Build the reproducible WordPress.org runtime package."""
import hashlib
from pathlib import Path
import re
import sys
import zipfile
from release_manifest import SLUG, VERSION, source_files

root = Path(__file__).resolve().parents[1]
header = (root / (SLUG + '.php')).read_text()
assert re.search(r'\* Version: (\S+)', header).group(1) == VERSION
assert f'Stable tag: {VERSION}' in (root / 'readme.txt').read_text()
out = Path(sys.argv[1] if len(sys.argv) > 1 else 'dist')
out.mkdir(parents=True, exist_ok=True)
archive = out / f'{SLUG}-{VERSION}.zip'
with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
    for path in source_files(root):
        info = zipfile.ZipInfo(SLUG + '/' + path.relative_to(root).as_posix(), (2026, 1, 1, 0, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, path.read_bytes())
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
archive.with_suffix('.zip.sha256').write_text(f'{checksum}  {archive.name}\n')
print(archive)
print(checksum)
