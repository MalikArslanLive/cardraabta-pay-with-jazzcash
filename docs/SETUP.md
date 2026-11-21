# Merchant setup and updates

## Before installing

Use a WordPress version supported by your WooCommerce release, PHP with OpenSSL and iconv, a PKR store and checkout over HTTPS. Use MySQL or MariaDB and check the [hosting guide](HOSTING.md) before enabling database replication, object caching or a CDN. JazzCash supplies credentials for each environment. Ask JazzCash which payment methods, hosted-link version, history and refund features your merchant account is provisioned for.

## Configure WooCommerce and JazzCash

1. Back up the site database, plugin files and matching WordPress site salts.
2. Install the CardRaabta ZIP via **Plugins → Add New Plugin → Upload Plugin** and activate it.
3. Open **WooCommerce → Settings → Payments → Pay With JazzCash**. Choose Sandbox for test credentials or Live for production. Enter the merchant ID, password and integrity salt for the selected environment. Select the provisioned hosted-link API version, normally 1.1 or 2.0.
4. Enter the Return URL and IPN URL below in your JazzCash portal. Use the public HTTPS store URL, including its subdirectory when applicable.

| JazzCash portal field | Store value |
| --- | --- |
| Return URL | `https://your-store.example/wc-api/result/` |
| IPN URL | `https://your-store.example/jazzcash_ipn_v2` |
| IPN protocol | REST |

5. Confirm the return URL shows a neutral message on GET and the IPN rejects GET with HTTP 405. Apply the [cache exclusions](HOSTING.md).
6. Enable the gateway. Move through checkout with a controlled transaction and compare the WooCommerce status with the exact provider reference in the merchant portal. Confirm the merchant has authorized test funds and any real refund before using these operations.

## Updates and credentials

Use the installable ZIP and its published SHA-256. Back up the site before replacement. For a replacement build with the same version number, use WordPress's upload-and-replace flow and purge checkout assets and page caches. Keep the selected environment and callback routes consistent. Both credential sets stay in their existing option when the plugin is replaced. Changing WordPress salts invalidates the keys used to decrypt the passwords and integrity salts. Enter the values again after a salt rotation or restore the matching database and site configuration together.

Disable the WooCommerce gateway to suspend new customer payments. Managers can still view history and existing return or IPN events can still be processed while the plugin is active. Coordinate with JazzCash before deactivating the plugin if payments are in flight.
