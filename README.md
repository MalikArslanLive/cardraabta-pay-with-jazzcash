# CardRaabta – Pay with JazzCash for WooCommerce

Stable version **1.0.0**. This version supports the **sandbox payment workflow**; live payments and refunds remain disabled pending approved production API verification. Do not collect real customer payments with this release.

Independent software by [Malik Arslan](https://malikarslan.com/), not affiliated with or endorsed by JazzCash or WooCommerce. GPL-2.0-or-later; see LICENSE.

## Installation

Requirements: WordPress 6.5+, WooCommerce 8.3+, PHP 8.1+ with OpenSSL, HTTPS, PKR, and MySQL/MariaDB named locks. SQLite cannot process payments because locks fail closed.

1. Install and activate WooCommerce.
2. Upload `cardraabta-pay-with-jazzcash-1.0.0.zip` through Plugins → Add New Plugin → Upload Plugin; activate it.
3. Open WooCommerce → Settings → Payments → Pay With JazzCash. Enable the gateway and enter your **sandbox** merchant ID, password and integrity salt.
4. Keep Sandbox selected, use PKR, and serve checkout over HTTPS.
5. Set the JazzCash portal Return URL to `https://YOUR-STORE/wc-api/result/`, IPN URL to `https://YOUR-STORE/jazzcash_ipn_v2`, and IPN Protocol to REST. Use one active integration for these routes.
6. Verify success, failure, pending, duplicate and interrupted payments against the provider portal, WooCommerce order and transaction ledger before any deployment.

Live credential fields are retained for compatibility, but selecting Live makes the gateway unavailable. The version number does not change this restriction. Production contract and real merchant end-to-end tests are pending.

## Payment behavior

The plugin requests a hosted payment link from the fixed JazzCash HTTPS API. Customers enter payment details on JazzCash's page; this plugin does not collect PAN/CVV, wallet PIN or OTP. Available hosted methods depend on the merchant/provider configuration.

A signed return must match the order gateway, sandbox environment, current amount, merchant, currency, request reference and durable attempt. Codes `000`/`121` are treated as success in this sandbox implementation; the production contract must confirm their meaning. IPN V1 authentication alone never marks an order paid. Replays are idempotent and financial mutations use real database connection locks. A second attempt cannot be recorded as completed for an already-paid order with another transaction ID.

Returns disclose private order links only to the owning account or checkout session. A signed, ten-minute same-site GET continuation can restore cookies omitted by cross-site POST; the ticket alone does not authorize disclosure.

## Transactions, privacy and backups

WooCommerce → CardRaabta Transactions provides search, verified provider details and CSV export to WooCommerce managers. Exports require a nonce and escape untrusted spreadsheet formulas. Response codes retain leading zeros. Unavailable provider fields are explicitly marked; the plugin never invents authorization codes or settlement data.

The ledger survives order deletion. Settings and ledger records are retained after uninstall; merchants must define retention, protect exports/backups and handle privacy requests. No automatic eraser or retention scheduler is included. Provider response text may contain identifiers.

Passwords and integrity salts are AES-256-GCM encrypted using keys derived from WordPress salts. Back up `wp-config.php` and the database together. Re-enter credentials after changing salts. Logs contain allowlisted diagnostic shapes rather than raw credentials, payloads or hosted links. See [SECURITY.md](SECURITY.md) and [readme.txt](readme.txt) for service and privacy details.

## Development and release

Use the existing isolated checkout; no additional worktree is required. Run checks only against a disposable WordPress/WooCommerce database:

```sh
CRJ_TESTING=1 wp eval-file wp-content/plugins/cardraabta-pay-with-jazzcash/tests/integration.php
python3 scripts/build-release.py dist
```

Tests create orders and synthetic ledger entries; never run them on a merchant store. GitHub Actions runs PHP lint, real database integration tests, WordPress Plugin Check against the packaged ZIP, JavaScript syntax checks and dependency review. CodeQL scans PHP-independent JavaScript security paths; PHP payment behavior is covered by Plugin Check and regression tests. See [docs/TESTING.md](docs/TESTING.md) for the matrix, commands and limits.

The ZIP contains only runtime files, license and user documentation. CI/tests/development files and credentials are excluded. Its SHA-256 file accompanies the release. WordPress.org approval is a separate human review; this sandbox-only implementation still needs the production contract before it can serve as a complete live payment gateway.
