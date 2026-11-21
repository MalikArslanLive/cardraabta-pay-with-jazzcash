# Checkout and payment verification

## Customer flow

1. WooCommerce creates an unpaid order. CardRaabta sends a signed request for a hosted link containing the merchant credentials, the PKR amount, date and expiry, and the WooCommerce bill and return references.
2. The customer chooses a payment method on JazzCash and enters the payment details there. A wallet customer approves the request through the prompt on the phone or JazzCash app.
3. CardRaabta binds the provider result to the original payment attempt. A verified success completes payment in WooCommerce, which then applies its order status rules. A verified decline can send the customer back to the same order's payment form. Pending results suppress repeat payment while the outcome remains uncertain.
4. The signed same-site return ticket is short-lived. The browser also needs an authorized order owner or matching checkout session before it can open an order URL.

## Wallet waiting state

JazzCash returns customers to WooCommerce. Confirmed payments show the receipt. Confirmed cancellations, drops and expiry show the order receipt with the provider outcome. Verified declines such as an invalid wallet can open the existing order payment form.

For unresolved Live code 999, CardRaabta schedules up to six background checks through WordPress Cron, starting after 20 seconds and then spaced by about a minute. Timing depends on the store's cron runner. The result must match the merchant, order reference, amount, currency and wallet charge. A dropped hosted-link parent alone cannot resolve a wallet charge. Until a signed notification or matching merchant history settles it, the order remains unpaid and another payment is blocked.

## Callback decisions

| Evidence | WooCommerce behavior |
| --- | --- |
| Verified matching success code | Complete payment once. |
| Signed pending or ambiguous result | Keep unpaid and suppress repeat payment. |
| Confirmed decline | Display the supported reason and offer retry on the same order when payable. |
| Missing order identity, amount, currency, or verification | Keep the order unchanged and record safe diagnostics. |
| Already paid or repeated event | Preserve the original payment and avoid duplicate status changes. |

The callback paths are `/wc-api/result/` and `/jazzcash_ipn_v2`. Complete signed successful IPNs can confirm a matching payment. The hosted-link and history APIs have separate roles. The API version setting controls the outgoing hosted-link request. A processing-mode column in a provider report alone cannot verify the outgoing request version.

## Diagnostics

In WooCommerce → Status → Logs, look for `cardraabta-pay-with-jazzcash-diagnostic`. Compare the WooCommerce order, provider transaction reference, amount, and server-side callback duration before interpreting a delayed return. A card or wallet entered on the hosted page is identified only from verified provider information. A blank transaction type can occur on an untouched or dropped hosted-link parent.
