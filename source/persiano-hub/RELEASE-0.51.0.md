# Batchly 0.51.0

## Customer correspondence
- Added **Customer Messages**, a unified one-to-one inbox for order-linked email and Twilio SMS.
- Email uses the existing WordPress/SMTP delivery path and Persiano email branding.
- SMS supports outgoing messages, incoming replies, delivery callbacks, order notes, unread status, quick replies, quiet hours and STOP/START opt-out handling.
- Added Twilio webhook URLs and optional signature validation settings.
- This workspace is intentionally separate from marketing campaigns and bulk messaging.

## Square transactions
- Added a Square Transactions workspace with date, status and text filters.
- Added live payment synchronization and cached transaction history.
- Added exact WooCommerce order links using stored Square identifiers only; no amount-only guessing.
- Added transaction details, receipt links, processing fees and refunded amounts.
- Added safe Square refunds through the linked WooCommerce order.
- Added Square payment/refund webhooks with idempotency and HMAC-SHA256 validation.

## Navigation and setup
- Added Email & SMS and Square Transactions to Customers & Sales.
- Expanded POS & Square settings with the Square webhook signature key and notification URL.
- Preserved existing settings when new Square fields are saved.
