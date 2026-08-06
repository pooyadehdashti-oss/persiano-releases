# Batchly 0.45.2 — Combined Hotfix

This release combines the two parallel 0.45.1 branches:

1. Import Safety Hotfix
2. Price Feed Storage Fix

It is safe to install over either 0.45.1 branch. The version is raised to 0.45.2 so WordPress and Batchly run the normal upgrade path.

## Included
- Exact-only ingredient matching for sensitive imports
- Import batch IDs, detailed row failures, cleanup tools, safer supplier-item export
- Recalculation and stale-cost cleanup after price-history removal
- Improved Needs Review and duplicate detection
- Valid Price Feed post-type key (`persiano_price_src`)
- Clear separation of invalid URLs and database storage errors
