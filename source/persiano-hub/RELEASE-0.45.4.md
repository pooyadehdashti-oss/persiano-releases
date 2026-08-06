# Batchly 0.45.4

Safe structural-data merge based on 0.45.3.

- Preserves all 0.45.3 AI queue, price feed, inventory, import/export, and reliability changes.
- Adds searchable contains-filter selectors for long admin dropdowns.
- Adds standardized Yield & Package Standards catalogue.
- Adds structured sellable package type, package size/unit, sellable units per batch, and remainder to recipe costing.
- Uses the existing Total batch output and Output unit fields as the physical recipe yield; no duplicate yield fields.
- Warns when total output divided by package size conflicts with sellable units per batch.
- Does not replace the current 0.45.3 codebase with an older branch.
