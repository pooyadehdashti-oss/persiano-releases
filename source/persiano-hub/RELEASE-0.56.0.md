# Batchly 0.56.0 — Maintenance Rollup and Label Repair

## Fixed
- Restored the Food Labels builder layout even when WordPress changes the admin hook name.
- Rebuilt responsive spacing, grids, checkboxes, product rows and mobile behavior.
- Avery 5163 sheets now populate every selected position when one product is repeated.
- Corrected selected-position handling for non-consecutive slots such as L1, R2 and R3.
- Passed preparation, notes and best-before guidance into the sheet renderer.
- Reworked 4 × 2 label typography, barcode size, logo sizing and content priority.
- Added an on-screen overflow warning instead of silently hiding excess content.
- Labels now use each site’s Batchly Business Profile contact and website identity.
- Replaced visible legacy “Persiano” wording in advance-order and product-list labels.

## Preserved
- Trial monitoring and feedback prompts.
- POS memory-loop and Trial Monitoring menu fixes from 0.55.1–0.55.2.
- Order correspondence, rich email, SMS, Square transaction linking and unified GitHub updater.

## Notes
Avery 5163 labels have limited physical space. Batchly prioritizes the product name, quantity, dates, ingredients, allergens, storage and machine-readable codes. Very long optional descriptions may be shortened and are flagged in the browser preview.
