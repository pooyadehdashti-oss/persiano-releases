# Batchly 0.45.20

## Avery format routing hotfix

- Restores the selected `label_format` in the print form POST payload.
- Prevents Avery 5163 jobs from silently falling back to the thermal 3 × 2 template.
- Keeps mixed-product and manual-variant sheet data unchanged.
