# Security policy

## Supported scope

The **1.0.0** candidate supports Sandbox and Live checkout, provider history and eligible Live refunds. Merchant end-to-end verification remains pending.

Use currently security-patched WordPress, WooCommerce, PHP and database releases. See docs/TESTING.md for exact coverage and docs/HOSTING.md for required configuration.

## Report a vulnerability privately

Use the repository's Security → Report a vulnerability feature if available, or contact the author through https://malikarslan.com/ to arrange a private channel. Do not put the vulnerability details in a public issue or pull request until coordinated disclosure is agreed.

Include the affected version/environment, minimal reproduction using synthetic data, expected/actual behavior, impact and a proposed mitigation if known. Do not send real merchant passwords, integrity salts, customer/card data, private order links, raw signed payloads, hosted links or database dumps. Redact identifiers in screenshots/logs. No fixed response deadline or bug bounty is promised here.

If credentials are exposed, rotate them through the provider, restrict affected access and preserve safe evidence. Avoid repeated payment attempts until provider/order/ledger reconciliation is complete.

## Security boundaries and controls

| Area | Control |
| --- | --- |
| Callback authenticity | HMAC verification, bounded/scalar parsing, duplicate/case-alias rejection and authenticated-field replay fingerprints |
| Settlement | Gateway/environment/merchant/current amount/current currency/reference/durable-attempt checks; actual WooCommerce completion required |
| Concurrency | Real connection-scoped named locks; held lock ownership rechecked; busy/unsupported/lost locks fail closed |
| Replays and status | Duplicate events ignored, late failures cannot downgrade paid orders, different attempts cannot mark an already-paid order complete |
| Private returns | Provider signature plus independent owner/session authorization; signed continuation alone does not reveal an order key |
| IPN and Live history | Complete signed successful IPNs can settle a matching order. The wallet waiting flow can use an authenticated Live history result with matching merchant, amount, currency and exact attempt. Incomplete results stay pending. |
| Secrets | Literal merchant-password preservation and AES-256-GCM storage keyed from WordPress salts |
| Administration | WooCommerce-manager capability, transaction-bound refund nonces, export nonce, prepared custom-table SQL and escaped output |
| Refunds | Recorded dispatch state, order and provider-reference locks, amount bounds, and manual reconciliation for uncertain outcomes |
| CSV | Untrusted formula prefixes neutralized; only fixed numeric three- and four-digit response-code expressions are permitted |
| Transport | Fixed provider HTTPS destination, verified TLS, no automatic redirects, bounded responses and hosted-link allowlisting |
| Cache/logs | Private/no-store callback headers, DONOTCACHEPAGE/LiteSpeed signal, diagnostic allowlists and no raw secret/hash/hosted-link logging |

Passwords are necessarily available in process memory when the integration uses them. Encryption does not protect a fully compromised administrator, database plus site-salt backup, PHP process or server. Protect matching configuration/database backups and re-enter credentials after changing WordPress salts. The plugin does not collect PAN/CVV, wallet PIN or OTP; the provider controls its hosted page.

## Required operational controls

- Serve checkout/callbacks over correctly recognized HTTPS and trust proxy headers only from trusted proxies. Keep TLS verification enabled.
- Bypass page/CDN cache lookup and storage for callback slash/query variants, checkout/cart/account/order pages, authenticated/session requests, private order URLs and exports. Caches serving before WordPress loads cannot see plugin headers.
- Keep named-lock and ledger queries on one authoritative physical database writer. Replica reads, result caches and transaction-pooling proxies are unsupported unless separately verified.
- Restrict WooCommerce-manager/database access, maintain updates, define ledger/export retention, and protect logs/backups/CSVs.
- Do not infer a refund from an order-status change or an authenticated IPN. Reconcile ambiguous provider transactions before another customer payment.

Settings and ledger records survive order deletion/uninstall. No dedicated personal-data exporter/eraser or automatic retention scheduler is included. Provider response text can contain identifiers; the merchant must handle applicable privacy requests and retention.

## Verification and limitations

The maintained workflow uses real-database behavior tests, WordPress Plugin Check on the exact distributable, extended JavaScript CodeQL and checksum-verified Gitleaks. Dependency review applies to pull requests; it intentionally skips ordinary release pushes. CodeQL does not scan PHP; PHP is assessed through Plugin Check, behavior tests and code review.

CodeQL init/analyze use matching pinned versions, and Dependabot groups Actions updates to avoid partial upgrades. The custom extended scan retains SARIF as an artifact rather than competing with GitHub's default CodeQL upload configuration.

Read [the verification record](docs/VERIFICATION.md) for the current candidate. A clean automated scan cannot prove absence of every vulnerability or certify all third-party plugins, caches, hosts and infrastructure. Private GitHub alert dashboards require their own access; unavailable alert APIs are not evidence that no alerts exist.

Never fix a scan by removing assertions, disabling TLS/checksum verification, suppressing broad check categories or enabling unverified live processing. Follow CONTRIBUTING.md and coordinate disclosure before publishing exploit details.
