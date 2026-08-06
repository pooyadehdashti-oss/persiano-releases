# Batchly 0.46.5

- Fixes POS lookup for WooCommerce SKUs when the product lookup table is stale.
- Matches SKU values case-insensitively and ignores accidental surrounding spaces.
- Supports product variation SKUs and common imported/custom SKU meta fields.
- Adds a normalized fallback for codes that differ only by spaces, hyphens, or underscores.
