# Batchly 0.45.1 — Import Safety Hotfix

- Prevents fuzzy ingredient-name matches during Ingredient Price History, Supplier Items, and Ingredient Aliases imports. Only a valid Ingredient ID, Canonical ID, exact canonical name, or exact alias is accepted.
- Keeps the entered purchase date in the WordPress site timezone.
- Adds an import batch ID to new price-history and supplier-item records.
- Shows detailed failed-row information after imports.
- Fixes the Supplier Items export fatal error caused by malformed or legacy supplier-item metadata.
- Adds a Maintenance tool to preview and remove price-history records by exact record IDs or import batch ID.
- Recalculates ingredient costs and recipes after price-history cleanup and removes stale costs when no approved history remains.
- Makes Needs Review include unapproved or non-normalized price-history records.
- Improves Possible Duplicates scanning by comparing aliases and token-contained names.
