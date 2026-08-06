# Batchly 0.51.0 setup

## 1. Install
Upload `persiano-hub-v0.51.0-email-square-sms.zip` in WordPress Plugins and replace the current Batchly version. The update creates the customer-message table automatically.

## 2. Email
Open **Batchly → Customers & Sales → Email & SMS → Settings**.

- Confirm the From name and From address.
- The From address must be permitted by the SMTP account already connected to WordPress.
- Send a one-to-one test email from Customer Messages.

## 3. Twilio SMS
In the same Message Settings page, enter:

- Twilio Account SID
- Twilio Auth Token
- Twilio From number, or a Messaging Service SID
- Optional SMS signature and quiet hours

Copy the displayed **Incoming message webhook** into the Twilio phone number or Messaging Service configuration and use HTTP POST. Batchly returns an empty TwiML response, so do not configure a separate standard auto-reply.

Outgoing SMS automatically includes the displayed delivery-status callback. Start with strict signature validation off; after inbound and status callbacks work through the host/proxy, enable it and test again.

For a Twilio trial account, outgoing SMS can be tested only with numbers allowed by that account.

## 4. Square
Open **Batchly → System & Tools → POS & Square**.

- Confirm the production Application ID, Location ID and Production Access Token.
- In Square Developer Console, create a production webhook subscription using the displayed webhook URL.
- Subscribe to `payment.created`, `payment.updated`, `refund.created` and `refund.updated`.
- Copy that subscription’s signature key into Batchly and save.

Then open **Customers & Sales → Square Transactions** and run **Sync Square**.

## 5. Verification checklist

1. Send an email to yourself from Customer Messages.
2. Send an SMS to an approved test number, reply, and confirm the reply appears in the same conversation.
3. Confirm SMS delivery status changes from queued/sent to delivered where supported.
4. Create a small Square payment and run Sync Square.
5. Confirm the transaction links only to the WooCommerce order carrying the same saved Square identifier.
6. Test a small refund on a linked test order before using refunds on live customer orders.
