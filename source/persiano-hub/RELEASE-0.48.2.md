# Batchly 0.48.2

- Uses the existing order-status polling request to perform exact-reference Square reconciliation while the payment page or computer/tablet handoff is open.
- Does not use amount-only matching.
- Automatically changes the computer/tablet handoff popup to Payment completed after Square verification.
- Shows invoice and completed-sale actions and refreshes the Payments badge.
- Keeps WP-Cron and the Square callback as additional verification paths.
