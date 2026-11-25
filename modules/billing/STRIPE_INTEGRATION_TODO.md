# Stripe card payments: setup and how it works

Card payments come built in. A site owner only needs a Stripe account and two keys; nothing is edited by hand.

## Setup (Billing > Settings)

1. Create a Stripe account and fill in the business details in the Stripe dashboard.
2. In **Stripe (card payments)**, paste the **publishable key** and the **secret key** (Stripe dashboard > Developers > API keys) and press **Save Stripe settings**.
   The panel checks the key with Stripe, stores it encrypted, and registers the payment notification webhook
   (`<site>/stripe/webhook.php`) with Stripe automatically. "Payment notifications: connected" confirms it.
3. Set the **Billing schedule**: renewal invoice days (default 7), auto-renew charge days (default 3), delete days (default 7).
4. Schedule the daily billing job (`cron/cron-shop.php`, see `cron/README.md`).

Test keys (`pk_test_` / `sk_test_`) take only test cards. Switch to live keys when ready; roll any key that was ever shared.
If the database login changes, the saved secret key can no longer be read: enter it again.

## Values to Replace

None. `mode` and `line_items` are built from the panel's invoices:

| Field | Value | What to Set |
|-------|-------|-------------|
| mode | `payment` (Pay Now), `setup` (auto-renew card saving) | Nothing |
| line_items | One line per invoice in the cart, with the panel's amount | Nothing |

## Configured Parameters (Checkout Studio, used when "Card form on our cart page" is chosen)

**File:** [includes/stripe_billing.php](includes/stripe_billing.php)

| Parameter | Value |
|-----------|-------|
| ui_mode | `form` (API version `2026-03-25.dahlia` on that one call) |
| billing_address_collection | `auto` (`required` with Stripe Tax on) |
| phone_number_collection | `{ enabled: false }` |
| automatic_tax | follows the Stripe Tax setting (off by default) |
| submit_type | `auto` |
| integration_identifier | `custom_embedded_web_0001` |

The default card page is Stripe's own hosted page.

## How it works

- **The panel owns invoices and receipts.** Stripe only takes payments. Customers never need a Stripe account.
- **Pay Now:** the cart's invoices go to Stripe as one payment, one line per invoice. After paying, the customer
  returns to "Paid, thank you"; the panel marks each invoice paid with Stripe's payment id and builds new servers.
- **Auto-renew (per server, optional):** "Turn on auto-renew" in My Servers sends the customer to Stripe to save a
  card; nothing is charged. The panel keeps only Stripe's references (customer and saved payment method ids), never
  card details. The daily job creates the renewal invoice N days before the due date (the invoice says when it will
  be charged), charges the saved card from M days before, once a day until it is paid, and never on or after the due
  date. Unpaid on the due date: the server is suspended and auto-renew is turned off; it is deleted after the delete days.
- **Refunds** go back through Stripe; a full refund cancels the invoices it paid.
- **Webhook events used:** `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
  `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded` (pinned to API version `2024-06-20`).

## Testing

| Card | Result |
|------|--------|
| 4242 4242 4242 4242 | Succeeds |
| 4000 0025 0000 3155 | Asks for 3D Secure |
| 4000 0000 0000 0341 | Saves, then declines when charged (tests a failed auto-renew) |

`php cron/cron-shop.php --dry-run` shows what the daily job would do without changing anything.
Automated checks: `tests/StripeBillingTest.php`, `tests/BillingScheduleTest.php`.

## Resources

- https://support.stripe.com
- https://docs.stripe.com/mcp
