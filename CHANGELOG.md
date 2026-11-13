# Changelog

Each fixed issue gets an entry under the version that shipped it: a one-line title, then the cause and the fix in plain language, written so a customer can follow it.

## 2.8.10 (2026-11-12)
- **Stable releases:** Pushing to the `stable` branch now publishes a stable-<version> release (a full release, not a prerelease) of the version already on the branch, so the Update page's stable choice has something to install. Before, only testing and working builds were published.

## 2.8.8 (2026-11-12)
- **Update page listed files from a removed module as yours:** After a release dropped the `bugreport` module, its files kept showing under "Files you edited". The update list now ignores manifest entries for files a release no longer ships, so only files you really changed appear. The first update after this one may still list them once; leave them unchecked.
- **Remote Agents:** The Servers menu entry and module are now called Remote Agents. The action buttons on the list are all the same size and wrap onto a second row when the window is narrow.
- **Port Blocks page simplified:** The page now explains what it does instead of showing a compatibility note. It has a button per agent plus Global, a Game port ranges list (first port and number of ports, default 100, global or per agent), a Disabled ports list (agent port, remote desktop and so on), a Game servers list that includes running servers and says whether each fits the rules, an agent filter, and the Allocation Events log. New servers are given ports inside the ranges; existing servers keep their ports, and one button records them without moving or restarting anything.
- **Storefront:** Expired servers are hidden on My Account until toggled. The support page has contact details (email and Discord) above the cards, an Account Info card, a scrolling Server Status frame, open tickets above the ticket form with replies newest first, and tickets for visitors who are not signed in (email required, a cookie shows their reply when they return). The AI Assistant menu item is hidden. Every page has the same dark header band under a spacer row, and the home banner and hero sit on one black band.

## 2.8.6 (2026-11-12)
- **Installer: database reachable to game hosts.** The MySQL/MariaDB server gse-panel-install.sh sets up now listens on every network interface (not just localhost), opens the port in ufw when ufw is active, and installs phpMyAdmin. Root still only logs in locally through the Unix socket; no root account exists for remote hosts.
- **One-file installer:** `gse-panel-install.sh` (attached to every release) installs Apache, PHP and its extensions, can install a local MySQL (Ubuntu) or MariaDB (Debian) server with an empty panel database, downloads the newest stable release (or the newest testing release when there is no stable one), checks its SHA-256 and unpacks it to `/var/www/html/panel`. The database and admin account are then entered on the web setup page. Tested on Ubuntu 22.04 (PHP 8.1, MySQL 8.0), Ubuntu 24.04 and Debian 12 (PHP 8.2, MariaDB 10.11).
- **Game databases on that server use lower-case table names:** the installer sets `lower_case_table_names=1` before the database server is first installed (MySQL 8 only reads it then), so game databases work the same for Linux and Windows game servers. An existing database server is not changed; the installer warns when it has another setting.
- **Add a server before its agent is installed:** the Servers page now saves a server whose agent is not running yet (with the agent user it will run as), instead of refusing it. Each server must have its own agent key, 1 to 16 characters.
- **Agent installers connect to the panel:** new `agent_enroll.php` answers the agent installers. They sign each request with the agent key (the key is never sent); the panel answers with that server's settings and the agent release its update settings pick, and when the agent is running it saves the agent's user and IP addresses.
- **Web setup asks only what it needs:** the setup page no longer asks for domains, a storefront domain, vhosts or HTTPS; only the database and the first admin account.
- **Game databases work on MySQL 8.0:** Creating or changing a game database user used `GRANT ... IDENTIFIED BY`, which MySQL 8.0 removed, so on 8.0 the database was never created and the user got an error. The panel now creates the user with `CREATE USER` and `ALTER USER` using `mysql_native_password` (so old game clients such as Arma hives can still log in), then grants access. This works on MySQL 5.7.8 and 8.0. Removing a user no longer fails when the user is already gone.
- **Schema installs on MySQL 5.7:** The schema file named the 8.0-only collation `utf8mb4_0900_ai_ci` on 24 tables, so a fresh install on 5.7 created only 63 of 87 tables. The collation is now left to the server default, which is the same collation on 8.0 and a working one on 5.7.
- **Requirements:** `requirements.json` now gives the minimum as Ubuntu 22.04, Debian 12 or any OS with PHP 8.1 or newer. PHP 8.3 is what the panel is tested on; every file also passes a syntax check on PHP 8.1 and 8.2.
- **One listing per game on the storefront:** Each game config file now shows as one card on the storefront and one row on the Game Catalog tab. Before, a game showed a separate copy for each platform it was saved under. The stored data is unchanged.
- **Game pictures on the storefront:** Each game card loads its own picture from the panel's game images folder, and the Game Catalog preview uses the same picture.
- **Control Panel link goes to the panel:** The Control Panel link, the support ticket links and the admin sign-in redirect now point to the panel's own address instead of a `/panel/` path on the storefront site.
- **Billing tabs are buttons:** The tabs on the Billing page are now buttons, and the page opens on Settings instead of Invoices.

