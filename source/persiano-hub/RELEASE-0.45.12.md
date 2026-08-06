# Batchly 0.45.12

Correction/integration release adding browser-assisted supplier price capture for retailer pages that block server-side fetching.

- Token-scoped REST API with no access to orders or customers.
- Ingredient contains-search endpoint.
- Reviewable product/package/price capture.
- Saves a Price Source, supplier package and approved price-history observation.
- Deduplicates by source URL and existing supplier-package linkage.
- Chrome/Firefox extension CORS support.
