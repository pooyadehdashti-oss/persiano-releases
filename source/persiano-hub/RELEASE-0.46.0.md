# Batchly 0.46.0 — Front-end Hub & WooCommerce POS foundation

## Included
- Dedicated authenticated front-end dashboard at `/hub/` without opening WP Admin.
- Responsive computer, tablet, and iPhone layouts.
- Installable home-screen web app manifest.
- WooCommerce-backed New Sale screen.
- Customer lookup by phone, name, or email.
- Guest sales and quick customer creation API.
- Product lookup by name, SKU, Persiano barcode, common UPC/EAN meta fields.
- Mobile camera barcode scanner using the browser BarcodeDetector API when available, with typed fallback.
- Pending WooCommerce POS orders that do not reduce stock until payment completes.
- Cross-device Ready for Payment queue.
- QR handoff from computer/tablet to payment iPhone.
- Square POS mobile-web launch for Tap to Pay on iPhone.
- Square callback handling and WooCommerce payment completion.
- Cash payment and cancellation controls.
- POS settings for Square Application ID, Location ID, and CAD default.

## Setup
1. Update permalinks once if `/hub/` returns 404 (Settings → Permalinks → Save).
2. Open Batchly → POS Settings.
3. Add the production Square Application ID and Square Location ID.
4. Register the shown Square callback URL in Square Developer Console → Point of Sale API → Web.
5. Open `/hub/` in Safari on iPhone and use Add to Home Screen.

## Deliberate first-release limits
- Square tip reconciliation needs live testing against the connected Square account. The callback records the POS transaction ID; detailed fee/tip retrieval through Square APIs is the next integration layer.
- Camera barcode detection depends on browser support. Manual barcode entry remains available.
- USB/Bluetooth scanners are planned to use the same barcode input/lookup in a future update.
