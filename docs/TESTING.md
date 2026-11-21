# Testing CardRaabta

Run payment tests against a disposable store containing synthetic accounts and credentials. The automated suite mocks JazzCash and never requests a real charge or refund. Test real merchant provisioning separately with merchant authorization.

## Local validation

Install WordPress and WooCommerce over a disposable MySQL or MariaDB database, activate CardRaabta, and install WP-CLI.

```sh
CRJ_TESTING=1 WP_PATH=/path/to/disposable/wordpress bash scripts/test-wordpress.sh
python3 scripts/build-release.py dist
python3 scripts/verify-release.py dist/cardraabta-pay-with-jazzcash-1.0.0.zip
```

Set the site's language and rewrites normally. Serve the fixture with a front controller and run `tests/http-smoke.py` against localhost. Run `tests/browser.cjs` with Playwright; set `CRJ_AXE=1` for the automated accessibility check of the transaction panel. Run `tests/payment-ui.cjs` for the isolated refund dialog. The background merchant-history order transitions have a separate fixture in `tests/wallet-history.php`, run through WP-CLI on the same disposable setup. Install WordPress Plugin Check in the disposable site and check the extracted distribution. Use the report parser to avoid passing a CLI run that actually contains warnings.

```sh
wp plugin check /absolute/path/to/extracted/cardraabta-pay-with-jazzcash --format=json > plugin-check.txt
python3 scripts/check-plugin-report.py plugin-check.txt
```

## What the GitHub workflows exercise

| Workflow | Coverage |
| --- | --- |
| Plugin checks | PHP 8.1 through 8.5, MySQL and MariaDB, HPOS and legacy orders, with and without Redis caching. The minimum-version cell uses WordPress 6.5 and WooCommerce 8.3.0. Selected jobs exercise callback HTTP routes, Chromium viewport checks, axe and WordPress Plugin Check. Windows and macOS jobs check syntax. |
| Every stable release | Each version in `tests/compatibility/versions.json` with a compatible counterpart. Shard results are aggregated and checked for missing or failing targets. |
| Security scanning | CodeQL for JavaScript, Gitleaks, and pull-request dependency review where enabled. PHP behavior is covered by the database suite and WordPress Plugin Check. |

These definitions describe planned coverage. The [verification record](VERIFICATION.md) states the environments and checks run for the current candidate. Individual public workflow badges reflect GitHub's recorded status.

## Merchant acceptance check

In an authorized test, confirm card approval and decline, Mobile Wallet approval and rejection, missing phone prompts, an ambiguous return followed by final confirmation, duplicate redirects and a verified retry on the original order. Compare the exact JazzCash transaction reference with the order. Confirm the merchant's refund access separately and review full and partial refunds, provider rejection and uncertain-outcome reconciliation. Keep account secrets and customer data out of test logs and public reports.
