Batchly 0.45.3

Background price-source monitoring, resilient AI Scan queues, and workflow-health controls.

Changes in 0.45.3

AI Scan Queue reliability hotfix:
- Pauses automatic page refresh while files are selected or scan-review fields have unsaved edits.
- Adds a manual Refresh jobs button and visible auto-refresh status.
- Fixes PDF chunk chaining under WooCommerce Action Scheduler so a running chunk can reliably schedule the next chunk.
- Adds a per-job lock and scheduled-action marker to prevent duplicate concurrent processing.
- Visiting the AI Scan Queue wakes stale queued jobs.
- Shows the actual server upload limit instead of always claiming 25 MB.

Combined release: Price Feed storage fix + Import Safety hotfix

Price Feed storage
- Uses the valid 18-character internal post-type key `persiano_price_src`, fixing URL records that previously failed with “Invalid post type.”
- URL Inbox results now separate invalid URLs from database/storage failures and display the actual storage error.

Import safety and maintenance
- Ingredient Price History, Supplier Items, and Ingredient Aliases imports accept only a valid Ingredient ID, Canonical ID, exact canonical name, or exact alias; fuzzy matches are rejected.
- Purchase dates are preserved in the WordPress site timezone.
- New imported price-history and supplier-item records receive an import batch ID.
- Import summaries include detailed failed-row information.
- Supplier Items export tolerates malformed or legacy metadata.
- Maintenance can preview and remove price-history records by record ID or import batch ID.
- Ingredient and recipe costs recalculate after cleanup; stale costs are removed when no approved history remains.
- Needs Review includes unapproved or non-normalized history.
- Possible Duplicates compares aliases and token-contained names.


Changes in 0.45.0

Online Price Feeds
- Added Costing & Recipes → Price Feeds as a persistent Price Source Inbox.
- Paste up to 200 product URLs at once, including URLs embedded in copied text.
- URLs are normalized, common tracking parameters are removed, and duplicates are recognized.
- New and existing URLs enter a background queue. WooCommerce Action Scheduler is preferred; WP-Cron is used as a fallback.
- Each source stores the original URL, current redirected URL, retailer, product name, brand, SKU/barcode, package quantity/unit, current and regular prices, currency, availability, image, last attempt, last success and failure history.
- JSON-LD Product/Offer data and common product meta tags are extracted when available.
- Package parsing supports ordinary packages and multipacks such as 16 L, 500 g and 12 × 355 ml.
- Extracted values remain editable during review. A page that did not expose a reliable package or price can be corrected manually before approval.
- Map a source once to an Ingredient Master record. Approval creates or updates the linked supplier package and adds a dated price-history record.
- Rechecking an unchanged source does not create duplicate price history.
- Price increases/decreases are recorded and displayed against the previous detected price.
- Sources can be searched, filtered by status, paged in groups of 50, checked manually, or assigned manual, daily, weekly or monthly refresh frequency.
- Product identity is protected with a fingerprint using name, brand, SKU/barcode and package. A changed product or package is deactivated and sent for review before its price can affect purchasing.
- Out-of-stock and inaccessible sources are excluded from active supplier-package selection.
- Temporary failures retry with backoff. HTTP 404/410 or three consecutive failures produce a Persiano notification and optional email alert.
- Redirects are followed and the current URL is retained.
- Price Sources are included in full JSON backup/restore and CSV export.

Shopping integration
- Shopping & Vendors now shows active Price Feed and attention counts.
- Added Check all online prices directly from Shopping & Vendors.
- Checks run in the background; the shopping comparison can be refreshed when they finish.
- Deleting a Price Source removes its feed-maintained supplier package while preserving historical ingredient price records.

Background AI Scan Queue
- AI Scan now accepts up to 10 images or PDFs in one submission, up to 25 MB each.
- Uploading stores the files immediately and returns the browser to the queue rather than waiting for extraction.
- Each file is an independent background job.
- PDFs are processed in small page ranges and merged, reducing browser/server timeout risk and preventing a failed page range from restarting completed ranges.
- Failed jobs retry with backoff up to three times and can be retried manually.
- An hourly recovery sweep requeues abandoned queued jobs, due retries and apparently stuck processing jobs.
- Successful and failed jobs create Persiano notifications; email delivery is configurable.
- Completed scans can be reviewed and mapped to any existing Ingredient Master record or used to create a new ingredient.
- Purchase scans remain separate from price-observation scans so flyers and supplier price lists do not increase inventory.

Yield & Packaging catalogue
- Added Costing & Recipes → Yield & Packaging.
- Consolidates recipe batch yields, linked WooCommerce customer package sizes and Ingredient Master supplier packages.
- Supports physical package descriptions and count descriptions such as 1 individual meal, 2 portions, 250 ml and 4 × 300 g.
- When both a serving description and physical package are present, the measurable mass/volume is preferred.
- Workflow Health flags:
  - missing or invalid recipe yields;
  - linked products without measurable package data;
  - incompatible recipe-yield and product-package unit families;
  - ingredients without supplier packages;
  - incomplete supplier packages or missing prices/suppliers;
  - active shopping lists that bypass a saved production plan;
  - Price Sources needing review or attention;
  - failed or apparently stuck AI Scan jobs.
- Added Yield & Packaging CSV export.

Notifications and data portability
- Notification settings now include Price Feed alerts and AI Scan completion/failure alerts.
- The admin notification bell and history include these background-system events.
- Full JSON backup format is now version 3 and preserves Price Sources and their ingredient mappings.
- Added Price Source CSV export fields for current/previous price and price-change tracking.

Retained from 0.44.0 and earlier
- Unified raw ingredient, prepared-component and WooCommerce product inventory.
- Prepared-component lots, expiry handling, reservations and earliest-expiry-first use.
- Production plans consume prepared stock before expanding sub-recipes.
- Correct g/kg and ml/L sub-recipe conversion throughout costing, production and shopping.
- Need-versus-Buy shopping quantities and clean shopping-list printing.
- Supplier items, price history, aliases, duplicate cleanup/audit, recipe versions, variants, manual orders, publishing and prior Batchly workflows.

Recommended workflow
1. Paste product URLs into Price Feeds as you encounter them.
2. Let the background queue extract the source data.
3. Review/edit the package and price, map it to an ingredient, and approve it.
4. Record raw or prepared inventory.
5. Build or refresh a saved Production Plan.
6. From Shopping & Vendors, run Check all online prices when current prices are needed.
7. Review Price Feed warnings, then refresh the shopping comparison.
8. Create, print and reconcile the shopping list.
9. Upload receipts/invoices or supplier PDFs to AI Scan in batches and review completed jobs later.

Important limitations
- Some retailers render prices only with JavaScript, require login/location selection, use CAPTCHA/bot protection, or prohibit automated access. Those sources will be marked for review or attention rather than silently changing data.
- URL extraction is intentionally conservative. No online result is allowed to alter ingredient pricing until the source is mapped and approved.
- The release was statically tested in a container but was not installed or browser-tested on the live Persiano Dish WordPress site.
