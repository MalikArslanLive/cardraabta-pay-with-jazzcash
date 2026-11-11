#!/usr/bin/env python3
"""Require completed analysis and fail on every CodeQL result."""
import json
from pathlib import Path
import sys
reports = list(Path(sys.argv[1]).glob('*.sarif'))
assert reports, 'No SARIF report was produced'
findings = []
for report in reports:
    data = json.loads(report.read_text())
    assert data.get('runs'), 'Analysis contains no runs'
    for run in data['runs']:
        for invocation in run.get('invocations', []):
            assert invocation.get('executionSuccessful', True), 'Analysis execution failed'
        findings.extend(run.get('results', []))
for finding in findings:
    print(finding.get('ruleId'), finding.get('message', {}).get('text', ''))
assert not findings, f'{len(findings)} CodeQL findings require review'
print(f'CodeQL: {len(reports)} reports, 0 findings')
