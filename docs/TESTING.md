# Compatibility and security verification for 1.0.0

The selected workflow is sandbox payment processing. Tests use synthetic credentials, mock the provider when creating hosted links, and never move real money. Version remains 1.0.0.

## Every published stable release in the requested range

`tests/compatibility/versions.json` inventories **77 WordPress releases from 6.5 through 7.1.4** and **153 WooCommerce releases from 8.3.0 through 11.2.2**, including patch releases, as of 11 November 2026. WordPress's official stable-check API and WooCommerce's official plugin version metadata supply the inventory. WordPress tag 6.5.1 is excluded because it was not published as a stable release/download; its official download returns 404 and the stable-check API does not list it. Beta, RC and development versions are excluded.

The `Every stable release` GitHub workflow executes all **230 release targets** in 16 shards. Each target gets a separate WordPress database prefix, official downloads/checksum verification, plugin activation and the full real-database regression suite. WordPress releases are paired with WooCommerce 8.3.0. Each WooCommerce release is paired with at least WordPress 6.5 or its own declared WordPress minimum, whichever is newer. This release inventory uses PHP 8.1/MariaDB 11.4. It does not claim every Cartesian combination of all historical dependencies is supported.

The current completed per-version snapshot is retained in docs/COMPATIBILITY-RESULTS.csv in the tagged source repository; development documentation/results are excluded from the WordPress.org upload ZIP. New workflow executions publish fresh results as artifacts; historic hosted run records need not be retained to reproduce the checks.

An aggregate job verifies exact inventory equality, no duplicate/missing outcomes, no failed releases and nonzero assertions. It publishes `compatibility-results.csv` with each release, exact paired versions and result. A green shard alone does not establish complete coverage.

## PHP, database, storage and persistent cache matrix

`Plugin checks` exercises PHP 8.1, 8.2, 8.3, 8.4 and 8.5 against **both MySQL 8.4 and MariaDB 11.4**, both HPOS and legacy order storage, with and without **Redis 7 / Redis Object Cache 3.0.0 / Predis**: 40 runtime combinations, plus the declared WordPress 6.5/WooCommerce 8.3 baseline. Redis persistence is verified between separate PHP processes before behavioral tests. SQL lock ownership is checked on the real database, never with a SQLite lock shim.

Full database tests run on Ubuntu. Separate Ubuntu/Windows/macOS jobs check PHP and JavaScript syntax; they do not establish complete Windows/macOS hosting compatibility. Database versions beyond the named test versions, Memcached, every CDN/cache plugin, proprietary LiteSpeed Enterprise and IIS are not certified by this matrix.

## Functional and security checks

Each release/runtime regression verifies callback parsing/bounds/case ambiguity, signature binding, amount/merchant/currency/reference mismatches, current order changes, durable attempts, replay idempotency including unsigned noise/signature casing, late failures, double settlement prevention, literal/omitted/malformed merchant password handling, AES-GCM tamper handling, private order returns, continuation authorization, provider-link allowlisting, verified TLS/no redirects, mocked link creation, disabled live mode, CSV formula defense, filtered keyset pagination, cross-connection locks and fail-closed behavior after lock loss.

HTTP checks exercise forged returns/IPN, method/content-type rejection, anonymous export denial, manager export nonce enforcement, manager history, no-store callback headers and pretty callback rewrites. Concurrent callback requests on actual web servers must produce exactly one successful settlement note/order completion; a busy request gets a retryable 503.

Chromium checks transaction details, settings switching, contained page layout at 320/375/768/1024/1440px and independently scrollable financial tables. Axe audits the plugin transaction panel against WCAG A/AA tags. The review fixed button/badge contrast, search labeling, keyboard focus/scroll access and mobile banner/toolbar sizing. Automated accessibility checks do not replace assistive-technology/manual review.

WordPress Plugin Check 2.2.0 scans the extracted final distributable with no packaged-file, check-category or result-code exclusions. Necessary custom-ledger queries and uncached connection-lock operations have narrow documented PHPCS comments. A wrapper fails CI even when upstream reports findings with exit code zero.

Extended JavaScript CodeQL fails on SARIF findings; GitHub default CodeQL remains enabled separately. CodeQL does not scan PHP. Gitleaks scans full Git history with a checksum-verified binary and redacted output. Dependency review is configured for pull requests and intentionally skips pushes. Dependabot monitors pinned Actions. PHP security is assessed by Plugin Check, behavior tests and code review; no test certifies absence of every vulnerability.

## Hosting and cache tests

See `HOSTING.md` for required configuration and `tests/servers/` for actual routing fixtures. Local real-server checks use Nginx 1.30.6/PHP-FPM 8.3, Apache 2.4.69/PHP 8.3, Caddy 2/PHP-FPM 8.3, and OpenLiteSpeed 1.9.4/LSAPI PHP 8.2. Nginx public page-cache HIT behavior is checked separately from bypass/no-store payment routes. Redis is a real persistent service, not an in-memory substitute. OpenLiteSpeed needs explicit virtual-host front-controller rewrites in the tested image.

Real HTTPS was separately verified using a short-lived local test certificate explicitly trusted by the client: certificate verification succeeded, callback headers were private/no-store, and the gateway rendered on a PKR HTTPS classic checkout. The storefront Coming soon setting must be disabled for that public checkout check. No TLS verification was disabled.

## Reproduce

Run only on disposable sites: tests create orders, replace gateway settings and insert synthetic ledger rows.

```sh
CRJ_TESTING=1 WP_PATH=/absolute/disposable/wordpress bash scripts/test-wordpress.sh
python3 scripts/test-all-versions.py --shard 0 --shards 16 --work /absolute/disposable/releases
python3 scripts/build-release.py dist
unzip dist/cardraabta-pay-with-jazzcash-1.0.0.zip -d /tmp/crj-package
wp --path=/absolute/disposable/wordpress plugin install plugin-check --version=2.2.0 --activate
wp --path=/absolute/disposable/wordpress plugin check /tmp/crj-package/cardraabta-pay-with-jazzcash --slug=cardraabta-pay-with-jazzcash --format=json > plugin-check.txt
python3 scripts/check-plugin-report.py plugin-check.txt
```

The historical-release script needs native PHP 8.1, WP-CLI, Python 3, a disposable `wordpress` database and working outbound verified HTTPS. Repeat all 16 shards, then aggregate their result files. Default database credentials in the test harness are synthetic CI defaults; never use it with a merchant database. Sync existing orders before changing authoritative HPOS storage.

## Remaining external validation

Approved production API/endpoints/signature semantics, refunds and merchant end-to-end verification remain pending as agreed. Live payments and refunds remain disabled. WordPress.org submission/human acceptance are separate; automated results do not establish directory approval or a complete live payment integration. Use currently security-patched dependency versions in deployment even when older compatibility targets pass.

References: https://developer.wordpress.org/apis/security/ · https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/ · https://github.com/WordPress/plugin-check · https://owasp.org/www-project-top-ten/
