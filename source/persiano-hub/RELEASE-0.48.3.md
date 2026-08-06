# Batchly 0.48.3

Correction release focused on POS cancellation synchronization and customer invoices.

- Cancelled, failed, and refunded orders now replace the tablet/computer handoff with the correct terminal state.
- Cancelled payment pages no longer display an unrelated Square-setup warning.
- New POS orders store Batchly POS origin, in-person channel, device, and cashier metadata.
- POS invoice email uses a direct branded message with a secure customer invoice link and clear send/failure reporting.
- WooCommerce My Account gains an Invoices area and invoice action for each customer order.
- Customer invoice links are protected by the WooCommerce order key.
- Optional BCC of customer-facing WooCommerce and Persiano invoice emails is enabled by default for info@persianodish.com and can be changed in POS Settings.

True background Web Push is not included in this release.
