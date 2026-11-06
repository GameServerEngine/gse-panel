# Gameserver Engine Installer - Differences from Original GSP `install.php`

## Overview

`install.php` in this repository is a customized installer for the
**Gameserver Engine Panel**. It is based on the original
GSP installer at
the legacy GSP project but has been adapted for the GSE environment.

---

## Key differences

### 1. Default table prefix: blank

The original GSP installer defaults to `gsp_`. The GSE Panel installer defaults
to a blank table prefix. You may enter a prefix during installation when a
prefixed schema is intentionally required.

### 2. Config file now includes `$db_port`

The generated `includes/config.inc.php` includes a `$db_port` variable:

```php
$db_host="HOST";
$db_port="3306";
$db_user="USER";
$db_pass="PASSWORD";
$db_name="DATABASE";
$table_prefix="";
$db_type="mysql";
```

Existing config files that predate this installer and lack `$db_port`
continue to work because the parameter defaults to `NULL` (MySQL default
port 3306).

### 3. MySQLi connection uses the port

`includes/database_mysqli.php` – `GSPDatabaseMySQL::connect()` – now
accepts an optional `$db_port` argument and passes it to `mysqli_connect()`:

```php
$this->link = mysqli_connect($db_host, $db_user, $db_pass, $db_name, $port);
```

`includes/helpers.php` – `createDatabaseConnection()` – likewise accepts
and forwards `$db_port`.

All panel entry points (`home.php`, `index.php`, `gsp_api.php`,
`server_status.php`, `cron/cron-shop.php`,
`modules/billing/includes/panel_bridge.php`) pass
`isset($db_port) ? $db_port : NULL` when calling
`createDatabaseConnection()`.

### 4. Initial admin account created during install

After modules are installed, the installer creates the requested initial admin
account using the existing `GSPDatabaseMySQL::addUser()` method, which stores
passwords as `MD5` to match the current login system. If that admin username
already exists it is **not** duplicated.

### 5. No prerequisite checks

Step 0 shows a welcome screen and language selector only. The original GSP
installer checks for required PHP extensions, Pear, etc. GSP skips those
checks because the deployment environment is pre-validated by our bootstrap
scripts.

### 6. Shared install engine

The browser installer calls `includes/install_engine.php` for config generation,
database reset, canonical schema creation, built-in module registration, default
settings, game config import, admin creation, and lock/state file creation.

The downloadable/root path is `scripts/install-panel.sh`. It installs Debian or
Ubuntu packages, downloads and extracts the selected release ZIP archive, repairs
permissions, detects a browser host IP when possible, and prints the browser
installer URL. Database, admin, vhost/domain, SSL, reinstall/recovery, and final
install options are completed in `install.php`. Permission repair uses
group-writable files and setgid directories. New shell bootstraps default to the
`stable` release channel and `/var/www/html/panel`.

The prerequisites-only helper is `scripts/install-panel-prereqs.sh`. It installs
host packages and enables Apache modules when appropriate, but does not run the
Panel installer, create/drop databases, write credentials, download release
archives, or modify Panel config.

### 7. Canonical fresh-install schema and built-in module registration

Fresh installs and recovery create the current GSE schema through the shared
canonical schema path. The installer imports the base schema with the selected
table prefix, applies current GSE schema guards for built-in tables such as
global port allocation, billing lifecycle, update metadata, and Server Content,
then registers shipped modules in the `modules` table at their current
versions. Fresh installs seed the automatic port allocation block size setting
as `port_block_size = 10`.

The fresh default module set is intentionally limited to the core Panel surface:
Module Manager, Server Manager, Settings, Game Monitor, Config Games,
Administration, Game Servers, User Admin, Update, Dashboard, Cron, FTP, LiteFM,
Register, Lost Password, Support, Server Content Manager, Steam Workshop,
Billing, and Subusers. Legacy or specialty packages such as Support Tickets,
TShock, TS3 Admin, TeamSpeak 3, LGSL, DSI, and Reseller are kept on disk for
manual installation where appropriate, but they are not registered and do not
create default top-level menus.

TShock should eventually be exposed as a Terraria-specific action from Game
Monitor or server actions after its context and token handling are hardened. LGSL
and DSI should likewise be treated as contextual server status/image actions,
not global navigation defaults.

Built-in modules are part of the product. They are not installed one by one
through the browser installer, and the browser installer does not call the
legacy `install_module()` function for shipped modules during fresh install or
recovery. Admin/runtime enable-disable behavior remains in the Panel module
manager. Historical module migrations remain for upgrades of existing installs;
fresh installs create the current schema directly instead of replaying the
module installation flow.

### 8. Reinstall table reset and `gsp_` migration

