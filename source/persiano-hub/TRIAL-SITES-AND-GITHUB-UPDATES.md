# Trial sites and centralized GitHub updates

## Deployment model

Install the same Batchly ZIP on every trial site. Keep each business's identity, credentials, customers and orders in that site's own WordPress database. Do not fork the plugin for each business.

Per-site values that survive plugin updates include:

- Business Profile and visible dashboard areas
- Twilio credentials, From number and webhook token
- Square credentials, location and webhook configuration
- email settings and quick replies
- fulfilment, tax and WooCommerce settings
- products, customers and orders
- order correspondence history

## Preparing a trial business

1. Use a staging site or a dedicated trial site, not the business's only production site.
2. Install WooCommerce and configure the site's timezone, currency, taxes and email delivery.
3. Install the latest `batchly-vX.Y.Z.zip` package.
4. Open **Hub → System & Tools → Business Profile**.
5. Enter the business identity, contact information, logo, colours, terminology and service area.
6. Hide dashboard areas that are outside the trial scope.
7. Configure only the channels included in the trial, using credentials belonging to that business or trial account.
8. Send an email and SMS test and create a test order before involving real customers.
9. Open **Hub → System & Tools → Updates** and enter the common GitHub release repository.

A Business Profile can be exported from one site and imported into another. The export contains branding and workspace choices only; it does not contain API secrets, customers or orders.

## Recommended repository structure

For external trial users, the simplest setup is a **public release-only repository**. It can contain release notes and compiled ZIP assets without exposing the private development repository.

Example:

- Repository: `your-account/persiano-hub-releases`
- Stable release tag: `v0.52.0`
- Required asset: `batchly-v0.54.1.zip`
- Optional theme asset: `batchly-theme-vX.Y.Z.zip`

Point every trial site to the same repository from **Hub → Updates**. Newer semantic versions then appear through the normal WordPress update system.

## Private repository option

A private repository requires a GitHub token on every trial site. Use a fine-grained token restricted to the one repository with read-only Contents access. Do not use a broad personal token and do not send the token by email or place it in a profile export.

For a small external trial, a public release-only repository is operationally simpler because no shared token needs to be distributed or rotated.

## Optional wp-config constants

The updater can be centrally preconfigured in `wp-config.php`:

```php
define( 'PERSIANO_HUB_GITHUB_REPOSITORY', 'your-account/persiano-hub-releases' );
// Only required for a private repository:
define( 'PERSIANO_HUB_GITHUB_TOKEN', 'github_pat_...' );
```

When these constants are not defined, each site can store the repository and optional token through the Updates screen.

## Publishing a release manually

1. Build the package:

   ```bash
   ./tools/build-release.sh 0.52.0
   ```

2. Create a GitHub release with a tag matching the plugin version, such as `v0.52.0`.
3. Upload the resulting ZIP as `batchly-v0.54.1.zip`.
4. Publish it as a stable release, not a draft or prerelease.
5. On a trial site, choose **Hub → Updates → Check for Updates Now**.
6. Install it from the normal WordPress Plugins or Updates screen.

## Automated release workflow

The example at `developer/github/release-batchly.yml.example`:

- runs when a `v*` tag is pushed
- verifies that the tag matches the plugin header version
- creates a correctly nested WordPress plugin ZIP
- creates the GitHub Release or replaces the asset when the release already exists

Copy it to the release repository as:

`.github/workflows/release-batchly.yml`

The repository root should contain `persiano-hub.php`, `includes/`, `assets/`, `templates/` and the other plugin files.

## Packaging rule

The ZIP must contain a top-level `persiano-hub/` folder:

```text
batchly-v0.54.1.zip
└── persiano-hub/
    ├── persiano-hub.php
    ├── includes/
    ├── assets/
    └── templates/
```

Do not place `persiano-hub.php` directly at the archive root. A wrongly nested package can cause WordPress to install into a different directory and break future updates.

## Trial safety checklist

- Create a backup before every update.
- Test the release on one staging/trial site before updating all participants.
- Give each business separate Twilio and Square credentials.
- Keep webhook URLs and tokens specific to each installation.
- Never include `wp-config.php`, database exports, `.env` files, API keys or customer exports in a GitHub Release asset.
- Obtain the business's approval before using real customer contacts for a test.
- Document the feedback window and what support is included in the trial.
