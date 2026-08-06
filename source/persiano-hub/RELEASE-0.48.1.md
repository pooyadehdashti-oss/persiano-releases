# Batchly 0.48.1

Correction release:
- Replaces WordPress user-bound Square callback nonce with a persistent order-bound random callback token.
- Allows the Square callback route to validate securely even when the Square in-app browser does not share the WordPress login session.
- Preserves exact order-bound verification; no amount-only matching.
- Adds automatic status polling on the payment page so it refreshes when the order is paid or reaches a terminal status.
- Corrects the plugin version constant to 0.48.1.
