# Batchly 0.47.0

## Square payment reliability
- Adds a secure, server-side Square Production Access Token setting.
- Resolves a Square POS transaction to its Square Order and Payment records.
- Verifies completed status, currency and location before completing WooCommerce payment.
- Records Square payment ID, order ID, receipt URL, card brand/last four, tip and processing fee.
- Adds payment-attempt history and statuses to the WooCommerce order screen.
- Adds a manual “Verify Square payment” action for delayed callbacks/API timing.

## Refunds
- Registers an internal WooCommerce Square Tap to Pay gateway with refund support.
- Enables full and partial refunds through Square when a verified Square payment ID is stored.
- Records Square refund IDs and pending/completed/failed refund states.

## Duplicate-order protection
- Locks a cart after its pending order is created.
- Closing the QR dialog no longer enables creating the same order again.
- Uses a per-cart idempotency token; repeated submissions reopen the existing pending order.