## 2.8.5 (2026-11-11)
- **All billing administration is now in the control panel:** Administration has one Billing page with tabs for Invoices, Orders, Payments, Cancellations, Game Catalog, Locations, Coupons and Settings. The old Billing Setup and Billing Operations entries are replaced by it (the panel update changes the menu), and old links to them still open the Billing page. The website no longer has its own admin pages: opening one sends you to the panel.
- **Website admin and container pages removed:** The website's admin page, the old per-agent pricing page, the XML editor and everything for containers (customer container list, container actions and the container admin page) are gone. The container database tables are left as they were.
- **Placement headroom moved:** The CPU, RAM and disk limits that decide which agent a new server is placed on are now set on the Game Catalog tab, since the old pricing page that held them is removed.
- **Games chosen in the panel show on the storefront:** The import tool on the Game Catalog tab can use the agents that were saved on each old service, so games that were already set up for an agent appear without ticking every agent again. Preview shows what will be added first.
- **Support tickets from the website:** The Support page has an Open a Ticket form for signed-in customers. It adds the ticket to the control panel's tickets (the same ones staff already answer under Support), with an optional choice of one of the customer's own servers, and lists the customer's tickets with a link to each in the panel.
- **Storefront restyled to match GameServer Engine:** The billing website now uses the GameServer Engine look: the same logo, dark header and footer with orange accents, Inter font and hero artwork, on a light page. Every page's content now sits in one white content section, including pages that used to carry their own dark or purple backgrounds (sign in, password reset, cart, documentation, order, payment success). The AI Assistant page had no header, footer or styling at all and now has them. The payment success page now shows the site header and footer. The old stylesheet's unused rules and the old globe logos were removed. The storefront no longer loads the panel's Bootstrap theme files.
- **Support page ticket form restored:** The Open a Ticket form from the earlier 2.8.5 work was not in the committed page, only its helper code. The form, ticket list and the optional server choice are now in `support.php`, and the page falls back to the contact details when the ticket tables or database are not reachable.
- **My Servers page works again:** The customer's server list read two tables that no longer exist (`home` and `game_configs`), so it could not list anything. It now reads the current server and game tables, and shows each server once with its newest order instead of repeating a server for every order the customer has.

## 2.8.4 (2026-11-11)
- **Game Catalog page for the storefront:** Billing has a new Game Catalog tab. Open a game to set its display name, description and picture, its price (per slot, tiers, or base plus extra slots), the slots customers can pick, and which agents it is sold on. Games you publish now show up on the storefront catalog and can be rented. The storefront was empty because nothing had been published and the page for doing it had been left out of the menu. A one-time import copies the old services list onto the agents you pick.
- **Billing Setup page easier to read:** The PayPal settings and diagnostics used dark grey text that was almost invisible on the dark panel. The text now follows the panel colours and is slightly larger.

## 2.8.3 (2026-11-11)
- **Edited-files review on the update page:** Nothing is checked by default, so unchecked files are overwritten by the install. Checking a folder checks every edited file under it, and a check-all box is in the header. Commit `048a8c47`.

