# Verification and release checklist

Version: 1.0.0. Scope: sandbox payment workflow. Tests never contact JazzCash with real credentials or move funds.

## Repeatable checks

GitHub workflow `.github/workflows/ci.yml` runs real WordPress/WooCommerce integration checks with MySQL/MariaDB, PHP syntax checks, WordPress Plugin Check on the extracted distributable, and JavaScript syntax checks. It fails for any packaged Plugin Check error or warning, including findings that the upstream CLI reports with exit status zero.

The integration matrix covers PHP 8.1–8.5 with WordPress 7.1.4 / WooCommerce 11.2.2, both legacy order storage and HPOS on MariaDB 11.4. Additional jobs cover the declared WordPress 6.5 / WooCommerce 8.3 minimum on PHP 8.1, and MySQL 8.4 on PHP 8.3. Ubuntu hosts the full database checks; Windows and macOS jobs validate PHP/JavaScript syntax only. This finite matrix cannot represent every hosting environment or every combination of third-party plugins.

To run against a **disposable** installation, install/activate WooCommerce and this plugin, then:

```sh
export CRJ_TESTING=1
WP_PATH=/absolute/path/to/wordpress bash scripts/test-wordpress.sh
python3 scripts/build-release.py dist
unzip dist/cardraabta-pay-with-jazzcash-1.0.0.zip -d /tmp/crj-package
wp --path=/absolute/path/to/wordpress plugin install plugin-check --version=2.2.0 --activate
wp --path=/absolute/path/to/wordpress plugin check /tmp/crj-package/cardraabta-pay-with-jazzcash --slug=cardraabta-pay-with-jazzcash --format=json > plugin-check.txt
python3 scripts/check-plugin-report.py plugin-check.txt
```

Never run integration tests against a merchant database: they create orders, replace gateway settings and insert synthetic ledger records. A real database supporting `GET_LOCK` is required. No SQLite lock shim is used. When switching existing test orders between HPOS/legacy storage, enable synchronization and run `wp wc hpos sync --user=1` before changing the authoritative store. Fresh CI databases configure storage before creating orders.

## Security coverage

The regression suite verifies strict callback parsing, duplicate/case-ambiguous key rejection, bounded payloads, signed amount/merchant/currency/reference checks, current-total changes, durable attempts, replay idempotency, late failures, second-payment settlement rejection, credential encryption/tampering, private return authorization, continuation signatures, safe provider URLs, verified TLS/no redirect transport, mocked link creation, live-mode disablement, formula-safe CSV export, filtered pagination, and locking against a separate database connection.

Plugin Check covers all its default stable checks: WordPress.org metadata/content, escaping, direct SQL, prefixing, redirects, resources, internationalization usage, version compatibility and other bundled rules. Necessary direct custom-ledger queries and connection-lock operations carry narrow, documented PHPCS comments; cached data cannot be used as payment authority. No Plugin Check checks or result codes are disabled for the packaged ZIP.

`security.yml` runs JavaScript CodeQL with extended security queries on push/PR and weekly. CodeQL does not support PHP; PHP review is supplied by Plugin Check and behavioral tests, not represented as PHP CodeQL coverage. Dependency review runs on pull requests; there are no bundled third-party runtime libraries. Dependabot monitors pinned GitHub Actions. Gitleaks 8.30.1 scans the full Git history with redacted output; its downloaded binary is pinned by SHA-256.

GitHub default CodeQL setup is already enabled on this repository and publishes to the Security tab. The custom extended JavaScript job retains SARIF as an artifact, fails on findings, and does not upload competing advanced-configuration results. This avoids the GitHub restriction on mixing default and advanced CodeQL uploads.

The review used WordPress security guidance and OWASP risk categories, including authorization/CSRF, stored/reflected XSS, SQL injection, unsafe redirects/SSRF, callback forgery/replay, information disclosure, secret storage and spreadsheet injection. These are bounded checks, not a claim that every known vulnerability is absent.

References:

- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/apis/security/
- https://github.com/WordPress/plugin-check
- https://owasp.org/www-project-top-ten/

## Observed results

The initial GitHub CI run passed all 12 integration matrix jobs and all three OS syntax jobs: https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/runs/42990571842 . Each integration run completed 62 assertions with zero failures. Local PHP 8.1–8.5 runs with HPOS both on/off also passed all 62 checks. The extracted release ZIP passed Plugin Check 2.2.0 with zero errors and zero warnings, without excluding packaged files or checks.

Local HTTP checks passed eight assertions covering neutral returns, forged callbacks/IPN, method/content-type rejection, private exports and nonce enforcement. Chromium exercised transaction details and settings switching. The test waits on stable element identity when button labels change. External WordPress/WooCommerce resources can log certificate failures in this isolated browser; plugin interaction and JavaScript exception checks are scoped to the plugin's local functionality.

## Outstanding provider and directory validation

The approved JazzCash production API contract, production endpoints/signature conventions, refund contract and merchant end-to-end tests remain pending. Live payments/refunds remain disabled. Sandbox response-code semantics must be checked against the approved production contract before live enablement.

WordPress.org automated checks are distinct from submission and human acceptance. No plugin has been submitted to WordPress.org by this work. Its guideline requiring a complete submission remains relevant to a sandbox-only payment gateway. The distributable is a versioned, installable 1.0.0 package; it is not a claim of directory approval or production payment readiness.
