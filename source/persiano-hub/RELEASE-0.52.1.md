# Batchly 0.52.1

## Corrections
- Order Correspondence now retrieves the billing email or phone automatically when an Order ID is entered or changed.
- Switching between Email and SMS refreshes the contact from the selected order.
- Invalid order IDs clear stale contact data instead of reusing the previous customer.
- New manual orders default to **Send for secure online payment**.
- Existing order correspondence, rich payment-link emails, Twilio delivery tracking, business profiles, GitHub update support and exact Square identifiers remain included.

## Trial preparation
- Each trial installation must use its own third-party credentials.
- Do not migrate Persiano Dish API keys, tokens, customers or orders to a tester.
- Use an isolated WordPress/WooCommerce installation per business.
