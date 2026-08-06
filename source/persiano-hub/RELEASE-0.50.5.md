# Batchly 0.50.5

Focused existing-order payment recovery patch.

- Existing unpaid WooCommerce orders can be opened in the tested Batchly payment screen without creating a duplicate order.
- The pending payment page now uses full Cash and E-transfer forms, including tip, cash received, and change due.
- Cash and E-transfer reuse the same proven POS AJAX handlers as ordinary POS sales.
- Payment completion updates the same WooCommerce order, payment method, ledger, stock, invoice, and status.
- Existing order totals, items, customers, taxes, and fulfilment details are preserved.