## 2.8.2 (2026-11-11)
- **Fixed bot.def error preventing start:** Starting a server no longer checks for a manually made `bot.def` file in the server folder. Start had been refusing to run when that file was present. The panel now writes its own bot settings only when Server Bot content is installed, updated, or turned off. Commit `ec7d8091`, `79f548c5`.
- **Server Bot changes no longer write bot.def:** The Windows agent reads Server Bot settings from `_alsoRun.cmd`, so enabling, updating, or disabling Server Bot content no longer creates or checks `bot.def`. Commit `ec7d8091`.

## 2026-10-20
- **Scheduler v2, first phase:** The new scheduler now has its own data tables, a fixed list of allowed actions, and a lock that stops two conflicting actions from running on the same server. Run history can be viewed in the panel and through the API, and the new code is covered by 24 tests. Commit `fa897752`.
- **ntfy notifications module:** Added a notifications module with a customer subscribe page and an installer step. The installer step was tested on its own in a throwaway container. Commit `ae368a50`.
- **Design documents:** Wrote design documents for the new scheduler and the customer AI assistant. They also record decisions on AI log handling, plan limits, scheduled stop, and ntfy. Commit `e87e3198`, `d35a32d2`.
- **Port cross-over investigation:** Looked into servers appearing to share ports and found the database layout is correct, with ports tracked per IP address. The queries that might cause the problem are listed in the to-do file. No fix has been made yet.
- **Notification topic names:** A change to give each notification topic a random suffix was started and then reverted. It needs a token table and updates to the code that uses topics first. Topics are still named `gse-<home_id>`.
- **Old panel copy removed:** Removed a stale, unconfigured copy of the panel from the development host. The test panel is still available at `https://dev.isaserver.net/panel/`.


## 2026-05-19
- **Workshop content install handling:** Installing Workshop content now follows the same checks everywhere in the panel and API. The Server Content installer asks for the Workshop ID, app, and path, and shows a live preview of the target. On Linux and Windows agents, the install now runs the real SteamCMD download, copy, and post-install steps.
- **Update page diagnostics and older update links:** The update page now shows the stable and unstable branches in use and the temporary folders used during the last update. Older update links now treat `Panel/` and `panel/` the same, so panel files in current GitHub downloads are no longer skipped.
- **Update layout checks and marker files:** The updater now requires the live install folder with separate Panel and Website destinations, and it logs the paths it finds. It stops if the layout is wrong, copies each part to the right place without nesting a folder inside itself, and checks key files after copying. On success it writes a timestamp marker for both the website and the storefront.
- **Workshop script path fix:** Workshop actions were looking for their scripts in the wrong place on the agent host. They now use the scripts in the Server Content module's Workshop folder.

## 2026-05-18
- **Protocol and image upgrade groundwork:** Added a common wrapper for server queries, with the current query library as the default and room for other providers later. Also wrote up the current protocol setup, the migration plan, and a comparison of the server image modules. Nothing existing was removed.
- **Scheduled tasks can run Server Content actions:** The scheduler and user scheduled tasks can now run Server Content actions such as check, install, queue, restart, validate, and backup. The scheduler only triggers them, so game-specific install steps stay in Server Content.
- **Server Content Workshop, first phase:** Added a Workshop Content area to Server Content. Each server keeps its own list of Workshop IDs, which are checked for validity and duplicates, with install, update, remove, and update-all actions. Safe placeholder scripts were added for Linux and Windows, along with the database changes to store this.
- **Updater safety checks and required patches:** The updater now works out the install, Panel, and Website folders and runs required checks before updating. Required patches run in order and are tracked, and the admin Update page offers checks, patches, backup, update, and rollback.
- **Updater self-refresh, backups, and Apache path repair:** The updater now notices when its own files are out of date, refreshes them, and carries on. Backups and rollback cover Panel, Website, and the version file, with old backups cleaned up, and a new tool finds and repairs outdated Apache paths after making a backup and testing the configuration.
- **Storefront moved into the panel with portable paths:** The storefront now runs from the billing module, and its base path and panel path can be set per install. Key redirects and links use the computed storefront address, and the old Website entry points still work.
- **Updater only touches panel files:** When the repository has a panel folder, the updater now uses only that folder as its source. Documents, examples, and scripts at the top of the repository are no longer candidates for overwriting panel files.
- **Registration no longer crashes:** Fixed a fatal error on the registration page and removed old hard-coded redirects. Registration is now logged, duplicate usernames are rejected, and password hashes are stored for newer PHP versions. If the reCAPTCHA keys are missing, outdated, or fail, registration falls back gracefully instead of showing raw errors.

