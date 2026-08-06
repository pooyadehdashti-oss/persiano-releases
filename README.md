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
| **Batchly plugin** | **0.56.2** |
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

## Latest release — 0.56.2

- Adds a canonical WordPress **Update URI** for Batchly.
- Improves the WordPress **View details** modal with a full description, installation instructions, changelog, homepage, icons and banner metadata.
- Uses this repository as the canonical plugin-information and release-details location.
- Includes all Manual Order, label-selection and monitoring-interface fixes from 0.56.1.

## Installation

1. Open the repository's [Releases page](https://github.com/pooyadehdashti-oss/persiano-releases/releases).
2. Download the latest `batchly-vX.Y.Z.zip` plugin package.
3. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
4. Upload, install and activate Batchly.
5. To install the storefront theme, download `batchly-theme-vX.Y.Z.zip` and upload it under **Appearance → Themes → Add Theme → Upload Theme**.

Existing Batchly and former Persiano Hub installations retain their existing products, customers, orders, correspondence, settings and third-party credentials during normal upgrades.

## Automated release publishing

The repository includes a GitHub Actions workflow that can validate and publish Batchly ZIP packages as a GitHub Release. It supports three package sources, in this order:

1. A checked-in `source/persiano-hub` and `source/batchly-theme` tree
2. ZIP files under `release-assets/`
3. Public package URLs supplied when manually starting the workflow

The workflow verifies the ZIP structure before publishing. The plugin ZIP must contain a top-level `persiano-hub/` folder; the theme ZIP must contain a top-level `batchly-theme/` folder.

## Update model

One Batchly release can update Persiano Dish, the Velvet Crumbs demo and future client sites. Each installation receives the same software while retaining its own branding, business data and third-party credentials.

## Release history

See [`docs/RELEASES.md`](docs/RELEASES.md) for the maintained release summary and [`latest.json`](latest.json) for machine-readable current-version information.

## Product preview

<div align="center">
  <img src="docs/assets/batchly-plugin-details.svg" alt="Batchly product details preview" width="100%">
</div>

## Author

Batchly is created and maintained by **Persiano Dish**.
