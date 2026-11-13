# Billing module (storefront)

The billing module is the customer storefront for game server hosting. It lives in `modules/billing` inside the GameServer Engine panel repo and can be served on its own virtual host.

The module is required: the panel does not let it be disabled. Install steps, with the DNS record and the Apache and nginx virtual hosts, are in `INSTALL.md` and at https://gameserverengine.com/storefront.html.

## Rebranding

Change the name and company in `site_config.local.php` (per install, not in git), or in `site_config.php` for a default that goes with the code:

```php
'brand' => [
    'name'    => 'Your Store Name',
    'company' => 'Your Company',
],
```

Anything not set falls back to the defaults in `includes/brand.php`. To change the logo, replace the PNG files in `images/brand/` and keep the same file names.

## Configuration (`site_config.php`)

| Key | Purpose |
|---|---|
| `base_path` | Storefront base URL path |
| `panel_path` | Path to the panel installation |
| `paypal_key_file` (set in `site_config.local.php`) | Encryption key for the stored PayPal secret. Keep it outside the web root, readable by the web server only. |
| `panel_base` | Panel address for the Control Panel link and shared pictures |
| `brand` | Name, company, tagline, email and Discord link (see above) |

`site_config.php` is tracked in git and holds the defaults. `site_config.local.php` is gitignored and overrides it on each install. `site_config.example.php` lists the keys.

## PayPal

- Set up in the billing admin, PayPal section. Mode is `sandbox` or `live`.
- Enter the client ID and secret, then press **Test connection**. The secret is stored encrypted with the key in `paypal_key_file`.
- Webhook path defaults to `/paypal/webhook.php`. The full URL is the site base URL plus that path.
- Refunds go through PayPal.

## Orders and invoices

- Customers order from the catalog, which creates an invoice first (see `INVOICE_FIRST_FLOW.md`).
- Payment is captured through `api/capture_order.php`. The invoice is then marked paid and the service is activated by `classes/BillingLifecycleService.php`.
- Gateways live in `classes/`: PayPal, Stripe and manual. `GatewayFactory` chooses one.

## Lifecycle (cron)

`cron-shop.php` runs once a morning. It handles renewal invoices, expiry notices, suspension at expiry and deletion after the grace period. Expiry and deletion only apply to services that have an expiration date. Admin servers are not billed.

## Documentation in this folder

- `INSTALL.md`: domain, virtual hosts, payment and cron checklist
- `INVOICE_FIRST_FLOW.md`: how orders become invoices and services
- `INVOICE_SYSTEM.md`: invoice fields and statuses
- `PANEL_INTEGRATION.md`: how the storefront uses the panel
- `COUPON_SYSTEM.md`: coupons
- `PAYPAL_DEBUGGING_GUIDE.md`: PayPal troubleshooting
- `docs/`: per-game setup docs shown on the storefront

## Tests

`tests/BillingLifecycleServiceTest.php` covers the lifecycle service. Run it with the PHP CLI.
