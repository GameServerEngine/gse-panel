# Database Schema Audit

Audit date: Jul 11, 2026.

Scope: documentation-only verification gate before Phase 2. Work was limited to `Panel` documentation. The live schema source was `/home/dev/Projects/GSP/panel.sql`; `Panel/panel.sql` was inspected only as the repository baseline.

## Verification Status

Status: PASS for documentation/audit. No schema or PHP changes were made.

The uploaded live dump is current enough for this verification gate: its phpMyAdmin header says `Generation Time: Jul 11, 2026 at 11:23 PM`, and the filesystem timestamp is Jul 11, 2026 18:24 local time. It materially differs from `Panel/panel.sql`: live has 76 `CREATE TABLE` blocks, the repo baseline has 60, and live contains production-only billing, update, SSO, agent telemetry, and Workshop/content tables.

## Sources Inspected

- `/home/dev/Projects/GSP/AGENTS.md`
- `README.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/REPOSITORY_STRUCTURE.md`
- `docs/COMMAND_FLOW.md`
- `docs/UPDATE_POLICY.md`
- `docs/modules/billing.md`
- `docs/deployment/billing-cron.md`
- `/home/dev/Projects/GSP/panel.sql`
- `panel.sql`
- `modules/billing/PHASE1_COMPLETE_SUMMARY.md`
- `modules/billing/module.php`
- `modules/billing/classes/BillingRepository.php`
- `modules/billing/classes/BillingService.php`
- `modules/billing/add_to_cart.php`
- `modules/billing/api/capture_order.php`
- `modules/billing/checkout_free.php`
- `modules/billing/paypal/webhook.php`
- `modules/billing/includes/payment_processor.php`
- `modules/billing/create_servers.php`
- `modules/billing/cron-shop.php`
- `modules/billing/admin_orders.php`
- `modules/billing/admin_invoices.php`
- `modules/billing/admin_payments.php`
- `modules/billing/adminserverlist.php`
- `modules/billing/my_account.php`
- `modules/billing/my_servers.php`
- `modules/billing/serverlist.php`
- `modules/billing/create_invoices_table.sql`
- `modules/billing/fix_invoices_table_columns.sql`
- `modules/billing/migration_to_invoices.sql`
- `modules/billing/sql/002_billing_checkout_fixes.sql`
- `modules/billing/normalize_billing_order_status.sql`
- `modules/billing/add_paypal_data_column.sql`
- `modules/billing/create_coupons_table.sql`
- `modules/user_games/module.php`
- `modules/user_games/admin_billing_migration.sql`
- `modules/user_games/billing_integration.php`
- `modules/user_games/edit_home.php`
- `modules/user_games/check_expire.php`
- `includes/database_mysqli.php`
- `includes/database.php`
- `includes/install_engine.php`
- `home.php`
- `index.php`

## Confirmed Active Fields

