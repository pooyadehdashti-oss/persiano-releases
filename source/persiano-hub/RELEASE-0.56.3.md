# Batchly 0.56.3 — Core and Theme Separation

- Separates Batchly Core updates from storefront theme updates.
- Removes the Batchly theme from the Core updater and release requirements.
- Adds Theme API 1.0 with stable helpers, hooks and public REST endpoints.
- Makes the business-profile and theme-integration layer available without WooCommerce.
- Treats WooCommerce as the current optional commerce adapter rather than a theme requirement.
- Keeps all existing WooCommerce-backed operational features unchanged when WooCommerce is active.

This release establishes the compatibility boundary. It does not yet replace the existing WooCommerce-backed product and order storage with a fully native Batchly commerce engine.
