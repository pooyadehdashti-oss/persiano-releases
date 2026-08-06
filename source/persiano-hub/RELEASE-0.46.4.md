# Batchly 0.46.4

- Fixes Square mobile-web callback matching by sending the exact registered callback URL with no query string.
- Carries the WooCommerce order reference securely in Square state.
- Sends the Square charge amount in cents as a numeric string, preventing the $0.00 handoff.
- Fixes exact SKU/barcode lookup with a direct metadata fallback.
- Replaces broad WooCommerce catalogue search with ranked product-title matching.