## 2026-05-13
- **Apache site template for the split layout:** Added a ready-to-copy Apache example for both the Panel and the Website. It covers HTTP-to-HTTPS redirects, SSL sites, required modules, Certbot commands, and the steps to check and reload Apache.
- **Separate Apache examples per site:** Added one example file for the panel and one for the website, so each can be copied into Apache's available sites with only small edits.
- **Apache install helper script:** Added a script that copies the Apache examples into place. It can also enable the modules and sites, test the configuration, and reload Apache in one go.

## 2026-05-09
- **Billing sells each OS variant as its own service:** Removed the merging of Linux and Windows services in the storefront and the automatic switching of the operating system at order time. Each enabled service is now sold as listed, and checkout keeps exactly what was chosen. Orders for an incompatible location are refused instead of swapped. While an install is running, the panel now says the install is in progress instead of reporting a missing executable. Also fixed a deprecated function in the server log viewer for PHP 8.3.
- **Provisioning log and retry for existing servers:** Added a visible provisioning log and showed detailed results after checkout. An active order with an existing server is only skipped if its install is complete. Otherwise the missing address and mod details are filled in and the install is started again, with no manual Update Server click.
- **Provisioning runs automatically and only once:** Paid checkout, free checkout, PayPal renewals, and admin-added servers now always hand the order to provisioning. New servers get a 6-character alphanumeric password and FTP turned on, and extra checks and logging cover the service, node, port, and mod. Already installed servers are skipped, so refreshing or retrying no longer creates duplicates.
- **Game Monitor shows IP and port reliably:** When a server's address details were incomplete, the Game Monitor could show no IP and port. It now looks up the missing address and port, so new servers always show them.
- **Documentation links and automatic install retry:** Documentation links in Game Monitor and Support now open the game's page at `https://gameservers.world/docs/` in a new tab. Orders with an existing server now retry their install automatically, and the provisioning log records the order, invoice, user, server, address, and result. PayPal renewals now provision any order that still has no server.
- **Payment success page shows install status:** The payment success page now shows whether each order's install has started, is pending, or failed. Users and admins can see the outcome right away instead of a silent failure.

## 2026-05-08
- **Automatic install and monthly pricing:** New servers from paid, free, or admin-created orders now start their install automatically, the same as the Game Monitor update button, with no manual click. Monthly price is now the main price in admin settings, add-to-cart, free checkout, PayPal, and end-date math, which counts a month as 31 days. Older daily and yearly prices are kept.
- **File manager fixes for PHP 8.3:** Removed outdated code that caused warnings and guarded against missing session values and empty paths. When a server's files are not installed yet, the file manager now shows a clear message instead of a warning.
- **Provisioning and billing admin improvements:** Automatic provisioning now picks ports from the configured port ranges, never gives the same port twice, and keeps the order, invoice, and server linked even while the install is pending. A missing mod choice no longer blocks a base install. The billing admin service list has a save button per row, sortable columns, and clearer save messages. Also polished the login page and the update caution styling, and updated the storefront footer timestamp.
- **Steam Workshop reliability and simpler screens:** Removed command-line instructions from the customer Workshop screens and kept only the supported per-server options. Queued updates now move through queued, updating, and installed or failed, with clearer error messages. Documentation links open the game's page, or the docs index, in a new tab.
- **Storefront docs links:** Documentation links and icons in the storefront now use paths starting from the site root, and old hard-coded panel host advice was removed from the getting-started page.