- `gsp_billing_invoices.payment_status` is active and should remain the canonical payment status for repository-based checkout. It is read by `modules/billing/cart.php` and `modules/billing/classes/BillingRepository.php`, and written by `modules/billing/api/capture_order.php`, `modules/billing/paypal/webhook.php`, `modules/billing/checkout_free.php`, and admin invoice actions.
- `gsp_billing_invoices.status` is still active for cart removal, legacy webhook/payment lookup, and compatibility. It must not be removed in Phase 2.
- `gsp_billing_invoices.billing_status` is active for renewal lifecycle. It is written by `modules/billing/cron-shop.php`, `modules/billing/add_to_cart.php`, and `modules/billing/checkout_free.php`.
- `gsp_billing_invoices.home_id` is active for renewal invoices and cron joins. It is read and written by `modules/billing/cron-shop.php`, `modules/billing/api/capture_order.php`, and repository helpers.
- `gsp_billing_invoices.period_start`, `period_end`, `subtotal`, `total_due`, `rate_type`, `rate_per_player`, and `players` are active in add-to-cart, checkout, cron, and admin views.
- `gsp_billing_orders.status` is active as the provisioning queue state. `modules/billing/create_servers.php` selects only `status='Active'`.
- `gsp_billing_orders.home_id` is active as the order-to-server link. Provisioning writes it; cron clears it when deleting expired homes.
- `gsp_billing_orders.paypal_data` exists live and is conditionally supported by `modules/billing/classes/BillingRepository.php`.
- `gsp_billing_transactions` is active as a payment audit table. It is ensured and written by `modules/billing/classes/BillingRepository.php`, `modules/billing/api/capture_order.php`, and `modules/billing/checkout_free.php`.
- `gsp_server_homes.billing_status`, `billing_enabled`, `next_invoice_date`, `last_invoice_id`, `billing_expires_at`, `billing_price`, `billing_rate_type`, `billing_players`, and `billing_invoice_sent_at` are active. Cron, provisioning, repository helpers, and gamemanager module schema guards reference them.
- `gsp_billing_services.remote_server_id` is active and stores a comma-separated allowed-node list. The selected node is copied to `gsp_billing_orders.ip`.
- `gsp_users.user_id`, `users_login`, `users_passwd`, `users_role`, `users_group`, `users_fname`, `users_lname`, and `users_email` are active for login, identity, billing customer data, and notifications.
- `gsp_modules.db_version` is active and drives module schema upgrades.

## Likely Obsolete Or Legacy-Only Fields

These are not safe to remove in Phase 2 without broader runtime testing:

- `gsp_billing_orders.status='in-cart'` is a legacy default. Active invoice-first checkout inserts into invoices first and creates orders after payment, but the default remains in live schema and older flows/docs refer to it.
- `gsp_billing_orders.status='paid'` appears in older docs and tests. Active provisioning requires `Active`; normalize scripts already map legacy values.
- `gsp_billing_service_remote_servers_deprecated_backup` is explicitly preserved by `modules/billing/module.php` db_version 5. It is a backup of a deprecated mapping table and should not be deleted during Phase 2.
- `gsp_users.users_phone`, `users_city`, `users_province`, `users_country`, `users_comment`, and `user_receives_emails` had limited or no billing references in this audit. They are user-profile fields and need a separate user-admin audit before removal.

## Duplicate Or Overlapping Fields

- `gsp_billing_invoices.status`, `payment_status`, and `billing_status` overlap by name but serve different active purposes. Do not collapse them in Phase 2.
- `gsp_billing_orders.end_date`, `gsp_server_homes.next_invoice_date`, `gsp_server_homes.server_expiration_date`, and `gsp_server_homes.billing_expires_at` overlap as lifecycle dates. Current cron uses `next_invoice_date` to generate renewal invoices and `server_expiration_date` for expiry/deletion; repository helpers also use `billing_expires_at`.
- `gsp_billing_orders.ip` stores a remote server id, not an IP address. This is misleading but active and should not be renamed without a compatibility migration.
- `gsp_billing_invoices.ip` also stores the selected remote server id for invoice-first order creation.
- `gsp_billing_services.remote_server_id` is a comma-separated list, while `gsp_remote_servers.remote_server_id` is the normalized remote-server table key.

## Schema Drift

Live-only tables compared with `Panel/panel.sql`:

- `gsp_agent_resource_snapshots`
- `gsp_billing_config`
- `gsp_billing_paypal_errors`
- `gsp_billing_paypal_webhook_events`
- `gsp_billing_service_remote_servers_deprecated_backup`
- `gsp_billing_transactions`
- `gsp_panel_update_log`
- `gsp_server_content_install_history`
- `gsp_server_content_manifest`
- `gsp_server_content_workshop`
- `gsp_server_content_workshop_catalog`
- `gsp_sso_tokens`
- `gsp_steam_workshop_game_profiles`
- `gsp_steam_workshop_server_mods`
- `gsp_steam_workshop_server_settings`
- `gsp_update_patches`
- `ogp_ban_list`

Material live-vs-baseline column drift:

