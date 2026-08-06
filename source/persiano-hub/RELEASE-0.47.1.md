# Batchly 0.47.1

Payment reconciliation and POS handoff corrections.

- Recovers completed Square payments even when the iOS callback is not received.
- Matches recent Square payments by order note, amount, currency, location, and time.
- Hides Tap to Pay after an order is verified paid.
- Enables Square refunds after reconciliation, while keeping the internal gateway hidden from checkout.
- Accepts callback fields both inside the `data` JSON and as top-level query parameters.
- Fixes the handoff dialog close/keep/new-sale controls.
- Prevents accidental duplicate orders while keeping the current sale available.
