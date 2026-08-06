# Batchly 0.48.0

## Payment reliability
- Square callbacks that arrive before the payment is visible to the API now enter Verification Pending.
- Safe automatic retries run after 5, 15, 45, and 120 seconds.
- Exact WooCommerce order reference matching remains mandatory; amount-only matching stays disabled.
- Payment ledger derives Partially Refunded and Refunded from actual WooCommerce refund totals.
- Added a Verification Pending ledger filter.

## POS product tiles
- Desktop, tablet, and mobile POS now load clickable tiles for currently in-stock simple products and variations.
- Each click adds one unit to the cart.
- Search and barcode lookup remain available and can still retrieve out-of-stock products for authorized manual use.
- Out-of-stock products are not shown as tiles.

## Invoice workflow
- Completed POS payment pages now include Print invoice and Email invoice actions.
- Added legal business name, business address, GST number, phone, and invoice email settings under POS & Square.
- Printable invoices display the configured business identity and GST number.

## Labels
- Avery label barcodes are larger for more reliable camera and hardware scanner reading.
