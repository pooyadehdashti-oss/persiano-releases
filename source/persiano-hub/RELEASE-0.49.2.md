# Batchly 0.49.2

- Uses a numeric WooCommerce order total for cash and e-transfer forms, avoiding currency-symbol parsing errors such as 361.00.
- Cash received defaults to the exact order total; if unavailable it defaults to 0.00.
- E-transfer now shows and validates the amount received against order total plus tip.
- Adds an administrator/manager cash transaction adjustment form for completed cash sales.
- Adjustments can record corrected cash received, corrected change, an additional tip, and a required reason.
- Original cash values remain preserved in an audit history and WooCommerce order notes.
