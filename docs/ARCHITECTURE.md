# Implementation guide

## Files

| File | Responsibility |
| --- | --- |
| `cardraabta-pay-with-jazzcash.php` | Gateway registration, settings, hosted-link initiation, signed browser/IPN handling, financial order updates, HPOS declaration and transaction ledger lifecycle. |
| `includes/security.php` | Bounded callback parsing, named database locks, encrypted credential options, return-ticket and ownership checks, safe provider details and ledger exports. |
| `includes/provider.php` | Authenticated provider history and refund requests, manager UI, refund state, wallet history matching and customer payment notices. |
| `blocks.js`, `assets/checkout.css` | Checkout Blocks presentation and styles shared with the classic checkout. |
| `assets/admin.css`, `assets/settings.js`, `assets/refund.js` | Scoped WooCommerce admin styles, environment-aware settings and refund dialog. |
| `tests/`, `scripts/`, `.github/workflows/` | Disposable test fixtures, installable build and verification tools, and CI profiles. |

## Data and ownership

The gateway ID is `crj_card`. WooCommerce options retain separate settings for Sandbox and Live, with a shared hosted-link version selector. Secret values are stored in authenticated encrypted form. The custom transaction ledger uses the current WordPress table prefix and stores verified response details for reconciliation. It does not depend on the continued existence of the WooCommerce order.

The response signature alone establishes the provider, never the identity of the viewing browser. The protected continuation additionally checks the order owner or bound checkout session. WordPress Cron checks authenticated merchant history for unresolved Live wallet returns. Only a matching result can settle or fail the attempt.

## External request contracts

| Operation | Encoding and verification |
| --- | --- |
| Hosted checkout | Form-encoded POST to a fixed HTTPS endpoint. Requests use sorted nonempty JazzCash fields and configured HMAC credentials. Hosted links are restricted to the expected TLS host. |
| History | JSON POST with Live merchant credentials and request signature. The provider controls returned page membership and status values. |
| Refund | JSON POST with exact provider transaction reference, PKR and amount in paisa. Before dispatch the plugin validates authorization, transaction or order state, and records in-flight state. The reported provider result determines whether local reconciliation can proceed. |

Treat account settings, raw callbacks, hosted links and private order URLs as sensitive. See [Security policy](../SECURITY.md) for deployment boundaries and private reports.