## 2026-05-07
- **README and storefront fixes:** Rewrote the main README and made the storefront work better on phones, including login, order, cart, and header. Fixed low prices not being kept when adding to the cart, made cart and free-checkout totals use the same amounts, and refreshed the storefront footer timestamp.
- **Billing and cart stability:** Fixed adding to the cart on older installs that lack a period start column. Fixed a free-checkout error when closing the database connection. Cart and free totals now work in cents, so a small price like $0.02 never shows as FREE. Improved how games and OS variants are matched on the storefront, and aligned the Steam Workshop labels with the new restart and update wording.

## 2026-05-06
- **Missing setting labels:** Added English labels and help text for the ban time, CPU affinity, invalid file name characters, and Discord settings. If a language is missing a label, the panel now falls back to English, so settings pages no longer show raw placeholder text.
- **Game config editor redesign:** The game config editor now has a section-based view, where each section can be validated, saved, or reset on its own. Optional sections can be added or removed, required sections are protected, and each section is checked before saving. The detailed editor and the full raw editor are still there for advanced changes.
- **Steam Workshop admin and navigation cleanup:** Reorganized the admin Workshop profile screen into clearer groups. It can now detect and refresh defaults from the game's XML, asking before it overwrites anything. Also fixed the user Workshop link, which had been failing with "Invalid subpage given."
- **Billing admin and provisioning fixes:** The Migrate button now looks like the other server action buttons, and servers added by an admin default to monthly billing with 31-day months. After a paid checkout creates the server, the order, invoice, and transaction records are linked to it, so paid orders no longer keep a server ID of 0.
- **Cart amounts are now correct:** Invoice amounts are now worked out from the chosen slots and duration, and the subtotal and total due are saved. Placeholder passwords are replaced with securely generated ones before anything is saved.
- **PayPal and coupon double-processing fixed:** Each PayPal payment is now tied to the exact invoices being paid. Payment, free checkout, and webhook handling all count a month as 31 days, avoid logging a payment twice, and only start provisioning for orders that have no server yet.

## 2026-05-05
- **Paid orders now create servers automatically:** After a successful PayPal payment or a zero-dollar checkout, an order is now created for each paid invoice and sent to provisioning. Before this fix the order list was never filled in, so provisioning was always skipped and an admin had to create the server by hand.
- **No duplicate servers from retried payments:** Each invoice is now linked to its order as soon as the order is created. If a PayPal payment is retried, there are no unpaid invoices left to process, so a second server cannot be created.
- **Paid invoices no longer return to the cart:** Paying an invoice now marks both its payment status and its status as paid. The cart also ignores any invoice that is paid, cancelled, or refunded.
- **Zero-dollar checkout:** When a coupon brings the total to $0, the cart now shows a Complete Free Order button instead of PayPal. This marks the invoices paid with the coupon, creates the orders, counts the coupon use, and starts provisioning, the same as a PayPal payment.
- **Payment success page error fixed:** The payment success page asked for a game name column that does not exist, which broke the page. It now uses the service name.
- **Billing database update for older installs:** Added a safe-to-repeat update that adds the missing coupon, discount, payment status, subtotal, and total due columns to invoices, and the coupon and discount columns to orders. This fixes older installs that were missing them.
- **Order statuses standardized:** Orders now use only Active, Invoiced, and Expired. The old values (installed, paid, and suspended) are no longer written, and an included script converts existing orders. Older orders are still read correctly until the script is run.
- **Expiration shown as a date only:** The billing expiration on the Game Monitor now shows only the date, with no time.
- **Servers stay active through their last day:** A server whose end date is today stays active for the whole day, and expiration is only processed the next day. The billing cron job, the Game Monitor, and user and group assignment expiry all follow this rule.
- **Version reset to 1.0:** All bundled and core modules now start again at version 1.0. Database versions were not changed.
- **FAQ module refresh:** Restored the online FAQ update from the original source, fixed a startup bug, switched the local cache file, and made it work with PHP 8.3. Also restored the original credits footer and opened the page to users, admins, and subusers.
- **Config XML editor improvements:** Game config changes are now checked before saving, in both the structured editor and the raw editor. Invalid XML is rejected with line-level messages and restored from backup, and fields appear in the expected order with required and optional labels. A raw editing panel was added with a warning, and custom fields you add are kept when only some fields are changed.
- **Obsidian theme:** Added the Obsidian theme, which can be chosen right away in the panel theme settings.
- **Stray backup files removed:** Removed 22 leftover backup and duplicate files from manual editing. The server config backup folder is kept, because the panel uses it.

