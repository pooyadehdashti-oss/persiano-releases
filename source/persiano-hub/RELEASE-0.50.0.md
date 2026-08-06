# Batchly 0.50.0

- Unified active-card query and race-safe polling to stop Payments badge flicker.
- Verification-error, failed, cancelled, paid and refunded orders are excluded from the pending badge.
- Dependency-free PDF invoice generation and PDF attachment on Batchly invoice emails.
- True Web Push subscription for installed Home Screen apps using server-generated VAPID keys.
- Card selection sends a per-order background push; notification opens the exact payment order.
- Push subscription status and a server-side test push control are included.
