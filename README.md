# Zero_Mollie

Payment on Mollie's page for Zero Checkout: the customer picks iDEAL,
Bancontact, card, SEPA, Klarna or any other method switched on in the
Mollie dashboard for the order's currency. No libraries: Mollie's Payments
API v2 over Magento's HTTP client.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-mollie
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core`, `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## How a payment goes

1. **Placing the order** creates a Mollie payment for the order's grand
   total in the order's currency (the store view's currency, e.g. EUR in
   Europe (EUR)). The order stays **pending payment**, and Zero Checkout
   sends the customer to Mollie (`Placeorder::REDIRECT_URL`).
2. **The customer comes back** (`zero_mollie/payment/return`, also Mollie's
   cancel URL). The payment's state is asked from Mollie:
   - `paid`: captured, invoice registered, order email;
   - `authorized` (pay-later methods): reserved, the order is processing;
     capture it in the Mollie dashboard;
   - `canceled`, `expired`, `failed`: the order cancelled, the cart restored;
   - `open` (came back without paying): the payment is cancelled where the
     method allows it, then the order;
   - `pending`: the success page with a note.
3. **Mollie's webhook** (`zero_mollie/payment/webhook?order=…`, POST
   `id=tr_…`): unsigned by Mollie's design, so it only triggers asking
   Mollie again; the id must be the order's own payment. Not sent from a
   development site (Mollie refuses webhooks it can't reach); there the
   return updates the order.
   Handled now or from the queue: Zero Checkout > Payment Webhooks
   (the Zero_Checkout README).

A payment for another amount or currency than the order's leaves the order
pending with a comment. **Refunds:** a credit memo from the invoice refunds
online, in full or in part, in the order's currency.

**Settlement** (Zero Checkout's redirect-payment flow): the return page, the
webhook and the recheck cron update the order under its lock, on a freshly
read order; while another process holds it the customer sees "processing". A
payment Mollie reports for an order already cancelled is not booked: the order
stays cancelled, with a comment and an admin notice (refund it at Mollie). The
updater is registered for Zero Checkout > Payment Webhooks > Recheck Pending
Payments (off by default), since it cancels only on `canceled`, `expired` or
`failed`, never because nobody has paid yet.

## Settings

Stores > Configuration > Sales > Payment Methods > **Mollie (Zero)**:
Enabled, Title, **API Key** (encrypted; `test_…` for test payments),
countries, sort order. The method shows only with a key. Not for orders made
in the admin.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

Zero is made by [Fixstacks](https://fixstacks.com).
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
