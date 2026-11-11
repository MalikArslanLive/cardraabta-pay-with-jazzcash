# Contributing to CardRaabta

Thanks for helping improve CardRaabta – Pay with JazzCash for WooCommerce. Contributions may address correctness, regression coverage, accessibility, documentation and maintainability. The current stable version is **1.0.0**, with a sandbox-only payment scope; production API/refunds remain disabled pending provider verification.

## Before opening an issue

Read README.md, readme.txt and docs/HOSTING.md. For a reproducible non-security bug, include the plugin/WordPress/WooCommerce/PHP/database versions, HPOS/cache mode, server, expected versus actual result and minimal steps using synthetic data. Explain whether the problem occurs with unrelated plugins disabled on a disposable test site.

Never publish merchant passwords, integrity salts, raw signed callbacks, customer data, private order URLs, payment links, database dumps or credentials. Suspected vulnerabilities belong in the private reporting process in SECURITY.md. Redact screenshots and logs before sharing. Do not make real customer payments as part of a public reproduction.

## Development environment

Use a fork or a branch from current main and preserve existing changes. In an isolated cloud task, use its existing checkout; an additional Git worktree is not required.

You need PHP 8.1+ with OpenSSL/mysqli, WP-CLI, Python 3, Git and a disposable WordPress/WooCommerce installation backed by MySQL or MariaDB. Node/Playwright are needed for browser checks. The project has no bundled Composer/npm runtime dependencies and does not require a front-end compilation step.

Keep credentials in local/environment configuration outside Git. Use synthetic credentials for mocked tests, HTTPS/PKR for the relevant checkout checks and real database named locks. SQLite and a mocked lock shim cannot establish payment readiness. See docs/TESTING.md and the GitHub workflow definitions for the pinned test tools and full matrix.

The installable WordPress ZIP contains only runtime files, readme.txt and license/notice files. It intentionally excludes README.md, CONTRIBUTING.md, SECURITY.md, CHANGELOG.md, docs/, tests/, scripts/ and .github/. Clone the GitHub repository to obtain contributor tooling.

## Change guidelines

- Keep financial changes small and explain the trigger, before/after behavior and tradeoffs.
- Preserve gateway IDs, callback routes, order-meta/settings identifiers, ledger history and compatibility unless an intentional migration is reviewed.
- Never remove signature, ownership, current amount/currency, durable-attempt, replay, TLS, capability, nonce or lock checks just to make a test pass.
- Preserve literal cryptographic credentials; use appropriate WooCommerce password handling and authenticated storage. Escape at output boundaries.
- Use prepared SQL for custom-ledger access. Payment/lock authority must use current database state, not stale cache results.
- Do not log secrets, raw payment payloads or hosted links. Do not add credential/card/PIN/OTP collection to the checkout.
- Keep assets local, scoped to their intended screen, keyboard-accessible and responsive. Explain any new dependency and verify its license.
- Maintain WordPress 6.5/WooCommerce 8.3/PHP 8.1 compatibility within the documented dependency constraints. Do not claim an untested environment works.
- Keep the current release at 1.0.0 unless the maintainer explicitly requests another version. Live mode/refunds need the approved provider contract and end-to-end evidence, not just changed labels.

Follow WordPress PHP conventions and existing prefixes. Document narrowly necessary custom-table/lock operations rather than hiding broad categories of warnings. Never disable assertions, checksum verification or TLS verification.

## Validation

From the repository root, with a configured **disposable** WordPress site:

```sh
CRJ_TESTING=1 WP_PATH=/absolute/disposable/wordpress bash scripts/test-wordpress.sh
python3 scripts/build-release.py dist
python3 scripts/verify-release.py dist/cardraabta-pay-with-jazzcash-1.0.0.zip
```

Run WordPress Plugin Check against the extracted package, then pass its output through `scripts/check-plugin-report.py` so warnings/findings cannot silently pass CI. The full workflow definitions reproduce the 230-release inventory and PHP/database/storage/cache matrix. For meaningful runtime changes, add a failing regression and verify the fix; documentation-only changes need relevant package/link/metadata checks.

For browser checks on a disposable local site, the current CI uses Playwright 1.62.1 and @axe-core/playwright 4.11.1. See tests/browser.cjs and tests/http-smoke.py. The browser test covers transaction details/settings and 320/375/768/1024/1440px layouts; accessibility scope is the plugin transaction panel. Do not present a skipped/zero-test run as a pass.

Provider requests in automated regression tests are mocked and never move funds. Production interoperability requires separate merchant-approved testing.

## Pull requests and review

State the problem, resulting behavior, validation and any remaining limitations. Include exact affected versions and relevant non-sensitive evidence. Update README.md/readme.txt and hosting/security guidance when behavior, requirements, privacy or configuration changes. Keep dependency pins together, especially matching CodeQL init/analyze versions.

Changes should be reviewable from a clean checkout and avoid generated logs, environments, credentials, nested archives or unrelated formatting churn. The maintained installable package belongs only in releases/1.0.0 and must match the source/sidecar checksum. Maintainers rebuild and verify it before tagging.

Do not force-push main, move public tags, rewrite project history, publish releases or deploy a store merely as part of contributing. Those are maintainer actions and need explicit authorization. Branch protection/review requirements still apply where configured.

## License and conduct

By submitting a contribution, you represent that you have the right to contribute it under the project's GPL-2.0-or-later license. Preserve third-party notices and provide original source/license information for any approved addition. No separate proprietary license or contributor license agreement is imposed by this document.

Be respectful, focus on reproducible technical evidence, and protect merchants/customers. Maintainers may request changes or decline contributions that weaken security, misstate live-payment support or cannot be validated.
