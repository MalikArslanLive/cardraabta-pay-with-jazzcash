=== CardRaabta – Pay with JazzCash for WooCommerce ===
Contributors: malikarslanlive
Tags: woocommerce, payment gateway, jazzcash
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

JazzCash hosted sandbox payments for WooCommerce, signed returns, transaction history and CSV export.

== Description ==
Version 1.0.0 integrates the JazzCash hosted sandbox workflow. Live payments and refunds are disabled; do not use this version to collect real customer payments. Production API verification is pending. CardRaabta is independent of JazzCash and is not an official JazzCash plugin.

= Features =
* One Pay With JazzCash checkout choice; actual methods are supplied by JazzCash's hosted page.
* Separate sandbox/live credential fields; live transactions remain disabled.
* Sandbox hosted-link creation through the established fixed HTTPS API endpoint.
* Signed return verification and request/provider reference reconciliation.
* REST IPN V1 authentication and diagnostics. IPN alone never completes payment.
* Independent transaction ledger, search, provider details and CSV export after order deletion.
* Leading-zero response codes retained in Excel using a fixed numeric-only text expression.
* Transaction toolbar layout, Checkout Block integration and HPOS compatibility.
* Refund placeholder remains disabled pending a verified Orchestrator refund contract.

= Security controls =
* Order/ledger connection locks and fail-closed unsupported locking.
* Amount matching against the current order AND durable attempt, plus gateway/environment/merchant/currency/reference checks.
* Duplicate callback fingerprints prevent repeated writes and order-note spam.
* Ledger remains PROCESSING until actual WooCommerce completion is confirmed.
* Duplicate JSON/form-key rejection, bounded/scalar parsing and limited API responses.
* Signed provider callbacks do not disclose private order keys without customer/session authorization.
* Authenticated encryption of stored password/salt options using site configuration salts.
* Prepared custom-table SQL, late escaping and corrected filtered CSV keyset pagination.
* Locally enqueued admin assets and activation-time rewrite initialization.

= Response codes =
Official JazzCash Resources documentation maps 000 to successful and 121, merchant-confirmed, to completed. Other codes do not automatically complete orders. Validate the latest Orchestrator contract with JazzCash before production use.

= Requirements =
WordPress 6.5+, WooCommerce 8.3+, PHP 8.1+ with OpenSSL, HTTPS, PKR and MySQL/MariaDB named locks. SQLite is not supported for production payment handling. Sandbox credentials and merchant approval for hosted methods are arranged separately with JazzCash.

== Installation ==
1. Back up the test database/files and wp-config.php.
2. Upload the ZIP through Plugins > Add New Plugin > Upload Plugin and activate CardRaabta – Pay with JazzCash for WooCommerce.
3. Activate and configure WooCommerce > Settings > Payments > Pay With JazzCash. Keep Sandbox selected.
4. Confirm PKR/HTTPS and merchant credentials.
5. Retain /wc-api/result/ as Return URL and /jazzcash_ipn_v2 as IPN URL; select REST for IPN. Do not change working portal values unnecessarily.
6. Test successful, unsuccessful, pending and repeated responses against the provider portal, WooCommerce order and ledger.
7. A callback without owner/private session authorization shows a neutral response. Use the original order link or owning customer account to view private details.

== Privacy and backups ==
The plugin sends merchant/payment request fields to JazzCash's fixed sandbox hosted-link API. Payment entry occurs on JazzCash's page. This plugin has no PAN/CVV, OTP or wallet MPIN input fields. Allowlisted signed transaction details and diagnostic shapes are stored locally; raw passwords/hashes/payloads and hosted links are not intentionally logged.

Passwords and integrity salts are authenticated encrypted in options using keys derived from wp-config.php salts. Preserve matching site configuration and database backups. After changing site salts, re-enter merchant credentials. Protect database and configuration backups.

Records/settings are retained; the merchant defines retention, protects exports/backups and handles privacy requests. No dedicated privacy exporter/eraser or automatic retention scheduler is included. Provider response text may contain identifiers and should be redacted before sharing.

== Frequently Asked Questions ==
= Does version 1.0.0 collect real payments? =
No. This release supports the sandbox workflow only. Live payments and refunds remain disabled until the approved production API contract and merchant end-to-end tests are verified. The stable version number does not enable live payments. WordPress.org acceptance requires its separate human review and a complete submission; automated checks alone do not establish acceptance.

= How does the customer return to the order page? =
A verified browser return uses the owning account or checkout-session binding to redirect to WooCommerce's order-received page. If a cross-site POST omitted session cookies, a signed ten-minute same-site GET continuation restores them before checking ownership. The continuation ticket alone never reveals the order key. Unrecognized browsers receive a neutral message and can use their original order link or owner account.

= What is included in transaction details and CSV? =
The main transaction fields plus signed response message, provider transaction date/time, settlement date/expiry, retrieval reference, authorization code, bank, merchant/submerchant, product, API version and language. Each datum has one CSV column. Filename: cardraabta-pay-with-jazzcash-transactions-YYYY-MM-DD.csv. Response codes retain leading zeros in Excel; untrusted formula prefixes are escaped.

= Why is an authorization code or settlement field unavailable? =
Optional values are displayed when saved from a verified response. Missing fields show "Not available in verified response". Later callbacks preserve previously recorded nonempty details for the exact same payment attempt. The plugin cannot recreate values never saved or manufacture an authorization code. Shape-only diagnostics identify whether future signed returns supplied authorization/settlement fields; raw credentials and payloads are not logged.

= Why does IPN not mark paid? =
The observed REST IPN V1 shape lacks sufficient amount/currency confirmation. Authentication alone is insufficient for settlement.

= What happens to duplicates or concurrent requests? =
Exact signed duplicates are ignored. Busy callbacks receive a retryable error. Paid orders are not downgraded by late failed responses. Reconcile before initiating another customer payment.

= Can Live mode or Refund be enabled? =
Not in version 1.0.0. These remain disabled until the provider contract and production integration are verified.

== External service ==
The service is contacted only when a customer starts payment. Requests include merchant ID/password, signed hash, amount/currency, generated transaction and order references, timestamps, order description and store return URL. The plugin does not send customer card numbers, CVV, PIN or OTP. JazzCash controls its hosted payment page and its data processing.

Hosted-link API: https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/transactions/link
Provider website/merchant support: https://www.jazzcash.com.pk/
Provider privacy: https://www.jazzcash.com.pk/privacy-policy/
Merchants must follow their current JazzCash agreement and integration documentation.

== Changelog ==
= 1.0.0 =
* Stable 1.0.0 version of CardRaabta – Pay with JazzCash for WooCommerce.
* Hosted-link creation, signed callbacks, transaction history and CSV export.
* Encrypted credentials, bounded parsing, replay protection and order/amount matching.
* Checkout Blocks and HPOS support. Live payments and refunds remain disabled pending provider validation.
* Reject unsigned provider field aliases and case-ambiguous callbacks.
* Prevent another attempt from being recorded as completed after an order is paid.
* Avoid schema updates on every page request; use versioned initialization.
* Add repeatable integration checks, release packaging and GitHub security workflows.
