# Batchly 0.45.7

Correction-only hotfix:

- `Keep separate` now removes the reviewed pair from the current duplicate-results table immediately.
- The pair remains stored in the ignored-pairs list, so later duplicate scans do not suggest it again.
- No ingredient, alias, supplier package, recipe, or price data is changed by this action.
