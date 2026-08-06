# Batchly 0.51.1 — Rich customer email hotfix

## Fixed

- `{payment_link}` no longer disappears silently. If the selected order cannot currently accept online payment, Batchly stops the send and explains why.
- Linked unpaid orders now automatically include a prominent secure payment button and copyable fallback URL.
- Customer emails now include a greeting, highlighted personal message, order number, date, status, fulfilment method, total, item summary and a reply prompt.
- Paid orders receive a View Order Details button instead of an empty payment section.
- Plain URLs in the personal message are clickable.

## Test

1. Open a WooCommerce order with **Pending payment** status and a total above zero.
2. Send an email containing `{payment_link}` from **Customers & Sales → Email & SMS**.
3. Confirm the email contains both a **Pay Securely** button and a full fallback URL.
4. Try the same token on a paid order and confirm Batchly blocks the incomplete email with a clear message.