- `gsp_billing_invoices` live includes `home_id`, `billing_status`, `rate_type`, `rate_per_player`, `players`, `period_start`, `period_end`, `subtotal`, `total_due`, and `payment_status`; `Panel/panel.sql` lacks those.
- `gsp_billing_transactions` exists live but not in `Panel/panel.sql`.
- `gsp_billing_config` exists live but not in `Panel/panel.sql`.
- `gsp_server_homes` live includes billing lifecycle columns; `Panel/panel.sql` lacks them.
- `gsp_remote_servers.server_os` and `ufw_status` exist live; baseline has older remote-server shape.
- `gsp_billing_services.out_of_stock` is `tinyint(1) NOT NULL DEFAULT '0'` live, while module/repo baseline creates `varchar(255) NOT NULL`.
- `gsp_billing_invoices.coupon_id` allows `NULL` live, while `modules/billing/module.php` baseline creates it as `NOT NULL DEFAULT 0`.
- `gsp_billing_orders.paypal_data` exists live and is now covered by the module install/update path.
- `gsp_billing_invoices` has no live key on `home_id`, although cron filters and joins by `home_id`.
- `gsp_server_homes` has no live keys on `billing_enabled`, `billing_status`, `next_invoice_date`, or `user_id_main`, although cron and UI code filter by those fields.

Module install/update drift:

- `modules/billing/module.php` db_version 1 mostly matches live billing tables, except `gsp_billing_services.out_of_stock` and invoice `coupon_id` nullability.
- `modules/billing/add_paypal_data_column.sql` remains a standalone repair script for older installs; the module db_version path now also covers `gsp_billing_orders.paypal_data`.
- `modules/billing/sql/002_billing_checkout_fixes.sql`, `fix_invoices_table_columns.sql`, and db_version 2/7 guards cover many invoice drift columns, but some standalone SQL files use hardcoded `gsp_` and should be treated as one-off repair scripts, not canonical migrations.
- `modules/user_games/admin_billing_migration.sql` proposes a billing-orders `created_by_admin` column, but live schema does not contain that column and no active PHP reference was found in the audited paths. Do not add it in Phase 2 unless a verified PHP writer/reader is introduced.

## Risky Removals

Do not remove these in Phase 2:

- `gsp_billing_invoices.status`, even though `payment_status` is newer.
- `gsp_billing_invoices.billing_status`, because cron uses it for renewal lifecycle.
- `gsp_billing_orders.status`, because provisioning depends on `Active`.
- `gsp_billing_orders.home_id`, because provisioning and expiry cleanup depend on it.
- `gsp_server_homes.server_expiration_date`, because legacy user_games expiry and billing cron both use it.
- `gsp_server_homes.billing_*` fields, because cron/repository/gamemanager paths are active.
- `gsp_billing_service_remote_servers_deprecated_backup`, because the module intentionally preserves it as a backup.

## Recommended Phase 2 Changes

These recommendations are based only on the verified live schema and PHP usage.

1. Make `gsp_server_homes` authoritative for server billing lifecycle:
   - `billing_enabled = 1` means cron manages the server.
   - `billing_status = 'Active'` means current and service should remain assigned.
   - `billing_status = 'Invoiced'` means renewal invoice exists and payment is due.
   - `billing_status = 'Expired'` means past grace period and pending deletion workflow.
   - `next_invoice_date` is the next renewal invoice generation time.
   - `server_expiration_date` remains the due/expiration deadline used by existing cron and legacy user_games code.
   - `last_invoice_id` points to the current renewal invoice when present.

2. Make `gsp_billing_invoices` authoritative for invoice/payment state:
   - `payment_status`: `unpaid -> paid`, with `cancelled` and `refunded` as terminal admin states.
   - `status`: compatibility/cart payment state; keep in sync with `payment_status` for `due` and `paid`.
   - `billing_status`: renewal lifecycle copy for renewal invoices: `due/Active/Invoiced/Expired`.
   - `home_id`: `0` for not-yet-provisioned invoice; server id for renewals or admin-created homes.
   - `order_id`: `0` until an order exists; set after order creation.
   - `subtotal`, `discount_amount`, `total_due`, `amount`: keep all because active checkout calculates discounts and captures final amount.

