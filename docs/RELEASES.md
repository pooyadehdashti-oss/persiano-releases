# Batchly Release History

## 0.57.1 — Corrected Google Reviews Release

- Corrected the plugin version included in the release package.
- Re-published the Google Reviews via Make.com feature.
- Supersedes the incorrectly packaged 0.57.0 release.

## 0.57.0 — Google Reviews via Make

- Added secure Make.com webhook endpoints for Google Business Profile reviews.
- Added single-review and historical bulk-import support.
- Deduplicates and updates reviews using the Google review ID.
- Stores reviewer name, photo, rating, comment, business reply and timestamps locally.
- Added an admin review list with publish, hide, feature and unfeature controls.
- Added the `[batchly_reviews]` website shortcode.
- Added configurable rating, limit, layout, featured-only, photo and reply display options.

## 0.56.6 — Multisite Update Permissions Fix

- Restored the Batchly Updates page for site administrators.
- Changed page, settings and manual-check access from `update_plugins` to `manage_options`.
- Actual plugin installation remains governed by WordPress and network permissions.
- Preserved the GitHub update and plugin-details integrations.

## 0.56.5 — Core Update Channel Cleanup

- Removed the obsolete Batchly Theme status card from the Core Updates screen.
- Removed wording that required every Core release to include a theme ZIP.
- Clarified that storefront themes are optional, independently versioned and independently distributed.
- Preserved the GitHub browser-download fix introduced in 0.56.4.
- Provides the first end-to-end test of updating Batchly through WordPress **Update now**.

## 0.56.4 — WordPress Update Download Fix

- Fixed `PCLZIP_ERR_BAD_FORMAT` during GitHub-delivered WordPress updates.
- Public repositories now use GitHub's direct `browser_download_url`.
- Private repositories continue using authenticated GitHub API asset downloads.
- Added ZIP-signature validation and clearer invalid-package errors.
- Preserved the working Batchly **View details** metadata.
- Batchly Core and storefront themes remain independently released.

## 0.56.3 — Core and Theme Separation

- Separated Batchly Core updates from storefront-theme updates.
- Removed theme packages from the Core updater and Core release requirements.
- Added Batchly Theme API 1.0 with stable helpers, hooks and public REST endpoints.
- Made business-profile and theme-integration services available independently of WooCommerce.
- Reframed WooCommerce as the current commerce adapter for existing commerce features.
- Preserved all current WooCommerce-backed operations when WooCommerce is active.
- Established the compatibility boundary for independently designed and versioned client themes.

This release does not yet replace WooCommerce-backed products and orders with a fully native Batchly commerce engine.

## 0.56.2 — Update Delivery & Plugin Details

- Added a canonical WordPress `Update URI` for Batchly.
- Improved the WordPress **View details** modal with description, installation guidance, changelog, homepage, icon and banner metadata.
- Uses the Batchly GitHub repository as the canonical update-information location.
- Added a GitHub Actions workflow for validating and publishing ZIP assets.
- Includes all Manual Order and Food Label fixes from 0.56.1.

## 0.56.1 — Manual Order & Label Tools

- Restored the responsive Manual Order page layout.
- Corrected customer, guest, consent, item, fulfilment and payment-field alignment.
- Preserved secure online payment as the default manual-order payment handling.
- Added Avery label controls: Select all, Clear all, Left, Right, Reverse and Select first N.
- Reduced Trial Monitoring feedback-prompt interference on operational forms.

## 0.56.0 — Maintenance Rollup

- Repaired the Food Labels builder layout.
- Fixed Avery 5163 multi-label printing and selected-position handling.
- Improved label content transfer, logo, barcode, footer and text sizing.
- Added overflow guidance for compact 4 × 2-inch labels.
- Replaced several Persiano-specific labels with configurable business wording.
- Preserved the POS memory hotfix and Trial Monitoring menu fix.

## 0.55.2 — Trial Monitoring Menu Fix

- Restored the Trial Monitoring menu entry.
- Included the 0.55.1 POS settings hotfix.

## 0.55.1 — POS Settings Hotfix

- Fixed recursive Hub URL generation that exhausted PHP memory on the POS settings page.

## 0.55.0 — Trial Monitoring & Hub Access

- Added trial activity logging, daily summaries, feedback prompts and inactivity alerts.
- Added Hub rewrite refresh and a query-string fallback route.

## 0.54.1 — Unified Updater

- Unified Batchly plugin and theme update naming.
- Preserved the legacy internal plugin folder for upgrade compatibility.
- Prepared one GitHub release source for Persiano Dish, demo sites and future clients.

## 0.54.0 — Batchly Rebrand

- Renamed the customer-facing product from Persiano Hub to Batchly.
- Added Batchly branding and a generic Batchly theme.

## 0.53.0 — Business Setup Wizard

- Added guided business identity, contact, store, email, SMS, payment, accounting, social and update configuration.

## 0.52.x — Order Correspondence & Customization

- Added order-based communication timelines.
- Added reusable business profiles and white-label settings.
- Added automatic customer-contact retrieval from Order ID.
- Set secure online payment as the manual-order default.

## 0.51.x — Email, SMS & Square Foundation

- Added branded order emails and payment links.
- Added Twilio SMS sending, receiving and delivery tracking.
- Added Square transaction synchronization and transaction views.
