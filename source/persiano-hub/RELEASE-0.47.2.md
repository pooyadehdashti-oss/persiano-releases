# Batchly 0.47.2

Correction release for Square payment safety and the front-end payment ledger.

- Removes all amount/time-only Square reconciliation. An exact WooCommerce order reference is now mandatory.
- Rejects any callback/payment whose Square payload does not contain the exact WooCommerce order number.
- Adds a complete Payments ledger with Pending, Completed, Failed, Cancelled, Verification Error, Partially Refunded, Refunded, and All views.
- Adds payment search by order number, customer, phone, amount, Square payment ID, or Square transaction ID.
- Keeps the red Payments badge limited to genuinely pending POS orders.
- Registers the internal Square gateway after WooCommerce loads so automatic full/partial Square refunds are available in WooCommerce Admin.
- Blocks Square Tap to Pay server-side for paid, non-pending, or already-linked orders.

Note: true locked-screen/background Web Push is not included in this correction release. The existing notification remains open-app polling until the dedicated push service is implemented.
