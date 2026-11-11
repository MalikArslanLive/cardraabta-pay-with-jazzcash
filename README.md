# CardRaabta – Pay with JazzCash for WooCommerce

**Stable release: 1.0.0 · GPL-2.0-or-later · Independent software by [Malik Arslan](https://malikarslan.com/).**

CardRaabta integrates JazzCash-hosted **sandbox payments** with WooCommerce. It creates a hosted payment link, verifies signed returns, maintains an independent transaction ledger, and provides transaction details and CSV export. Customers enter payment details on JazzCash's hosted page; the plugin does not collect card PAN/CVV, wallet PIN or OTP.

**Live payments and refunds are disabled.** The approved production API/refund contract and merchant end-to-end verification remain pending. Version 1.0.0 is a stable version identifier for the implemented sandbox scope; do not use it to collect real customer payments.

[Download the installable ZIP](https://raw.githubusercontent.com/MalikArslanLive/cardraabta-pay-with-jazzcash/v1.0.0/releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip) · [Release notes](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/releases/tag/v1.0.0) · [WordPress.org](https://wordpress.org/plugins/cardraabta-pay-with-jazzcash/) · [Hosting guide](docs/HOSTING.md) · [Contributing](CONTRIBUTING.md)

## Features

- A single Pay With JazzCash checkout option; actual hosted methods depend on the merchant/provider configuration.
- Signed order, amount, currency, merchant, environment and durable-attempt reconciliation.
- Replay protection, current-order checks and connection-scoped database locks.
- A transaction ledger that survives order deletion; manager-only search, details and CSV export.
- Allowlisted verified provider details, with explicit unavailable values rather than invented authorization codes.
- Literal merchant-password handling and AES-256-GCM encryption of stored passwords/integrity salts.
- Classic checkout, Checkout Blocks and HPOS integration.
- Responsive transaction views, independently scrollable tables, keyboard focus and accessible labels/contrast.

The gateway ID is `crj_card`. Existing callback routes, settings identifiers and historical order metadata remain stable.

## Requirements and tested scope

| Component | Requirement / verified coverage |
| --- | --- |
| WordPress | 6.5+; all 77 published stable releases through 7.1.4 inventoried and tested |
| WooCommerce | 8.3+; all 153 published stable releases through 11.2.2 inventoried and tested |
| PHP | 8.1–8.5 tested, with OpenSSL and the WordPress MySQL driver |
| Database | MySQL/MariaDB with real connection-scoped locks; matrix covers MySQL 8.4 and MariaDB 11.4 |
| Checkout | HTTPS, valid certificate chain and PKR store/order currency |
| Cache/storage | HPOS and legacy order storage, Redis enabled/disabled |
| Hosting review | Nginx, Apache, Caddy and OpenLiteSpeed; see exact versions/configuration in the hosting/testing guides |

WooCommerce can require a newer WordPress version; meet both dependencies' requirements. SQLite payment processing is unsupported and fails closed. Use currently security-patched dependencies in deployment even where historical versions pass compatibility tests.

The 230-release inventory is tested with declared compatible pairings, not every Cartesian combination of historic dependencies. The runtime matrix separately covers PHP × database × HPOS × Redis combinations. Full database checks run on Ubuntu; Windows/macOS jobs check syntax. Proprietary LiteSpeed Enterprise, IIS, Memcached and every CDN/plugin combination are not certified. See [testing scope](docs/TESTING.md), [version results](docs/COMPATIBILITY-RESULTS.csv) and [verification snapshot](docs/VERIFICATION.md).

## Installation and configuration

1. Back up the test site's database, files and matching `wp-config.php` salts.
2. Install and activate WooCommerce, complete its setup, and make the test storefront visible. New WooCommerce installations can default to Coming soon.
3. Upload **the installable ZIP**, not GitHub's generic source archive, through Plugins → Add New Plugin → Upload Plugin, then activate CardRaabta.
4. Open WooCommerce → Settings → Payments → Pay With JazzCash. Enable it and enter the merchant **sandbox** ID, password and integrity salt securely.
5. Keep the separate JazzCash environment set to **Sandbox**, use PKR, and serve checkout over HTTPS. Selecting Live makes this gateway unavailable.
6. Configure the merchant portal:

| Portal field | Value |
| --- | --- |
| Return URL | `https://YOUR-STORE/wc-api/result/` |
| IPN URL | `https://YOUR-STORE/jazzcash_ipn_v2` |
| IPN protocol | REST |

Include the store's actual subdirectory if applicable. Use one integration for these callback routes. Preserve already-working portal configuration. Provider approval and credentials are arranged separately with JazzCash.

7. Confirm routing: a GET to the return route should show a neutral response; GET to the IPN route should return HTTP 405, not a server 404.
8. Apply the [server/proxy/cache configuration](docs/HOSTING.md), then verify successful, failed, pending, repeated and interrupted sandbox payments against the provider portal, WooCommerce order and ledger.

When replacing an earlier 1.0.0 package, manually replace the installed plugin and purge browser/static-asset, page-cache and CDN caches. The deliberately unchanged version number does not trigger a version-increment update. If an older save altered a password containing literal percent sequences or angle brackets, re-enter the original credential securely.

## Payment and return behavior

A hosted-link request goes to the fixed JazzCash HTTPS API. Provider TLS verification stays enabled; automatic redirects and oversized responses are rejected. A signed return must match the gateway, sandbox environment, current amount/currency, merchant, request reference and durable attempt. Sandbox codes `000`/`121` are treated as success; the approved production contract must confirm production semantics.

IPN V1 authentication alone never marks an order paid. Exact signed replays do not repeat settlement; unsigned noise or hash casing cannot create a new event. Late failures do not downgrade paid orders, and another attempt cannot be recorded as completed for an already-paid order with a different transaction ID. Unsupported/busy/lost database locks fail closed.

A provider signature does not authorize disclosure of a private order URL. The owning account or checkout session must also match. A signed ten-minute same-site GET continuation can restore cookies omitted by a cross-site POST; the ticket alone does not reveal the order key. Unrecognized browsers receive a neutral response.

## Transactions, privacy and retention

WooCommerce → CardRaabta Transactions is restricted to WooCommerce managers. CSV export also requires a dedicated nonce. Exports neutralize untrusted spreadsheet formulas and preserve fixed three-digit response codes. Details come only from allowlisted verified fields; missing authorization/settlement values are explicitly unavailable.

The ledger and settings are retained after order deletion/uninstall. Merchants must define retention, protect exports/backups and handle privacy requests. No automatic retention scheduler or dedicated privacy eraser/exporter is included. Provider response messages can contain identifiers and should be redacted before sharing.

Passwords/integrity salts use authenticated encryption derived from WordPress salts. Back up the database and matching site configuration together; re-enter credentials after salt changes. Logs use allowlisted diagnostic shapes and omit raw secrets, secure hashes and hosted links. See [SECURITY.md](SECURITY.md) and the privacy/service sections of [readme.txt](readme.txt).

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Gateway absent | Sandbox mode, enabled setting, merchant credentials, PKR, recognized HTTPS and WooCommerce setup |
| Public checkout hidden | WooCommerce storefront visibility / Coming soon mode |
| Callback 404 | WordPress front-controller rewrites and permalinks; explicit OpenLiteSpeed virtual-host rules may be needed |
| Busy or unverified response | Provider/order/ledger reconciliation before another customer payment; do not disable signature/lock checks |
| Missing provider details | Only values actually saved from verified responses can be shown |
| Credential failure after restore | Matching WordPress salts/database and the original merchant credentials |
| Stale layout or payment response | Correct cache exclusions and purge browser/CDN/page caches after replacement |

## Development, verification and release integrity

Clone the source for development. The WordPress.org upload ZIP includes only runtime files, `readme.txt`, canonical `LICENSE` and `NOTICE.txt`. README, CONTRIBUTING, SECURITY, the separate CHANGELOG and `docs/` remain in the source repository; `.github`, test harnesses, development tools and nested release archives are excluded.

```sh
CRJ_TESTING=1 WP_PATH=/absolute/disposable/wordpress bash scripts/test-wordpress.sh
python3 scripts/build-release.py releases/1.0.0
python3 scripts/verify-release.py releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip
```

Never run integration tests on a merchant database: they create orders and replace gateway settings. See [CONTRIBUTING.md](CONTRIBUTING.md) for the contributor workflow and [docs/RELEASING.md](docs/RELEASING.md) for packaging/tag instructions.

The tracked ZIP, its SHA-256 sidecar, the downloadable release and the local WordPress.org candidate must be identical. The verifier also compares every ZIP entry byte for byte with the source. GitHub's generic source ZIP is a different artifact and is not the uploadable plugin package.

WordPress Plugin Check, behavior tests, JavaScript CodeQL and Gitleaks provide bounded checks. No automated result establishes absence of every vulnerability or WordPress.org acceptance. WordPress.org submission and human approval remain separate from preparing this candidate.

## Contribution, license and independence

Bug fixes, tests, accessibility improvements and documentation contributions are welcome under [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md), never through a public issue containing secrets/customer data.

Copyright © 2026 Malik Arslan and contributors. Project license: **GPL-2.0-or-later**; the canonical GNU GPL version 2 text is in [LICENSE](LICENSE), with the project's “or later” grant explained in [NOTICE.txt](NOTICE.txt). No third-party runtime libraries are bundled in the plugin. JazzCash, WordPress and WooCommerce names/trademarks belong to their respective owners; this independent plugin is not affiliated with or endorsed by those projects/services.
