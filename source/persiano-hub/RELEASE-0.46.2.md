# Batchly 0.46.2

- Fixes front-end POS AJAX URL construction so customer and product search complete instead of remaining on “Searching…”.
- Adds visible errors when a search request fails.
- Adds a ZXing browser fallback for live barcode scanning on iPhone/Safari where the native BarcodeDetector API is unavailable.
- Preserves manual barcode entry when camera decoding cannot start.
