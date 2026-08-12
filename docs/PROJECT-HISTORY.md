# Batchly Project History

This document records how Batchly evolved from internal Persiano Dish operating tools into a reusable business operations platform. It intentionally keeps the full path: idea, implementation, trial, failure, fix, and refinement.

## Why this history matters

Batchly is being shaped by real operational use. The most useful development record is therefore not only a release changelog, but the chain of events that caused each feature to exist.

A useful timeline entry should capture:

- Problem or observation
- Idea / proposed solution
- Decision
- Implementation
- Real-world test
- What failed or was confusing
- Fix / iteration
- Current status
- Possible future use as product or marketing story

## Timeline

### Early stage — Persiano operational tools

The system began as practical tools for running Persiano Dish: recipes, ingredient costing, production planning, orders, payments, customer communication, labels and fulfilment.

The central design principle emerged naturally: operational data should connect instead of being maintained in separate places.

### July 22, 2026 — repository foundation

The Persiano releases repository was established. This marks the beginning of a formal software release and distribution structure.

### Late July 2026 — operations become a connected system

Recipes, costing, manual orders, advance orders, Square / WooCommerce workflows, payment links, fulfilment and customer handling began operating as related parts of one system.

The project direction shifted from custom Persiano tools toward a reusable operating platform.

### Early August 2026 — Hub expands

Messages evolved into a unified customer-care workflow combining email, SMS and order activity, with conversation state, unread handling, pinning, internal notes, order linking and notifications.

Labels evolved from basic printing into product-driven label content, sheet positioning, barcode handling and dedicated printer workflows.

POS, manual orders and advance orders began exposing the need for different front-end workflows over the same underlying order and customer data.

### August 6, 2026 — Batchly becomes a product

Batchly received a formal product identity, branding, documentation and its own distribution direction.

The software began to be treated as something another business could install, rather than only a Persiano-specific internal tool.

### August 6, 2026 — update infrastructure trial and error

The update system went through several real iterations:

1. Initial plugin release/update channel
2. Update metadata refinements
3. ZIP publishing workflow
4. Separate Core and theme release channels
5. WordPress update download fixes
6. Release-channel cleanup
7. Multisite permission fix

This is an important product-development story: moving from "works on my site" to "can be safely distributed and updated elsewhere."

### August 7, 2026 — integrations broaden

Google Reviews via Make was introduced while direct Google API access remained dependent on external approval. Square, Twilio, email and WooCommerce increasingly became integrations around Batchly's internal workflow rather than isolated features.

### August 9–11, 2026 — real operations drive feature design

A number of important features came directly from live Persiano orders:

- Customer substitutions and deductions led to item-adjustment handling.
- Customers paying later led to customer accounts, ledgers and statements.
- Weekly payment arrangements led to statement scheduling and allocation logic.
- Product leaving the business before payment exposed the need to track fulfilment and payment independently.
- Tip and refund edge cases exposed the need for one operator-facing refund workflow while preserving separate accounting components.
- Real correspondence led to account-level conversations and case workflow.
- Whole lamb and whole chicken purchasing led to the Whole Animal Breakdown / Yield Costing concept.

### August 11, 2026 — customer account and statement workflow

The account system reached a meaningful operational stage:

- Customer overview and order/payment history
- Consolidated statement creation
- Invoice selection
- Credits / discounts
- Partial payments
- Allocation back to invoices
- Payment receipts
- Tip handling separate from principal
- Statement copies and invoice attachments
- Correspondence snapshots in Messages

Real tests found and drove fixes for statement consolidation, tax presentation, receipt behaviour and conversation routing.

### August 11, 2026 — fulfilment and payment separated

A key operating-model decision was implemented: fulfilment state and payment state are independent.

Examples:

- Order may be Delivered while still Unpaid.
- Order may be Paid while still Preparing.
- Fulfilment quick actions should never capture or modify payment.

This is intended to become a reusable workflow model across Batchly.

### August 11, 2026 — current testing chapter

Confirmed or largely working:

- Manual orders
- Customer ledger
- Consolidated statements
- Statement email + invoice attachments
- Payment allocation
- Partial payments
- Separate tips
- Fulfilment/payment separation
- Quick fulfilment status actions
- Message archive of sent correspondence

Known current issues / refinements:

- Refund flow has inconsistent amount pre-population and needs a reliable unified principal + tip workflow.
- Currency entities such as `&#36;` can render visibly in adjustment rows and correspondence snapshots.
- Address picker is still not working reliably.
- Account-level correspondence can reopen / attach to previously completed conversations in ways that need cleaner lifecycle rules.
- Editing an order currently drops the user into the WooCommerce order screen, breaking Batchly workspace continuity.

## Product philosophy emerging from the timeline

1. Keep users in Batchly whenever possible.
2. Separate operational state from financial state.
3. Record every meaningful customer interaction.
4. Treat WooCommerce as the underlying commerce engine, not the primary staff workspace.
5. Use real operational pain as the source of product requirements.
6. Preserve trial-and-error history instead of hiding it; the failures explain why the current design exists.

## Marketing / storytelling use

The same timeline can later be converted into public-facing content using this narrative format:

**Problem → Idea → Prototype → Used at Persiano → Failed / learned → Improved → Batchly feature**

Persiano Dish can therefore serve as Batchly's first real-world case study.