The installer is an explicit install/reinstall action. During that action it
connects to the configured database, verifies that `SELECT DATABASE()` matches
the configured database name, enumerates base tables only, and drops existing
tables that match the configured table prefix before module schema creation.

If the configured table prefix is `gsp_`, only `gsp_` tables are dropped. If a
different non-empty prefix is configured, only tables with that prefix are
dropped. Views, procedures, and other non-table objects are not dropped.

If the table prefix is blank, the engine detects known unprefixed Panel tables
instead of treating every database table as a Panel table. A fresh blank-prefix
install with no unprefixed Panel table collisions continues without a reset
confirmation. A blank-prefix reinstall that would drop existing unprefixed Panel
tables requires explicit confirmation. Unrelated or unrecognized tables are not
dropped automatically.

The drop runs under `SET FOREIGN_KEY_CHECKS = 0` and restores checks afterward.
If any drop fails, installation stops before schema creation. The installer
reports the table names that were dropped and never prints database passwords.

If the database already contains legacy tables prefixed with `gsp_` and the
configured prefix is different:

* For each `gsp_X` table, if the corresponding `{configured_prefix}X` table
  does **not** exist, it is renamed to `{configured_prefix}X`.
* If `{configured_prefix}X` already exists, the rename is skipped silently.
* The installer never aborts due to pre-existing tables.

This allows upgrading an existing GSP installation to GSP without losing data.

### 9. Browser form preservation

If database, config, schema, or admin validation fails in the browser installer,
the form is re-rendered with safe values preserved from the attempted submit:
database host, port, username, database name, table prefix, admin username, and
admin email. Password fields are intentionally blank after errors and are not
stored in HTML.

The admin password prompt states `Minimum 8 characters`, matching shared engine
validation.

### 10. Installation lock and web reinstall authorization

Successful installs write `includes/install.state.json`. Anonymous access to
`install.php` is locked only when the installer can also verify a complete
installation: readable config, successful database connection, core tables
(`users`, `settings`, `modules`, `remote_servers`, and `server_homes` with the
configured prefix), required global port allocation tables (`port_pools`,
`port_pool_games`, `game_port_offsets`, `static_port_reservations`, and
`home_port_blocks`), and at least one admin user.

If config or installer state exists but schema/admin creation is incomplete,
the browser installer shows a partial-install recovery screen instead of a
permanent lock. The recovery screen stays inside the modern installer UI,
summarizes missing required tables without printing raw database secrets, and
provides visible actions to return to settings, run dependency checks, or retry
install/recovery. When the saved config can connect to the database, the retry
form reuses the saved database settings without displaying the database
password and prompts only for the initial admin credentials and the blank-prefix
table reset confirmation when needed.

Browser install/recovery output is captured into the installer result panel
instead of being dumped as plain text. Success shows clear actions to open the
Panel or log in. Failure shows a clear error summary plus buttons for settings,
dependency check, and retry/recovery.

Browser reinstall requires:

- an existing authenticated panel admin session
- a short-lived filesystem gate at `includes/install.maintenance`

Root/sudo CLI execution through `scripts/install-panel.sh` counts as
authorization.

### 11. CLI working directory and language loading

The CLI wrapper changes into the validated Panel base before including legacy
installer code. Language loading resolves `lang/` from the Panel base directory,
not from the caller's shell directory. If `lang/` is missing, directory scanning
returns an empty list and logs a diagnostic instead of throwing a PHP fatal.

This prevents failures such as:

```text
opendir(lang/): Failed to open directory: No such file or directory
readdir(): Argument #1 ($dir_handle) must be of type resource or null, false given
```

### 12. Shell bootstrap prompts

- `Release source/channel` defaults to `stable`; `unstable` is available for
  development and test installs, and `custom` prompts for a release ZIP URL.
- `Install path` defaults to `/var/www/html/panel`.
- Custom `Release ZIP URL` must be an HTTP(S) ZIP archive or a local `file://`
  ZIP for validation. Channel URLs are:
  `https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip` or
  `https://git.runlevelsystems.com/GSE/Panel/archive/unstable.zip`.

The shell bootstrap does not ask for MySQL host, MySQL port, database name,
database user, database password, table prefix, admin username, admin email,
admin password, vhost/domain, or SSL. After archive extraction and permission
repair, it prints a boxed completion message with the URL to open in a browser,
for example `http://SERVER-IP/panel/install.php`.

### 13. Browser installer prompts

- `Panel Domain / Hostname` records the intended public hostname or IP.
- `Use a dedicated web server vhost for this domain` records whether the Panel
  should be served from its own vhost.
