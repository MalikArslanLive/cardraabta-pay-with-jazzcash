# Releasing 1.0.0

This guide is for maintainers. Contributors should follow CONTRIBUTING.md; a pull request does not authorize publishing, deploying, rewriting history or moving tags.

## Source and version

The maintained repository has one main branch and one annotated v1.0.0 release tag. Runtime source, included documentation and the tracked installable archive must describe the same final state. Keep plugin header, CRJ_ORCH_VERSION, block asset version, readme.txt Stable tag and packaging checks consistent.

The current request deliberately retains 1.0.0. A future independently approved version should normally increment its version and update the manifest/packaging assertions together. An equal-version replacement needs manual installation and cache purging; do not imply automatic version-increment detection.

## Validate before packaging

Use a disposable database and synthetic credentials. Follow docs/TESTING.md and the workflow definitions for regression, compatibility, syntax, cache, HTTP/browser, security and WordPress Plugin Check checks. Run the 230-version inventory when relevant code changes affect the supported range. Record precise outcomes; skipped checks are not passes.

Preserve TLS/package checksums and mandatory payment/ownership/lock checks. Confirm the sandbox scope and remaining production-provider requirements. Check that no credentials, personal data, raw payment payloads or private test outputs are tracked.

## Build and verify

From the repository root:

```sh
python3 scripts/build-release.py releases/1.0.0
python3 scripts/verify-release.py releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip
```

The explicit WordPress.org manifest includes only six runtime files, readme.txt, canonical LICENSE and NOTICE.txt (nine files total). README.md, SECURITY.md, CONTRIBUTING.md, CHANGELOG.md and docs/results remain in the source repository and are excluded from the installable ZIP. It excludes .git/.github, test/development scripts, environments, raw logs, dependencies and the nested releases directory. This prevents recursive ZIP inclusion.

The builder fixes ZIP entry timestamps/order/permissions for reproducibility and writes a SHA-256 sidecar. The verifier checks archive integrity, root path, version metadata, exact manifest, file bytes, permissions and the checksum. It can also compare the ZIP to an exact Git ref:

```sh
python3 scripts/verify-release.py releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip --git-ref v1.0.0
```

Extract the ZIP to a clean directory for WordPress Plugin Check; do not exclude packaged files or check categories. Pass its output through scripts/check-plugin-report.py. Confirm the rebuilt CI archive equals the committed package byte for byte.

## Commit, tag and distribute

Commit the final source and archive together. Create an annotated v1.0.0 tag containing the release scope, contribution/license/documentation updates and verified checks. Publish a release that clearly states the sandbox/live/refund limits and links the tracked ZIP/checksum under releases/1.0.0.

The same tracked ZIP is the WordPress.org candidate and GitHub download. GitHub's automatically generated source ZIP includes development files and is not the installable plugin package. Verify the downloaded candidate against the local ZIP and against source at the tag. Do not substitute a ZIP rebuilt from another working tree.

If a GitHub asset-upload endpoint is unavailable, link the exact tracked ZIP from the release; no extra artifact branch is required. Keep one maintained version and avoid obsolete generated branches/tags. Retain test definitions and the current verification snapshot even when old hosted run records are intentionally removed.

WordPress.org submission and human acceptance are separate steps. Automated checks do not establish directory approval or production-payment readiness. Live API/refunds need the approved provider contract and merchant end-to-end validation.
