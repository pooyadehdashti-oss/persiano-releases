# Batchly 0.53.0 — Business Setup Wizard

Adds a resumable eight-step setup wizard for new businesses:

1. Business identity, logo and colours
2. Contact information and social profiles
3. Store, fulfilment and payment defaults
4. Email/SMTP and Twilio SMS
5. Square and QuickBooks preparation
6. Instagram/Meta and Telegram publishing
7. GitHub update channel and trial access
8. Readiness review and launch

Every third-party section includes an embedded step-by-step guide explaining where the business owner finds or generates the required values. Secrets are never displayed after saving and blank password fields preserve existing values.

The wizard writes supported credentials into the existing Batchly settings so the normal integration pages remain the source of truth. QuickBooks and SMTP connection details are staged for their connector modules and can be completed later.
