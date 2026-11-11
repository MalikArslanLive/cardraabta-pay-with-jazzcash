# Security policy

Version 1.0.0 supports sandbox payments only. Live payments and refunds stay disabled until the approved production contract and merchant end-to-end tests are verified.

## Reporting

Use this repository's **Security → Report a vulnerability** feature if enabled, or contact the author through https://malikarslan.com/ to arrange a private report. Do not open public issues containing customer data, merchant secrets, private order links or raw signed payloads. Include affected version, reproduction steps and impact using synthetic data. Rotate exposed credentials immediately.

## Controls and operational requirements

- Signed returns validate gateway, environment, merchant, currency, current total and durable attempt. Unsigned field aliases and case ambiguity are rejected.
- A provider signature does not authorize disclosure of private order URLs; an owner/session check is also required.
- Replays are idempotent. MySQL/MariaDB connection locks serialize financial mutations and fail closed on unsupported databases.
- Authenticated IPN alone never settles an order. Late failures do not downgrade paid orders and another attempt cannot be recorded as completed for an already-paid order.
- Passwords and integrity salts use AES-256-GCM with keys derived from WordPress configuration salts. Back up matching configuration/database files; re-enter credentials after salt changes.
- Administrative exports require WooCommerce management permission and a CSRF nonce. SQL uses prepared parameters; displayed provider fields are escaped and CSV formulas neutralized.
- Fixed provider HTTPS destinations, verified TLS, no automatic redirects and bounded responses limit transport exposure. Logs omit raw secrets, signed hashes and hosted links.

Restrict administrator/database access, maintain WordPress/WooCommerce/PHP updates, protect backups and exports, and reconcile ambiguous payments with the provider before retrying. No automated test establishes absence of every vulnerability or PCI compliance. Review other plugins and infrastructure separately. See docs/TESTING.md in the GitHub source for executed checks and remaining limits.
