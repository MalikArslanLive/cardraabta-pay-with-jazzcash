# CardRaabta – Pay with JazzCash for WooCommerce

[![Release 1.0.0](https://img.shields.io/badge/release-v1.0.0-007ec6)](CHANGELOG.md)
[![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-2271b1?logo=wordpress)](docs/SETUP.md)
[![WooCommerce 8.3+](https://img.shields.io/badge/WooCommerce-8.3%2B-96588a)](docs/SETUP.md)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)](docs/SETUP.md)
[![License GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-4c9021)](LICENSE)
[![Installable ZIP](https://img.shields.io/badge/download-installable%20ZIP-166a3c)](releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip)
[![Checksum](https://img.shields.io/badge/checksum-SHA--256-555)](releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip.sha256)

[![Plugin checks](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/ci.yml/badge.svg)](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/ci.yml)
[![Security scanning](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/security.yml/badge.svg)](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/security.yml)
[![Compatibility](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/compatibility.yml/badge.svg)](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/actions/workflows/compatibility.yml)
[![Open issues](https://img.shields.io/github/issues/MalikArslanLive/cardraabta-pay-with-jazzcash)](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/issues)
[![GitHub stars](https://img.shields.io/github/stars/MalikArslanLive/cardraabta-pay-with-jazzcash?style=social)](https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash/stargazers)

CardRaabta adds JazzCash hosted checkout to WooCommerce for merchants in Pakistan. Customers pay on JazzCash and return to WooCommerce for a verified receipt, retry or pending status. Store managers can view JazzCash transactions and submit eligible refunds from a single page.

| Start here | Guide |
| --- | --- |
| Install, configure and upgrade | [Merchant setup](docs/SETUP.md) |
| Understand checkout and payment verification | [Payment workflow](docs/PAYMENTS.md) |
| Search history, interpret statuses and issue refunds | [Transaction and refund guide](docs/TRANSACTIONS.md) |
| Set up server rewrites, proxies and caching | [Hosting guide](docs/HOSTING.md) |
| Review testing scope and results | [Testing](docs/TESTING.md) · [Verification](docs/VERIFICATION.md) |
| Develop, contribute or report a security issue | [Implementation guide](docs/ARCHITECTURE.md) · [Contributing](CONTRIBUTING.md) · [Security](SECURITY.md) |
| Build or publish a release | [Release guide](docs/RELEASING.md) · [Changelog](CHANGELOG.md) |
| Prepare a WordPress.org submission | [Directory readme](readme.txt) · [Release checklist](docs/RELEASING.md) |

## Requirements

WordPress 6.5+, WooCommerce 8.3+, PHP 8.1+ with OpenSSL and iconv, HTTPS, PKR and MySQL or MariaDB with connection-scoped named locks. Meet the WordPress and PHP requirements of your installed WooCommerce version. Live checkout, history and refunds depend on what JazzCash has provisioned for your merchant account. See the verification guide for the environments exercised on the candidate.

## Quick setup

1. Download and install the [installable plugin ZIP](releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip). WooCommerce must be active.
2. Open **WooCommerce → Settings → Payments → Pay With JazzCash**. Choose an environment, enter its merchant ID, password and integrity salt, and choose the provider-assigned API version. The default is **1.1**.
3. Set the JazzCash portal **Return URL** to your store's `/wc-api/result/` and **IPN URL** to `/jazzcash_ipn_v2`. Select **REST** as the IPN protocol.
4. Verify the intended payment methods and final order states with the merchant account before opening checkout.

The [SHA-256 checksum](releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip.sha256) is available beside the ZIP.

## Responsibility and support

CardRaabta is independently maintained by [Malik Arslan](https://malikarslan.com/). JazzCash controls the hosted payment page, wallet prompt, merchant provisioning and payment decisions. For a store issue, include the versions, order outcome, sanitized response code and steps to reproduce. Use the [private security instructions](SECURITY.md) for sensitive reports.

The project is GPL-2.0-or-later. See [LICENSE](LICENSE) and [NOTICE.txt](NOTICE.txt) for terms and attribution.
