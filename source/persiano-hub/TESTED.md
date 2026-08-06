Batchly 0.45.3 test record

Static validation
- PHP syntax checked for all 44 plugin PHP files.
- No PHP syntax errors found.
- Plugin header and PERSIANO_HUB_VERSION are set to 0.45.3.
- JavaScript syntax checked for all standalone plugin JavaScript files.
- New inline queue scripts were manually/syntactically reviewed.
- Release ZIP structure and compressed-data integrity are checked during packaging.

Price Feed parser tests
- JSON-LD Product/Offer extraction:
  - product name, brand, SKU, GTIN, current price, currency and availability;
  - retailer detection from URL;
  - regular/high price and AggregateOffer low price.
- Structured Schema.org weight parsing: 8 kg.
- Package parsing:
  - 16 L → 16 L;
  - 12 × 355 ml → 4,260 ml;
  - 2 × 3 L → 6 L;
  - 500 g → 500 g;
  - 3 pack → 3 each.
- URL normalization test:
  - case normalization;
  - trailing-slash normalization;
  - removal of fragment and common UTM/social-ad tracking parameters;
  - duplicate URLs collapsed to one queue source.
- Manual review fields support correcting product, supplier, package and price before approval.
- Reapproval logic avoids duplicate history unless mapping or price/package/availability changed.
- Remapping a source removes the feed-maintained package from the old ingredient.
- Out-of-stock, identity-changed and permanently inaccessible sources deactivate their supplier package.

AI Scan queue tests
- Multi-file upload handler accepts JPEG, PNG, WebP and PDF and rejects unsupported types.
- Each accepted file creates an independent background job.
- PDF page-count fallback was tested against the supplied files:
  - shopp.pdf → 3 pages;
  - Persian Olivieh WordPress PDF → 8 pages.
- PDF jobs calculate 2-page ranges for small PDFs and 4-page ranges for longer PDFs.
- Partial results merge and deduplicate rows by product/package/price signature.
- Retry counters reset after a successful page range.
- Failed ranges retry with backoff; failed/stuck jobs are included in the recovery sweep.
- Completed scan review now lists every Ingredient Master record as a mapping option.

Yield & Packaging parser tests
- 1 individual meal → 1 each.
- 2 portions → 2 each.
- 250 ml jar → 250 ml.
- 4 × 300 g containers → 1,200 g.
- “Good for 2 portions, 150 ml” → 150 ml, preferring measurable package size over the descriptive serving count.

Shopping integration checks
- Shopping & Vendors can queue all active online Price Sources without blocking the page.
- The return URL preserves the selected production plan and date range.
- Source counts distinguish active records from records needing review or attention.

Data portability checks
- Full JSON backup format version is 3.
- Price Source metadata includes URL, redirect URL, mapping, identity fingerprint, package, price, availability, refresh frequency, failures and price-change fields.
- Price Source ingredient IDs and suggested ingredient IDs are remapped during restore.
- Price Source CSV header and row order include previous price, absolute change, percentage change and change time.

Regression protection retained
- Prepared-component inventory and sub-recipe scaling from 0.44.0 remain unchanged.
- The corrected Olivieh/Mayonnaise g/kg scaling remains in Production Planner and Shopping & Vendors.
- Existing shopping-list printing and Need-versus-Buy calculations remain present.

Limitations of this test environment
- No live WordPress/WooCommerce browser, Action Scheduler runner, WP-Cron runner, email server or retailer website was available for end-to-end execution.
- Retailer pages that depend on JavaScript/login/location/CAPTCHA require live-site testing and may remain manual-review sources.
- OpenAI extraction requests were not sent during packaging; API credentials and live host limits must be verified after installation.


0.45.2 combined regression
- Confirmed all 0.45.1 Import Safety hotfix files and logic are present in the combined source.
- Confirmed the Price Feed post-type key is `persiano_price_src` (18 characters), within WordPress's 20-character limit.
- Confirmed URL submission reports invalid input separately from storage failures and includes the database error message.
- Confirmed the combined package is based on the Import Safety hotfix, with only the Price Feed storage changes layered on top.


0.45.3 AI queue reliability regression
- Verified automatic refresh script pauses when a file input has selected files.
- Verified unsaved scan-review edits also pause refresh.
- Verified Action Scheduler chaining uses non-unique child actions plus a per-job lock.
- Verified scheduled marker is cleared when processing begins and stale jobs can be woken from the queue page.
- Verified the UI reports the current WordPress upload limit.