- `Use HTTPS / SSL for the public Panel URL` records the desired public scheme.
- `Panel database host/IP (optionally host:port)` accepts `host:port` for
  domains and IPv4 addresses, such as `mysql.example.com:3307` or
  `127.0.0.1:3307`. The parsed port is stored as `$db_port`. Use `[IPv6]:port`
  for IPv6 with a port, or enter bracketless IPv6 as the host and provide the
  port in the separate prompt.
- Database ports must be numeric and in range `1-65535`.
- `Table Prefix` defaults blank.
- `Admin Username`, `Admin Email`, `Admin Password`, and confirmation create the
  initial administrator account.
- `Confirm dropping existing unprefixed Panel tables` preserves the existing
  reinstall/recovery safety behavior for blank-prefix installs.

### 14. Current required dependencies

`php-xmlrpc` is required by the current Panel-to-Agent XML-RPC transport in
`includes/lib_remote.php`. PEAR (`php-pear`) is required because
`Crypt/XXTEA.php` requires `PEAR.php`. XML-RPC may be replaced in a future
transport change, but it is required for current operation.

Git is no longer required for the normal Panel installer/update path. It may
remain installed for developer workflows or legacy game-management tasks, but
release ZIP install/update avoids Git checkout ownership and `safe.directory`
problems in web roots owned by `www-data`.

### 15. MD5 password hashing (legacy)

The `GSPDatabaseMySQL::addUser()` method stores passwords using `MD5()`.
This is legacy behaviour inherited from GSP and matches the existing panel
login system. MD5 is cryptographically broken for new systems; however,
changing the hashing scheme requires coordinated changes to the login code
(`index.php`, `modules/register/`, etc.) and is outside the scope of the
installer. Operators are strongly advised to audit and upgrade the hashing
scheme in a follow-up change.

### 16. Branding

The installer title and default site settings reference **GSP – Game Server
Panel** and **WDS** instead of "GameServer Panel".

---

## Running the installer

1. For a Debian/Ubuntu bootstrap from stable, run
   `sudo bash scripts/install-panel.sh`. For unstable, run
   `sudo bash scripts/install-panel.sh --channel unstable`. For a custom test
   archive, run
   `sudo bash scripts/install-panel.sh --release-zip-url https://example.test/panel.zip`.
2. For prerequisites only, run `sudo bash scripts/install-panel-prereqs.sh`.
3. Open the URL printed in the boxed completion message, or for a manual/browser
   install, upload/deploy the panel files and open `install.php`.
4. Ensure `includes/config.inc.php` is writable (or does not exist yet).
   The web server user must be able to write `includes/` and any existing
   `includes/config.inc.php`. On `/var/www/html/panel-test`, use
   `www-data:www-data`, set directories to `2775`, writable files to `664`,
   sensitive config/state files to `660`, and keep deploy user `dev` in the
   `www-data` group.
5. Open `https://yoursite/install.php` in a browser.
6. Select your language and run the dependency check.
7. When required checks pass, click **Install Now**.
8. Enter the intended domain/vhost/SSL choices, database credentials, install
   options, and initial admin credentials. Database names may contain letters,
   numbers, underscores, and hyphens.
9. The installer writes config, drops existing configured-prefix tables in the
   selected database, migrates tables (if needed), creates the canonical schema,
   registers built-in modules, creates the admin account, and writes
   `includes/install.state.json`.
10. Secure `includes/config.inc.php` and `includes/install.state.json` with mode
   `660` when browser repair/config writes are expected, or tighter local policy
   if the web server never needs to write them after install.

## Test deployment permissions

For `/var/www/html/panel-test`, the expected model is Apache-owned and
group-writable:

```bash
sudo usermod -aG www-data dev
sudo chown -R www-data:www-data /var/www/html/panel-test
sudo find /var/www/html/panel-test -type d -exec chmod 2775 {} +
sudo find /var/www/html/panel-test -type f -exec chmod 664 {} +
sudo chmod 660 /var/www/html/panel-test/includes/config.inc.php
sudo chmod 660 /var/www/html/panel-test/includes/install.state.json
```

This avoids deploy-created `dev:www-data` files without setgid inheritance and
keeps Apache able to write installer/runtime files without world-writable modes.

---

## Rollback

* To re-run the bootstrap from CLI, use `sudo bash scripts/install-panel.sh`.
  Existing `includes/config.inc.php`, `upload/`, `cache/`, `log/`, and `temp/`
  paths are preserved when release files are copied into an existing Panel path.
  Then open the printed browser installer URL to complete install/recovery.
* To re-run from the browser, log in as an existing admin and create the
  short-lived `includes/install.maintenance` gate first.
* If the wrong prefix was chosen, edit `includes/config.inc.php` manually or
  re-run `install.php`.
* Renamed (`gsp_` → configured prefix) tables can be manually renamed back with
  `RENAME TABLE configured_X TO gsp_X` in MySQL.
