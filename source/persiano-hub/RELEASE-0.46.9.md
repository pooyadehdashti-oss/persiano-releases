# Batchly 0.46.9

- Fixes POS order creation reporting “No valid products were added” after a valid product was selected.
- POS order creation no longer applies storefront `is_purchasable()` restrictions to selected simple products and variations.
- Hidden/private catalogue products can be added to a POS order when explicitly selected.
- Parent variable/grouped/external products are still rejected unless a concrete variation/product is selected.
