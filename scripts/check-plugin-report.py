#!/usr/bin/env python3
"""Fail CI for any Plugin Check finding, regardless of its CLI exit status."""
from pathlib import Path
import json
import sys
report = Path(sys.argv[1]).read_text()
findings = []
for line in report.splitlines():
    if line.startswith('['):
        findings.extend(json.loads(line))
if findings:
    for finding in findings:
        print(f"{finding['type']}: {finding['code']}: {finding['message']}")
    raise SystemExit(1)
if 'Success:' not in report or 'No errors found' not in report:
    raise SystemExit('Plugin Check did not report successful completion')
print('Plugin Check completed: 0 errors, 0 warnings')
