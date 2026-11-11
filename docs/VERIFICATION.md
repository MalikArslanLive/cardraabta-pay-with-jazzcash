# Verification snapshot for 1.0.0

Scope: the implemented JazzCash sandbox workflow. Runtime code hashes are retained in runtime-sha256.json; the per-version snapshot is in COMPATIBILITY-RESULTS.csv. Tests use synthetic data and do not make real customer payments.

| Verification | Observed outcome |
| --- | --- |
| Published stable WordPress 6.5 through 7.1.4 | 77 release targets passed |
| Published stable WooCommerce 8.3.0 through 11.2.2 | 153 release targets passed |
| Real-database regression suite | 72 assertions per release, 16,560 total |
| PHP 8.1–8.5 × MySQL 8.4/MariaDB 11.4 × HPOS on/off × Redis on/off | All 40 runtime combinations passed |
| Minimum WordPress 6.5/WooCommerce 8.3/PHP 8.1 baseline | Passed |
| Latest WordPress 7.1.4/WooCommerce 11.2.2/PHP 8.5 | All eight database/storage/cache cases passed |
| Ubuntu/Windows/macOS PHP/JavaScript syntax | Passed; full database tests run on Ubuntu |
| HTTP authorization, no-cache and callback rewrites | 11 checks passed |
| Nginx/Apache/Caddy/OpenLiteSpeed concurrent callbacks | Exactly one successful settlement |
| Persistent Redis/Predis | Cross-process persistence and behavior passed |
| Nginx/LiteSpeed public page cache | Public cache HIT and private payment responses verified |
| Transaction UI | 320/375/768/1024/1440px checks passed; scoped Axe A/AA audit found no violations |
| Extended JavaScript CodeQL and Gitleaks | No findings/leaks in executed scans |
| WordPress Plugin Check 2.2.0 | No errors/warnings in the checked distributable; no packaged-file/check exclusions |

The matrix uses declared compatible pairings, not all Cartesian combinations of historic dependencies. PHP 8.1/MariaDB 11.4 provide the historical-release baseline. The official stable inventory excludes unpublished WordPress tag 6.5.1. See TESTING.md for exact pairing/aggregation rules.

The local hosting review used Nginx 1.30.6/PHP-FPM 8.3.36, Apache 2.4.69/PHP 8.3.36, Caddy 2.11.9/PHP-FPM 8.3.36, and OpenLiteSpeed 1.9.4/LSAPI PHP 8.2.35. Redis Object Cache 3.0.0/Predis and LiteSpeed Cache 7.9.3 were representative cache integrations. Real HTTPS checkout/callback behavior was verified with an explicitly trusted short-lived test certificate; TLS verification was not disabled.

Some checks target runtime behavior, some the package, and some source/tooling. WordPress Plugin Check and package-byte verification must be rerun when release documentation/manifest changes. The maintained workflows reproduce fresh results; older hosted run records may be removed independently of this retained snapshot.

Live payments/refunds, approved production-provider semantics and merchant end-to-end verification remain pending. WordPress.org submission/human acceptance are separate. Automated evidence does not certify every vulnerability, dependency version, host, CDN, cache or third-party plugin. CodeQL does not cover PHP; PHP checks use Plugin Check, behavior tests and code review. Dependency review is PR-specific and skips ordinary pushes. Private GitHub alert dashboards require separate permission; unavailable APIs do not establish absence of alerts.

The current installable ZIP is tracked in releases/1.0.0 with its SHA-256 sidecar. Run scripts/verify-release.py from the source checkout to check each included file and, optionally, the exact Git tag. The checksum is outside the ZIP to avoid a self-referential package hash.

## Minimal WordPress.org package recheck — 11 November 2026

The upload ZIP now contains nine files: the six unchanged runtime files, readme.txt, canonical LICENSE and NOTICE.txt. Repository documentation, contributor/security guides, separate changelog, tests and verification snapshots remain in the tagged source repository. Fresh Plugin Check 2.2.0 on the extracted nine-file package completed with zero errors and zero warnings; all runtime snapshot hashes remain unchanged. Source/manifest/checksum verification is rerun for this package. The historical matrix above is retained evidence and was not rerun for this packaging-only change.
