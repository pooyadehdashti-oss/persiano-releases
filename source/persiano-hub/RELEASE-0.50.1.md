# Batchly 0.50.1

- Keeps a new Card / Square order in Pending until a payment or a real verification failure exists.
- No longer classifies "no Square payment found yet" as Verification Error.
- Push notification clicks now open the exact payment order, including when the Home Screen app is already running.
- Cache-busts and forces an update of the service worker.
