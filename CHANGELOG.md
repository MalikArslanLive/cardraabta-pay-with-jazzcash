# Changelog

## 1.0.0

Stable version of the implemented sandbox integration. Production API/refunds remain disabled pending approved provider documentation and merchant end-to-end verification.

- Hosted sandbox payment-link creation with fixed HTTPS destination, verified TLS, bounded responses and safe hosted-link validation.
- Signed callback reconciliation against gateway/environment, current order amount/currency, merchant, reference and durable attempt.
- Replay protection based on authenticated fields; no duplicate settlement, late paid-order downgrade or completion of a different paid attempt.
- Real database connection locks, held-lock ownership checks and fail-closed unsupported/busy/lost-lock handling.
- Diagnostic/authenticated IPN without settlement from insufficient V1 notification data.
- Owner/session-bound private returns and signed same-site continuation tickets.
- AES-GCM credential storage; literal passwords preserved and omitted/malformed inputs handled safely.
- Independent transaction history, verified provider details, manager-only nonce-protected CSV and formula neutralization.
- Classic checkout, Checkout Blocks and HPOS support.
- Callback no-store/LiteSpeed cache controls and documented proxy/routing/cache requirements.
- Responsive transaction layouts, keyboard focus/labels and accessibility contrast improvements.
- WordPress.org upload package contains only runtime files, readme.txt and license/notice files; full contributor/security/release documentation and verification snapshots remain in the source repository.
- Reproducible archive manifest, byte-for-byte source/Git/package verification and SHA-256 sidecar.
- Regression/security workflows and the published 230-release compatibility inventory.
