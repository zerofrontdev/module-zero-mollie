# Changelog: zerofront/module-zero-mollie

## 1.3.6

- Returns and webhooks settle through Zero Checkout's new flow, under the order's lock; an order is cancelled only when Mollie says canceled, expired or failed.
- A payment for an order already cancelled is flagged, never booked.
- Pending orders can be rechecked by the recheck cron (off by default).
- Amounts use each currency's ISO 4217 decimals (JPY and ISK whole).

Earlier releases (1.3.5 and before): see the Git tags.
