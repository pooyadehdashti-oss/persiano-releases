# Batchly 0.52.0

## Order correspondence

- Email, SMS and operational system activity are grouped by WooCommerce order rather than by contact/channel.
- Existing message records that already contain an order ID are migrated into order threads during the database upgrade.
- Email, SMS and System remain filters and badges inside one chronological order timeline.
- Outgoing messages created from an order stay in that order's history.
- Incoming SMS replies are linked to the most reliable recent order context; ambiguous messages remain in an **Unassigned** queue rather than being guessed.
- Unassigned correspondence can be assigned and merged into an order manually.
- Order creation, status changes, payment completion and refunds are recorded as system events.
- Customer-facing WooCommerce transactional emails sent after this update are recorded in the order timeline.
- The WooCommerce order edit screen includes an **Order Correspondence** summary and a link to the complete history.
- Other Hub modules can record communication through `Persiano_Hub_Customer_Messages::record_order_message()`.

## Multi-business customization

- New **Business Profile & White-Label Settings** page for:
  - internal Hub name
  - customer-facing and legal business names
  - business type
  - logo, tagline and brand colours
  - support email, phone, website, address and service area
  - Order, Customer and Product terminology
  - visible dashboard areas for a smaller trial-business workspace
- **System & Tools** cannot be hidden, preventing a remote trial site from losing access to branding, integrations or updates.
- Business profiles can be exported/imported as JSON without customer data, orders or API credentials.
- Profile settings now flow through customer correspondence, transactional email branding, campaign emails, account invitations, consent text, event emails, advance-order messages, invoices, labels, fulfilment defaults, POS identity and selected printed operational documents.
- Persiano's historical sample tasting event is not seeded on a white-label site with another business name.
- Each installation keeps its own profile and operational settings when the common plugin package is updated.

## GitHub updates

- The built-in updater supports a shared GitHub Releases repository for all trial installations.
- Public release repositories need only `owner/repository`; private repositories can use a fine-grained read-only token.
- Repository and token may alternatively be supplied through `PERSIANO_HUB_GITHUB_REPOSITORY` and `PERSIANO_HUB_GITHUB_TOKEN` constants.
- The required release asset is `batchly-vX.Y.Z.zip` with a top-level `persiano-hub/` directory.
- `developer/github/release-batchly.yml.example` creates or updates a GitHub Release whenever a `v*` tag is pushed.
- `tools/build-release.sh` creates the same correctly nested ZIP locally.

## Important limitations

- Gmail or other mailbox replies are not imported automatically. Inbound SMS is captured through the configured Twilio webhook.
- WooCommerce transactional emails sent before version 0.52.0 cannot be reconstructed automatically; tracking begins after installation.
- Dashboard-area controls simplify navigation but do not act as security permissions. WordPress roles/capabilities still determine access.
- This package has been statically validated and packaged, but has not been installed on the live Persiano Dish site or an external trial site.

## Compatibility

- WordPress 6.5+
- PHP 7.4+
- WooCommerce 8.0+
- High-Performance Order Storage declared compatible
