#!/usr/bin/env python3
"""Verify package integrity, checksum and byte identity to source or a Git ref."""
import argparse
import hashlib
from pathlib import Path
import subprocess
import zipfile
from release_manifest import SLUG, VERSION, source_files

parser = argparse.ArgumentParser()
parser.add_argument('archive', type=Path)
parser.add_argument('--git-ref')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
archive = args.archive.resolve()
expected = {SLUG + '/' + p.relative_to(root).as_posix(): p for p in source_files(root)}
sidecar = archive.with_suffix('.zip.sha256').read_text().strip()
checksum, name = sidecar.split(None, 1)
assert name.strip() == archive.name, 'Checksum filename mismatch'
assert hashlib.sha256(archive.read_bytes()).hexdigest() == checksum, 'ZIP checksum mismatch'
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None, 'Corrupt archive'
    assert len(z.namelist()) == len(set(z.namelist())), 'Duplicate ZIP entries'
    assert set(z.namelist()) == set(expected), 'Package manifest mismatch'
    for item in z.infolist():
        assert ((item.external_attr >> 16) & 0o777) == 0o644, 'Unexpected ZIP permissions'
        path = expected[item.filename]
        if args.git_ref:
            target = args.git_ref + ':' + path.relative_to(root).as_posix()
            data = subprocess.check_output(['git', '-C', str(root), 'show', target])
        else:
            data = path.read_bytes()
        assert z.read(item.filename) == data, 'Source byte mismatch: ' + item.filename
    header = z.read(SLUG + '/' + SLUG + '.php').decode()
    readme = z.read(SLUG + '/readme.txt').decode()
    assert '* Version: ' + VERSION + '\n' in header
    assert 'Stable tag: ' + VERSION + '\n' in readme
if args.git_ref:
    tracked = subprocess.check_output(['git', '-C', str(root), 'show', args.git_ref + ':releases/' + VERSION + '/' + SLUG + '-' + VERSION + '.zip'])
    assert tracked == archive.read_bytes(), 'Tracked ZIP differs from downloaded/local archive'
print(f'PASS: {len(expected)} package files match source byte for byte; SHA-256 {checksum}')
