# Hosting and cache configuration for 1.0.0

The plugin supports the JazzCash **sandbox workflow only**. Production API and refund verification remain pending. Use current security-patched WordPress, WooCommerce and PHP releases on a merchant site; historical-version tests are compatibility evidence, not a recommendation to deploy outdated software.

## Required runtime

- WordPress 6.5 or newer, WooCommerce 8.3 or newer, and PHP 8.1–8.5 with OpenSSL. WooCommerce may require a newer WordPress version; meet both plugins' requirements.
- HTTPS for checkout and provider callbacks, a valid certificate chain, outbound verified HTTPS to `onlinepayments.jazzcash.com.pk`, and store/order currency PKR.
- MySQL/MariaDB with working connection-scoped `GET_LOCK`, `IS_USED_LOCK`, `CONNECTION_ID` and `RELEASE_LOCK`. Tests cover MySQL 8.4 and MariaDB 11.4. No custom grant is normally needed for these functions. A host that blocks them cannot process payments.
- The WordPress database user needs the usual order/options permissions plus CREATE/ALTER/INDEX and SELECT/INSERT/UPDATE/DELETE on the plugin ledger. Do not grant FILE or SUPER for this plugin.
- Use a single authoritative database writer for financial state. Transaction-pooling proxies, replica reads, SQL result caches, or database drop-ins that change the physical connection or cache lock/ledger queries are unsupported unless separately verified. A lost lock fails closed; reconcile the provider/order before retrying a customer payment.
- SQLite is intentionally unsupported for payment processing. No lock shim is provided. A generic database-compatibility claim does not include SQLite, PostgreSQL or SQL Server.

Use normal secure file ownership: PHP must read plugin files and write only WordPress's required writable directories. Public plugin assets need readable files and traversable directories (typically files 0644, directories 0755); protect `wp-config.php` with 0640 where the PHP worker's group permits it. Do not use world-writable production directories. Test containers use synthetic credentials and writable test log directories only.

Complete WooCommerce onboarding and make the test storefront visible; new WooCommerce installations may default to Coming soon. This is separate from the JazzCash gateway environment, which must remain Sandbox. A hidden storefront cannot demonstrate the public checkout, even when gateway configuration is correct.

## Server routing

Apache requires `mod_rewrite`, the standard WordPress `.htaccess` rules and permission for those rules (`AllowOverride FileInfo` or the host's supported equivalent). LiteSpeed-compatible hosting needs WordPress rewrite rules enabled as well.

Nginx requires WordPress front-controller routing:

```nginx
location / { try_files $uri $uri/ /index.php?$args; }
```

Keep the server's normal safe PHP-FPM configuration, SCRIPT_FILENAME mapping, index.php and document root. A working homepage alone is insufficient: verify `/wc-api/result/` returns a neutral message and `/jazzcash_ipn_v2` rejects GET with HTTP 405. A 404 from either route indicates a rewrite problem. Re-save WordPress permalinks after routing changes.

Caddy uses its standard WordPress `php_fastcgi` and `file_server` directives. The exact tested Nginx/Caddy/OpenLiteSpeed fixtures are in `tests/servers/` in the GitHub source. OpenLiteSpeed may require explicit virtual-host rewrite rules; do not assume an Apache `.htaccess` configuration has been loaded. Proprietary LiteSpeed Enterprise, IIS and every managed-host variant are not represented by an OpenLiteSpeed test.

If HTTPS terminates at a reverse proxy/CDN, configure WordPress to recognize HTTPS using the host's supported trusted-proxy setup. Accept forwarded protocol headers only from trusted proxy addresses. Do not disable TLS verification or trust client-supplied forwarding headers globally. Ensure POST bodies and content types survive proxy/WAF rules unchanged.

## Page cache, CDN and WAF exclusions

Bypass **both cache lookup and cache storage** for:

- `/wc-api/result/`, `/wc-api/crj_orchestrator_return/`, `/jazzcash_ipn_v2` and their slash/query variants, including `?wc-api=result`, `?wc-api=crj_orchestrator_return` and `?crj_jazzcash_ipn=1`.
- Checkout, cart, order-pay, order-received, account, WordPress login/admin, transaction history and `wp-admin/admin-post.php` exports. Use the actual translated/custom page paths on the store, including any subdirectory prefix.
- All POST requests, authenticated requests, WooCommerce session/cart cookies and private order-key URLs. Never cache a response with Set-Cookie, private/no-store cache-control or a payment redirect.

The plugin sets no-store/no-cache response headers, `DONOTCACHEPAGE`, no-referrer/nosniff headers and the LiteSpeed no-cache hook on callback handling. A cache that answers before WordPress loads cannot see those controls, so external exclusions are still required. Preserve cache-control and Set-Cookie; never force-cache payment endpoints. Allow provider callbacks through the WAF without disabling signature verification.

The tested Nginx fixture caches anonymous public pages while bypassing query requests, private routes, session cookies and POSTs. Redis Object Cache 3.0.0/Predis provides the representative persistent object cache. These tests do not certify every page-cache/object-cache plugin or every CDN configuration. Memcached is not covered by the current runtime matrix.

## Merchant operation and backups

Use sandbox merchant credentials only in this release. Keep merchant passwords/integrity salts out of chat, Git and screenshots. Back up `wp-config.php` salts and the database together; re-enter credentials after salt changes. Never rotate salts or restore a database during active payment processing.

The ledger is retained after order deletion/uninstall. Define retention, protect CSVs/backups, restrict WooCommerce-manager access, and reconcile ambiguous or repeated payments in the provider portal. IPN authentication alone never completes an order; a signed, matching return is still required in this implementation.