## 2026-05-01
- **Update backups kept inside the panel:** Panel update backups are now saved in a backups folder inside the panel folder instead of a separate system location. This keeps backups self-contained and needs no extra permissions.
- **Login page heading removed:** Removed the leftover "Dev Testing" heading from the panel login page.

## 2026-05-01
- **Safe panel updates:** Added a safer update system to the administration page. You can update to a numbered release from a newest-first list, to the stable branch, or to the unstable branch, which shows a warning. Each update first makes a full backup of the database and panel files, and a Revert section restores a chosen backup. Config files and the update blocklist are kept. Updates require an admin, are protected against forged requests, and are logged, and the installed version and branch are recorded after each update.

## 2026-04-23
- **PHP 8 compatibility sweep:** Went through the panel's PHP code to fix looping, counting, and key checks that break on PHP 8. Also fixed how dates are handled and put quotes around bare delete, edit, and remove text.

## 2026-01-31
- **Workshop search uses Steam Community pages:** The Steam Workshop picker now searches only through the Steam Community pages, as the working manual method does. The request preview shows the real Steam address instead of a local panel address.
- **Workshop search finds the game's app ID:** The picker can now look up the game's app ID itself, so searches work even when the server's XML does not name a clear installer.
- **Pick several Workshop mods at once:** Search results now have checkboxes, so customers can choose several mods straight from the list.
- **Workshop search fallback:** Added a more robust way to read Steam Community pages. The Steam Workshop service now uses it whenever the official Steam API fails or returns nothing.
- **Workshop search without a shell:** Added a built-in web fetcher that is used when a shell is not available, such as on Windows XAMPP installs. The Game Monitor no longer shows "Unable to contact the Steam Workshop" when only the API is down.
- **Search results show which method was used:** The search response now reports whether the page reader or the API was tried, along with any commands, exit codes, and errors. The Game Monitor can show exactly which one produced the results.
- **Workshop scrape helper included:** Included the helper script used to check the Workshop search, so it can be run on the server later without copying it again.

## 2026-01-25
- **Workshop search uses the official Steam API:** Workshop search now uses the official Steam Web API, so searches are anonymous and paged and no longer rely on reading web pages.
- **Steam API errors are logged:** Steam API failures are now logged in detail. The search response includes paging details for the screen to use.
- **SteamCMD Workshop installer:** Added an installer that downloads Workshop items with an anonymous login, falls back to saved credentials, and saves all output to a log file for each run.
- **Workshop helpers documented:** Documented the new search and install helpers, including how to use them from the panel and from the command line.

## 2026-01-17
- **Per-game Workshop adapters:** Added a screen to manage Steam Workshop settings for each game, with automatic mapping. Admins can create, view, edit, and delete them.
- **Workshop button only where supported:** The Steam Workshop button in the Game Monitor now appears only for SteamCMD servers that support it.

## 2026-01-18
- **Workshop support read from game XML:** Workshop support is now worked out from the installer app IDs in the official game XML files. Admin mappings and Game Monitor buttons now appear only for games that truly support the Workshop.

## 2026-01-19
- **Admin-only install settings:** The staging folder, install strategy, and post-install script settings are now hidden from non-admins, so the panel defaults apply to customers.
- **Workshop mod picker:** Added a mod picker with search, so customers can choose mods without editing raw ID lists. The picker also has refreshed styling.
- **Workshop search works from any page:** The search now calls the panel page that is showing it, so it works in both the main panel and the customer Game Monitor.
- **Install strategy no longer set per server:** Removed the per-server install strategy choice. The admin's adapter settings now decide this.
- **Post-install script no longer set per server:** Removed the per-server post-install script field. Hooks are now set only in the adapter settings.
