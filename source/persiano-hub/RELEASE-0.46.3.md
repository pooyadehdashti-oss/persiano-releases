# Batchly 0.46.3

POS product lookup correction:

- Numeric input now checks the exact WooCommerce product/variation ID first.
- SKU and barcode fields are matched exactly before title search.
- Broad title search only runs when no exact identifier matches.
- Removed the automatic catalogue search that populated a random list on page load.
