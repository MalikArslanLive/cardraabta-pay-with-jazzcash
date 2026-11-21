=== CardRaabta – Pay with JazzCash for WooCommerce ===
Contributors: malikarslanlive
Tags: woocommerce, payment gateway, jazzcash, pakistan
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept JazzCash card and wallet payments in WooCommerce using hosted checkout, transaction history and eligible refunds.

== Description ==

CardRaabta adds a Pay With JazzCash gateway to WooCommerce Classic Checkout and Checkout Blocks. Customers complete payment on JazzCash's hosted page. The payment methods shown there depend on your JazzCash merchant account.

* Configure separate Live and Sandbox credentials.
* Match verified payment responses to the WooCommerce order, merchant, amount, currency and transaction attempt.
* Display JazzCash merchant transaction history with search, response codes and eligible refund actions for WooCommerce managers.
* Use High-Performance Order Storage (HPOS) or legacy order storage.

CardRaabta is independently maintained. Merchant accounts and payment services are provided by JazzCash.

== Installation ==

1. Install and activate WooCommerce, then install and activate CardRaabta.
2. Open WooCommerce > Settings > Payments > Pay With JazzCash. Choose Live or Sandbox and enter the merchant ID, password and integrity salt for that environment.
3. Choose the checkout API version assigned by JazzCash. The default is 1.1. Use PKR and serve the store over HTTPS.
4. In the JazzCash merchant portal, set the store Return URL to /wc-api/result/ and the IPN URL to /jazzcash_ipn_v2 on your store domain. Set IPN protocol to REST. Include the store directory if WordPress runs in a subdirectory.
5. Enable the gateway. Verify your merchant account's payment flow before exposing it to customers.

= Requirements =

WordPress 6.5 or later, WooCommerce 8.3 or later, PHP 8.1 or later, OpenSSL, iconv, HTTPS, PKR and MySQL or MariaDB with connection-scoped named locks. Choose WordPress and WooCommerce versions that meet each other's requirements. JazzCash must provision your merchant account for the services you use.

== Frequently Asked Questions ==

= Where do customers enter payment details? =

Card and wallet details are entered on the JazzCash hosted page. The customer approves a mobile-wallet prompt on their phone. CardRaabta does not collect card numbers, CVV, PIN or OTP.

= What happens if payment is still pending on return? =

The normal WooCommerce order page opens when JazzCash returns. Unconfirmed payments remain unpaid. The store can check recent Live merchant history in the background for an unresolved wallet payment. Signed notifications can also update the order.

= How does the plugin verify payment? =

Signed browser returns and complete signed notifications are matched against the saved order attempt. For pending Live wallet payments, a matching result from the authenticated merchant history can also confirm the attempt. Incomplete or conflicting records cannot confirm payment.

= Where are transactions and refunds? =

Open WooCommerce > CardRaabta Transactions. The table uses the configured Live merchant account. It requests 50 rows per provider page and sorts the loaded results newest first. Search matches transaction ID or bill reference across up to three provider pages at a time.

An eligible payment can receive a full or partial refund after a fresh JazzCash lookup. If JazzCash history does not confirm a WooCommerce refund, Transaction History shows the difference. An eligible completed JazzCash payment without a verified WooCommerce match can receive one direct refund after a fresh provider lookup. Direct refunds require separate WooCommerce reconciliation. Check unclear outcomes in the JazzCash merchant portal before sending another refund.

= Why does history show connected when checkout is disabled? =

The history connection badge reports a successful JazzCash history request. The WooCommerce gateway switch controls whether customers can start a new payment.

= What should I exclude from cache? =

Exclude checkout, cart, account, order and callback routes, including signed return and status queries. Preserve private and no-store headers, session cookies, provider POST bodies and redirects. See the hosting guide in the project repository.

== External service ==

This plugin connects to JazzCash for hosted checkout, merchant history and refunds. It sends the configured merchant credentials, a request signature, and the payment or order details required by each service. The store can make these requests when a customer starts checkout, while a Live wallet payment is being verified, or when a WooCommerce manager views history or sends a refund.

* Hosted checkout: https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/transactions/link
* Merchant history: https://onlinepayments.jazzcash.com.pk/payment-orchestrator/payment/api/v1/shopify/inquiry
* Refunds: https://onlinepayments.jazzcash.com.pk/payment-orchestrator/api/v1/rest/payments/shopify/refund
* JazzCash service and merchant support: https://www.jazzcash.com.pk/
* JazzCash privacy policy: https://www.jazzcash.com.pk/privacy-policy/

Merchants use the service under their JazzCash agreement. Merchant passwords and integrity salts are encrypted at rest using keys derived from WordPress site salts. Keep the matching site configuration and database backups. Store managers govern transaction retention and access.

== Support ==

Project documentation, configuration guides, issue reporting, security policy and development instructions are available at https://github.com/MalikArslanLive/cardraabta-pay-with-jazzcash. Use synthetic data in public reports. Report credentials, payment data or security details through a private channel.

== Changelog ==

= 1.0.0 =
* Hosted JazzCash checkout for Classic Checkout and Checkout Blocks.
* Signed payment returns and notifications, background wallet verification and duplicate-attempt protection.
* Transaction history and eligible refunds for WooCommerce managers.
* HPOS and legacy storage integration.
