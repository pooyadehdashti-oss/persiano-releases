# Batchly 0.45.9 — supplier package reconciliation correction

- Rebuilds missing supplier packages from current purchase fields first, then approved history.
- Transfers packages and purchase history from merged records to canonical ingredients.
- Excludes merged and non-purchasable process ingredients from Workflow Health.
- Separates identity conflicts and unresolved purchase assignments from ordinary missing-package warnings.
- Normalizes and deduplicates supplier-package structures and recalculates unit costs.
- Stores a repeatable repair report and schema consistency audit.
