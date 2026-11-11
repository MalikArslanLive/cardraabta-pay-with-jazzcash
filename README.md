# CardRaabta – Pay with JazzCash for WooCommerce

Version **1.0.0-beta.1** — initial sandbox beta. **Live payments and refunds are disabled.**

Slug and installation folder: `cardraabta-pay-with-jazzcash`.
Main file: `cardraabta-pay-with-jazzcash.php`.

This independent WooCommerce integration creates a JazzCash-hosted sandbox payment link, verifies signed returns against the order and durable transaction ledger, and provides transaction history, search, provider details and CSV export. Customers enter payment details on the provider’s hosted page. The plugin has no card PAN/CVV, wallet PIN or OTP input fields.

## Setup

1. Install WooCommerce and upload the plugin ZIP through **Plugins → Add New Plugin → Upload Plugin**.
2. Activate **CardRaabta – Pay with JazzCash for WooCommerce**.
3. Configure **WooCommerce → Settings → Payments → Pay With JazzCash** with merchant sandbox credentials. Keep Sandbox selected.
4. Use PKR and HTTPS. Configure `/wc-api/result/` as the return path and `/jazzcash_ipn_v2` as the REST IPN path.
5. Test successful, unsuccessful, pending and repeated responses against the provider portal, WooCommerce order and transaction ledger.

Use one active integration for these callback endpoints. Merchant approval and credentials are arranged separately with JazzCash. Requirements: WordPress 6.5+, WooCommerce 8.3+, PHP 8.1+ with OpenSSL, and MySQL/MariaDB named locks.

## Payment and security behavior

The gateway identifier is `crj_card`. The fixed sandbox HTTPS endpoint, callback routes, order metadata, receiving settings and ledger identifiers bind the payment workflow. Signed callbacks validate the order, current total, durable attempt, environment, merchant, currency and reference. Duplicate events are idempotent; financial mutations require connection locks. Ledger completion requires an actually paid WooCommerce order. REST IPN V1 authentication alone does not complete payment.

Credentials use authenticated encryption derived from site configuration salts. Back up wp-config.php and the database together; re-enter credentials after salt changes. Diagnostic logs avoid raw secrets, signed hashes and private hosted links. Signed-return diagnostics record only whether an authorization code and settlement date were present, not their values. Protect exports, backups and administrator accounts. See `readme.txt` and `SECURITY.md` for details.

## Return page and transaction details

A verified browser return redirects to WooCommerce's order-received page only after the customer account or checkout session is authorized. A signed, ten-minute same-site GET continuation restores cookies that a cross-site POST may omit; the ticket alone cannot reveal the order key. Unrecognized browsers receive a neutral response and can use their original order link or owner account.

View Details and CSV expose the same allowlisted transaction fields. Authorization code, retrieval reference (both provider spellings), response message, settlement date/expiry, bank, merchant/submerchant, product, API version and language are retained when supplied in a verified response. Later responses with omitted or empty optional fields preserve existing details for that exact payment attempt. Missing values display **Not available in verified response**; the plugin never manufactures bank authorization codes. Historical values that were never saved cannot be reconstructed from a screenshot.

CSV downloads use `cardraabta-pay-with-jazzcash-transactions-YYYY-MM-DD.csv`. Each column represents one datum: the main table and detail fields are combined without repeating transaction ID, response code, type or merchant reference. Response codes keep leading zeros in Excel; untrusted formula prefixes are escaped. Secrets, hashes, card details, wallet PINs, OTPs and arbitrary callback fields are excluded.

## Testing and release status

Automated callback, parsing, mocked provider/API, encryption, export and return-handler tests cover the sandbox workflow. Local SQLite tests use an explicit lock shim and do not establish real database concurrency or provider interoperability.

Merchant sandbox end-to-end verification and a verified production integration are still required. This beta is for internal testing and is not a production or WordPress.org submission package. A new name or slug does not establish live verification. No real customer payment is performed by automated tests.

Developed by [Malik Arslan](https://malikarslan.com/). Independent software, not affiliated with or endorsed by JazzCash or WooCommerce. GPL-2.0-or-later; see LICENSE.
