# Where configuration lives (decided 2026-10-22)

- All configuration for every module lives in the panel's Administration area. This includes billing,
  the storefront settings, payment settings, and the game catalog.
- The storefront keeps only one thing of its own: customer account creation.
- Modules are built into the panel and are only enabled or disabled (see the unified-database plan).
- Module menus are stored in module_menus. A module's admin pages are listed there, so the menu shows
  them under Administration, not in a separate storefront menu.

Open: login-page verification for test automation, and measuring the Panel Settings page with a real
logged-in session (earlier timings were taken logged out and are not valid).
