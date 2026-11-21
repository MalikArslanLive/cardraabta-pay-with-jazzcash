# Transaction history and refunds

Open **WooCommerce → CardRaabta Transactions** as a WooCommerce manager. The page queries JazzCash with the saved Live credentials. **Connected** in the Payment Health cards means the history request succeeded. Checkout availability is controlled by the gateway switch and its configuration.

Each table row represents a provider transaction reference. A hosted-link parent and its card or wallet charge can appear as separate references for the same order. **Response Code** comes from the provider history row. **Signed order return** appears when independently verified checkout return information for the linked attempt differs. For example, a dropped parent can have history code 129 while a wallet attempt returned 999.

History requests 50 rows from the provider at a time. Results are sorted newest-first within the loaded pages. Search matches transaction ID or bill reference across up to three provider pages at a time. Continue with **Next** when more pages are available. A blank type means no method was confirmed for that exact row.

## Refund a payment

1. Locate the exact paid provider transaction and choose **Refund**. Review the reference, original amount and refund amount in the modal.
2. Select **Process Refund** once. The result appears in the modal with the provider response message and code when JazzCash supplies them.
3. CardRaabta checks the exact transaction in JazzCash before requesting a refund. For a linked order it saves a WooCommerce refund only after JazzCash accepts the request.
4. The transaction status always comes from JazzCash history. A manual WooCommerce refund without matching JazzCash confirmation shows a discrepancy. A definite provider rejection keeps the message and code visible and permits retry. This includes code 999 when JazzCash explicitly says the refund failed and asks the merchant to try again. If the outcome is unclear, retry stays paused to prevent a second refund.
5. **Save WooCommerce refund** retries a local write when CardRaabta already received a successful JazzCash refund response. It does not send a second request. A full refund confirmed by JazzCash history displays the refunded total and a zero balance. For newer successful requests sent through CardRaabta, the page also displays the confirmed refund amount and an upper limit for the remaining balance. Refunds made outside CardRaabta and older records may only have a partial-refund status. Use the Merchant Portal for an exact balance when the history response does not provide it.

Only share sanitized references, timestamps and codes with support. Never post merchant passwords, integrity salts, PINs or raw payment payloads publicly.

## Order checks, refund sync and payment health

- In a CardRaabta order, select **CardRaabta: Sync with JazzCash** under **Order actions**. The check queues a background search when the order is saved. It reads merchant history in 50-row pages and processes only the exact order reference. Progress and the final result appear above the Update button. The search continues through WordPress Cron after you leave the order page. The final result is recorded in the order notes, and a matching Live payment can settle an unpaid order.
- The full-width **CardRaabta activity** panel shows recent payment attempts, WooCommerce refunds, refund response details and the most recent manual status check.
- The five-minute refund sync checks the latest merchant history page. A verified refund total can update a matching WooCommerce order without sending a refund request. Use **CardRaabta: Sync with JazzCash** to search for an older order. WordPress Cron timing depends on the host.
- **Payment Health** at the top of **WooCommerce → CardRaabta Transactions** shows current Live history connectivity, the last newly settled Live payment callback, and the count of CardRaabta orders pending or on hold.
- On partial refunds the balance reader accepts `pp_RefundedAmount` and `pp_RemainingRefundAmount` from authenticated history when both are integer paisa amounts, positive, and add up to the original PKR payment. If either field is unavailable or inconsistent, use the Merchant Portal for the exact balance. The server checks fresh history before allowing another partial refund.
