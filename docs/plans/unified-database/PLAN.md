# Unified database and module toggles (decided 2026-10-22)

## Model
- The panel is one product. Its features (called modules) are built in, not installed.
- Admins can only enable or disable a module. There is no uninstall and no custom install screen.
- Disabled modules keep their data. Enabling a module makes its pages and actions available again.

## Database
- One schema for the whole panel. At install, the installer creates every table that any module needs,
  in one pass, from the SQL files shipped with the panel (core plus all modules).
- Each module's SQL lives with the module (for example modules/billing/sql/install.sql), not in a
  separate install step. The installer reads them all, in a fixed order.
- Upgrades use numbered migrations in sql/migrations/, run by the migration runner and recorded in
  schema_migrations. Migrations are idempotent and never run per module.
- A module's enabled flag is one row in the modules table (the existing module_enabled flag, migration 0005).

## What changes in code
1. Remove the uninstall and custom-install paths from the module manager (the enable/disable part stays).
   Done on fix/modules-enable-only.
2. Move each module's install SQL into one place the installer reads. Keep the migration runner for upgrades.
3. The installer creates the full schema in one run, then sets each module to enabled or disabled from
   the install choices.
4. Modules that cannot be disabled (core: settings, user admin, game manager, update) stay enabled.

## Installation and configuration checks
- Fresh install on an empty database creates all tables with no errors.
- Re-running the installer on an installed panel is blocked (the install lock).
- Disabling a module hides its menu entries and blocks its pages; re-enabling restores them with data intact.
- Upgrading from an older database runs only the new migrations, then stops.

## Open items
- Whether storefront (billing) pages should be hidden automatically when no storefront domain is set.
- Naming: keep "module" in the UI, or call them "features".
