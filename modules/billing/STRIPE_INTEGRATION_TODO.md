# Stripe integration: remaining setup

This file is the single source of truth for what is left to do before Stripe card payments go live
on the ordering site (`modules/billing`, https://order.gameserverengine.com).

## Values to Replace

There are no placeholder values in the code. `mode` and `line_items` are built from each panel invoice:

**Files containing these values:**
- [includes/stripe_billing.php](includes/stripe_billing.php) (`stripe_checkout_params()`)

| Field | Current Value | What to Set |
|-------|--------------|-------------|
| mode | `subscription` for game server rentals (renew automatically), `payment` for service invoices and terms longer than one year | Nothing to change. |
| line_items[].price_data | Built from the invoice: its description, price per billing period and term (1 day to 1 year) | Nothing to change. Prices come from Billing > Game Catalog. |

## Configured Parameters

These parameters were configured in Stripe Checkout Studio and are set for the embedded payment form.

**Files containing these parameters:**
- [includes/stripe_billing.php](includes/stripe_billing.php)

| Parameter | Value |
|-----------|-------|
| ui_mode | `form` (needs Stripe API version `2026-03-25.dahlia`, sent only on the session create call) |
| billing_address_collection | `auto` (`required` when Stripe Tax is switched on) |
| phone_number_collection | `{ enabled: false }` |
| automatic_tax | `{ enabled: false }` by default; follows the "Charge sales tax with Stripe Tax" setting |
| payment_method_collection | `always` (subscriptions only) |
| submit_type | `auto` |
| integration_identifier | `custom_embedded_web_0001` |

Parameters kept on purpose (not Studio settings): `customer`, `client_reference_id`, `metadata`,
`subscription_data` / `payment_intent_data`, `invoice_creation`, `discounts` and `return_url`. They tie each
payment to its panel invoice, order and server, so provisioning and renewals work.

## Setup

1. **Keys.** Production has no billing key file, so put the keys in a root-owned env file the web server can read:
   ```
   sudo install -d -m 750 -o root -g www-data /etc/gse-billing
   sudo install -m 640 -o root -g www-data /dev/stdin /etc/gse-billing/stripe.env <<'EOF'
   STRIPE_PUBLISHABLE_KEY=pk_test_...
   STRIPE_SECRET_KEY=sk_test_...
   STRIPE_WEBHOOK_SECRET=whsec_...
   EOF
   ```
   (With a billing key file set up, the keys can be saved in Billing > Settings > Stripe instead. Never put keys in code or in the repository.)
2. **Database.** Run the billing module update to database version 16 (adds the Stripe tables and columns).
3. **Webhook.** In the Stripe dashboard (Developers > Webhooks), add the endpoint
   `https://order.gameserverengine.com/stripe/webhook.php`, **pinned to API version `2024-06-20`**, with these events:
   `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `invoice.paid`,
   `invoice.payment_failed`, `customer.subscription.deleted`, `charge.refunded`.
   Copy its signing secret into `STRIPE_WEBHOOK_SECRET`.
4. **Check.** Billing > Settings > Stripe should say "Ready (test mode)"; press "Check Stripe connection".

## Files

| File | What it does |
|------|--------------|
| `classes/StripeClient.php` | Stripe API calls (no SDK), webhook signature check |
| `classes/StripeGateway.php` | Refunds through Stripe |
| `includes/stripe_config.php` | Loads the keys (settings or env file), saves the settings |
| `includes/stripe_billing.php` | Checkout Session parameters, Stripe customers, webhook event handlers |
| `api/stripe_checkout.php` | Starts checkout for an invoice (embedded form or hosted page) |
| `stripe/webhook.php` | Webhook endpoint |
| `stripe/return.php` | Where the customer lands after paying |
| `cart.php` | "Pay by card" with the embedded form (falls back to Stripe's hosted page) |
| `admin_config.php` | Billing > Settings > Stripe section |
| `module.php` | Database version 16 |

## How it works

1. The customer presses **Pay by card** in the cart. `api/stripe_checkout.php` creates a Checkout Session for that invoice
   (a subscription for a game server) and the embedded form appears on the page.
2. After paying, Stripe sends the customer to `stripe/return.php`, which confirms the payment straight away. Stripe also
   sends `checkout.session.completed` to the webhook. Either one marks the invoice paid (only once), creates the order,
   links the Stripe subscription and starts building the server.
3. Each renewal, Stripe charges the saved card and sends `invoice.paid`; the panel records a paid renewal invoice and
   moves the server's expiry date forward. If a charge fails, Stripe retries; if it never succeeds the server is
   suspended at expiry by the daily billing job.
4. Cancelling in Billing > Cancellations stops the Stripe subscription (at the end of the term or straight away);
   refunds go back through Stripe and `charge.refunded` updates the invoice.

## Testing

In test mode use these cards with any future date and any CVC:

| Card | Result |
|------|--------|
| 4242 4242 4242 4242 | Payment succeeds |
| 4000 0025 0000 3155 | Asks for 3D Secure authentication |
| 4000 0000 0000 9995 | Declined (insufficient funds) |

Stripe test clocks (Billing > Subscriptions in the dashboard) can move time forward to see a renewal.
The automated checks are in `tests/StripeBillingTest.php`.

## Next steps

- Switch on Stripe Tax after adding your tax registrations in the Stripe dashboard.
- Customize Stripe's receipt and invoice emails (Settings > Emails) and Smart Retries (Settings > Billing).
- Going live: replace the keys with live ones (roll the test secret key first, it was shared in chat), add a live
  webhook endpoint, and set the mode to live.

## Resources

- https://support.stripe.com
- https://docs.stripe.com/mcp
