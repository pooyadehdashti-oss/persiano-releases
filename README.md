<div align="center">
  <img src="docs/assets/batchly-banner.svg" alt="Batchly — Run every workflow with confidence." width="100%">
</div>

# Batchly

**Orders, customers, production, correspondence and publishing in one configurable WordPress workspace.**

Batchly is a modular business-operations system created by **Persiano Dish** for independent food businesses, bakeries, caterers, meal-prep businesses and other small product-based operations.

> **Your business, organized.**

## Current versions

| Package | Current version |
| --- | ---: |
| **Batchly plugin** | **0.56.1** |
| **Batchly Theme** | **1.1.1** |
| WordPress | 6.5 or newer |
| PHP | 7.4 or newer |
| WooCommerce | Required for commerce features |

## Main capabilities

- Business Setup Wizard and reusable business profiles
- Products, customers and manual orders
- Secure payment-link workflows
- Order-based email and SMS correspondence
- Square transaction synchronization
- Recipes, costing, production and fulfilment tools
- Food labels, Avery sheets, barcodes and QR codes
- Publishing and external-channel connections
- Trial monitoring and tester feedback
- Shared GitHub-based updates while each site keeps its own data and credentials

## Latest release — 0.56.1

### Manual Order improvements

- Restored the responsive Manual Order form layout.
- Corrected customer, guest, consent, item, fulfilment and payment-field alignment.
- Preserved **Send for secure online payment** as the default payment handling.

### Food Label improvements

- Added **Select all**, **Clear all**, **Left**, **Right**, **Reverse**, and **Select first N** controls for Avery label positions.
- Improved multi-position Avery 5163 workflows.
- Preserved barcode, QR-code, business-profile and product-label functionality from 0.56.0.

### Trial monitoring interface

- Reduced feedback-prompt interference on operational forms.
- Added a close control and improved responsive placement.

## Installation

This repository is the Batchly distribution and release-information repository.

1. Open the repository's [Releases page](https://github.com/pooyadehdashti-oss/persiano-releases/releases).
2. Download the latest `batchly-vX.Y.Z.zip` plugin package.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
4. Upload, install and activate Batchly.
5. To install the storefront theme, download `batchly-theme-vX.Y.Z.zip` and upload it under **Appearance → Themes → Add Theme → Upload Theme**.

Existing Batchly and former Persiano Hub installations retain their existing products, customers, orders, correspondence, settings and third-party credentials during normal upgrades.

## Update model

One Batchly release can update:

- Persiano Dish
- Velvet Crumbs demo
- Future client and trial installations

Each site receives the same software code while retaining its own branding, business data and credentials. Third-party connections such as Square, QuickBooks, Twilio, SMTP, Instagram/Meta and Telegram must be configured separately by each business.

## Release history

See [`docs/RELEASES.md`](docs/RELEASES.md) for the maintained release summary and [`latest.json`](latest.json) for machine-readable current-version information.

## Product preview

<div align="center">
  <img src="docs/assets/batchly-plugin-details.svg" alt="Batchly product details preview" width="100%">
</div>

## Author

Batchly is created and maintained by **Persiano Dish**.
