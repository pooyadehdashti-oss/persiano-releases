# Batchly Roadmap

Status legend: DONE / TEST / FIX / NEXT / IDEA / LATER

## Immediate — ready to work on

### FIX — Refund workflow
- One clear refund action for staff.
- Correctly pre-populate refundable principal and refundable tip.
- Preserve principal/tip accounting separately underneath.
- Support partial refund without forcing full refund.
- Keep refund status and correspondence consistent across Orders, Payments, Customer Accounts and Messages.

### FIX — Currency / HTML entity rendering
- Never expose encoded entities such as `&#36;` to staff or customers.
- Normalize money display in WooCommerce order rows, adjustment rows, emails, Messages snapshots, statements and receipts.

### FIX — Address picker
- Restore reliable address selection/entry in order/customer workflow.
- Avoid duplicate entry where an existing customer address can be reused.

### FIX — Conversation lifecycle
- Account correspondence should not unexpectedly reopen an unrelated completed order conversation.
- Define separate lifecycle for order conversations and customer-account conversations.
- Keep case status predictable after outgoing email/SMS.

### TEST — New master regression order
Create one clean test order and deliberately exercise:
- customer lookup / creation
- address picker
- item substitution
- deduction
- positive adjustment
- tax recalculation
- order email
- payment link
- POS payment
- tip
- fulfilment status changes independent of payment
- statement inclusion
- partial payment
- refund principal
- refund tip
- messages/correspondence history
- cancellation

## NEXT — Batchly-native Order Workspace

Problem: Orders are visible in Batchly, but editing opens the WooCommerce admin order page. Staff then leave the Batchly workspace and must navigate back to continue Batchly work.

Direction: WooCommerce remains the commerce/data engine, while Batchly becomes the staff-facing workspace.

### Proposed Batchly Order Workspace

A persistent Batchly order screen or drawer with:

**Order header**
- Order number
- Customer
- Total
- Payment state
- Fulfilment state
- Date / requested fulfilment time
- Primary quick actions

**Tabs / sections**
1. Order
2. Items & Adjustments
3. Customer
4. Payment
5. Fulfilment
6. Messages
7. Notes / Activity
8. Documents

### Editing strategy
- Common order edits happen directly inside Batchly.
- Advanced WooCommerce-only fields can be exposed behind an `Advanced / WooCommerce` action.
- When WooCommerce must be opened, prefer modal/new-tab/deep-link patterns that do not destroy Batchly context.
- Preserve the selected customer/order/filter/scroll position when returning.

### Longer-term direction
Use one persistent workspace shell so Dashboard, Orders, Messages, Payments, Customers, Production and Publishing feel like one application rather than separate WordPress admin pages.

## NEXT — UI/UX cleanup pass

### Information architecture
Review:
- top navigation
- WordPress Batchly menu/submenus
- duplicate destinations
- naming consistency
- order of menu items
- separation between daily operations and configuration/admin tools

Possible high-level grouping:

**Operate**
- Dashboard
- Orders
- POS
- Payments
- Customers
- Messages
- Fulfilment

**Produce**
- Production
- Recipes & Costing
- Labels
- Inventory / Ingredients

**Sell & Publish**
- Products
- Publishing
- Offers
- Events
- Reviews

**Manage**
- Reports
- Integrations
- Settings
- System & Tools
- Updates

### Visual consistency
Standardize:
- page titles and subtitles
- cards
- tabs
- button hierarchy
- status badges
- form spacing
- field widths
- tables
- filters
- empty states
- success/error notices
- destructive actions
- icon use and tooltips
- mobile/tablet density

### Form design
- Group fields by task rather than database structure.
- Progressive disclosure for advanced fields.
- Reduce long vertical admin forms.
- Keep save/primary action visible.
- Use inline validation.
- Prefer pickers/search/autocomplete over manual IDs.

## IDEA — Whole Animal Breakdown / Yield Costing

This idea applies to lamb, chicken and other whole-animal purchases.

### Problem
Buying a whole animal can be significantly cheaper, but assigning the original purchase $/kg equally to every resulting cut produces misleading recipe costs and margins.

### Proposed model
Record a parent purchase/batch:
- supplier
- animal/type
- purchase date
- purchase weight
- total purchase cost
- processing/butchering cost
- waste/loss

Record actual breakdown yields:
- cut / output ingredient
- actual weight
- yield percentage
- usable quantity
- optional quality/grade
- optional market/replacement value

### Cost-allocation methods
Support selectable allocation rules:
- weight-based
- relative market-value based
- manual allocation
- hybrid/value-weighted

Allocated child costs must reconcile exactly to the total parent acquisition/processing cost.

### Integration
Generated cuts become inventory lots/ingredients with their allocated unit costs. Recipes consume those costs normally. This allows accurate margin reporting without pretending neck, shank, leg, ribs, breast, wings, etc. all have identical economic value.

### Future possibilities
- whole chicken breakdown templates
- lamb/carcass templates
- expected vs actual yield
- trim/bone/fat/by-product tracking
- stock transfer from carcass to cuts
- profitability comparison: whole animal vs individual cuts
- supplier/yield history

Status: IDEA / design captured; not yet implementation priority.

## LATER — Commercial readiness
- permissions / roles
- onboarding wizard
- tenant/business configuration
- generic demo site
- documentation/help
- telemetry/diagnostics with appropriate privacy controls
- backup/migration strategy
- security review
- performance review
- accessibility review
- localization
- plugin marketplace/readiness assessment

## Working principle
Before adding another isolated screen, ask whether the action belongs inside an existing Batchly workspace. Navigation continuity is now a product requirement.