3. Make `gsp_billing_orders` authoritative for provisioning queue/order linkage:
   - New paid checkout creates or activates order with `status='Active'`.
   - `create_servers.php` provisions only `status='Active'`.
   - After provisioning, set `home_id` to the created/existing `gsp_server_homes.home_id`.
   - Expiry deletion may set `status='Expired'` and clear `home_id`.
   - Keep `end_date` for compatibility but do not use it as the server lifecycle source of truth.

4. Make `gsp_billing_transactions` append-only payment audit:
   - Insert one completed transaction per captured invoice/transaction id.
   - Preserve `raw_response` as sanitized gateway data only.

5. Add explicit migrations/backfills in Phase 2:
   - `gsp_billing_orders.paypal_data TEXT NULL` has been added to the guarded module migration path.
   - Add index `gsp_billing_invoices.home_id` if missing.
   - Add a composite or separate index for cron on `gsp_server_homes(billing_enabled, billing_status, next_invoice_date)`.
   - Add `gsp_server_homes(last_invoice_id)` if renewal lookups remain invoice-id based.
   - Normalize `gsp_billing_services.out_of_stock` type in module install/update logic to match live `tinyint(1) NOT NULL DEFAULT 0`, or document a deliberate varchar compatibility reason before changing.
   - Normalize `gsp_billing_invoices.coupon_id` nullability decision: either keep live `NULL` and update module create-table, or backfill nulls to `0` and alter to `NOT NULL DEFAULT 0`. Do not do this without checking existing production data values first.
   - Backfill `gsp_billing_invoices.payment_status` from `status` where `payment_status='unpaid'` and `status='paid'`.
   - Backfill `gsp_billing_invoices.status` from `payment_status` for unpaid/paid rows so legacy cart/webhook filters stay coherent.
   - Backfill `gsp_billing_orders.status` using `modules/billing/sql/normalize_billing_order_status.sql` semantics: `paid/active/installed -> Active`, unpaid/cart states must not be provisioned, expired/deleted remain terminal.
   - Backfill `gsp_server_homes.billing_enabled=1`, `billing_status='Active'`, `next_invoice_date`, and `last_invoice_id` only for homes with verified paid/active billing order or invoice linkage.

## Changes That Should Not Be Made In Phase 2

- Do not delete `Panel/panel.sql` or replace it blindly with the live dump.
- Do not delete or rename `gsp_billing_orders.ip`; migrate with compatibility only if application PHP changes are planned.
- Do not remove `gsp_billing_invoices.status`.
- Do not collapse `payment_status` and `billing_status`.
- Do not add a billing-orders `created_by_admin` column solely because `modules/user_games/admin_billing_migration.sql` mentions it; no active PHP usage was verified.
- Do not remove `gsp_billing_service_remote_servers_deprecated_backup`.
- Do not make full app tests or destructive schema operations part of this verification gate.

## Validation Performed

- Parsed every `CREATE TABLE` and column from `/home/dev/Projects/GSP/panel.sql`.
- Compared live table list against `Panel/panel.sql`.
- Inspected module install/update SQL definitions and one-off SQL repair scripts under `modules/`.
- Inspected PHP references in billing, user_games, gamemanager, cron, includes, and entry-point files.
- Confirmed referenced PHP paths in this document exist.
- Confirmed referenced live table/column names in the schema reference exist in `/home/dev/Projects/GSP/panel.sql`.
- Reviewed `git status`, `git diff`, and recent commits.

## Machine-Readable Mapping

No machine-readable JSON mapping was created. The detailed markdown reference already contains the complete parsed live table/column inventory and the focused owner/reference mapping; a JSON file would duplicate it without adding clear value for Phase 2.
