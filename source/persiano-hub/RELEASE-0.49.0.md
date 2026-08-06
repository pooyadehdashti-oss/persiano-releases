# Batchly 0.49.0

- Adds payment-method selection after POS order creation: Card/Square, Cash, or E-transfer.
- Cash flow records cash received, optional tip, and change due.
- E-transfer flow records optional tip and transfer reference.
- Card-only pending queue/badge criteria, preventing cash/e-transfer and unassigned orders from triggering payment notifications.
- POS search now hides the full available-products grid and displays only matching results.
- Out-of-stock search results are clearly marked and require confirmation before manual sale.
- Cancelled/unpaid customer orders show an order summary instead of a tax invoice.
- The customer Invoices tab now lists only paid or refunded orders.

True server-delivered Web Push is not included in this build; the existing open-app notification polling remains unchanged.
