# Gameserver Engine Panel Installer Guide

Gameserver Engine Panel is the GSE control-plane web application.

## 1. Quick Install

### 1.1 Root shell installer

Debian and Ubuntu are the supported first targets:

```bash
curl -fsSL https://git.runlevelsystems.com/GSE/Panel/raw/branch/unstable/scripts/install-panel.sh -o install-panel.sh
sudo bash install-panel.sh
```

The shell bootstrap prompts only for the release source/channel (or a custom ZIP URL) and install path. New bootstraps default to the `stable` release channel and `/var/www/html/panel`.

It installs the PHP/runtime packages checked by `check.php`, downloads and extracts the selected Panel release ZIP, repairs permissions, detects a browser host IP when possible, and prints a boxed URL for `install.php`. The browser installer then collects domain/vhost, SSL, database settings, admin credentials, and reinstall/recovery options before running the shared PHP install engine. Git may still be installed for developer workflows or legacy game-management tasks, but normal Panel install/update does not use Git.

### 1.2 Prerequisites-only helper

Use this when you want to prepare a host before running the full installer. It installs packages only; it does not create databases, write credentials, download release archives, or run the Panel installer.

```bash
sudo bash scripts/install-panel-prereqs.sh
```

### 1.3 Manual dependencies

```bash
sudo apt update
sudo apt install apache2 mysql-client unzip tar screen sudo git rsync \
    php php-cli php-common php-mysql php-gd php-curl php-mbstring php-zip \
    php-xml php-xmlrpc php-pear libapache2-mod-php -y
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

### 1.4 Permissions

For development and test deployments such as `/var/www/html/panel-test`, use
`www-data:www-data` with group write and setgid directories so Apache and the
deploy user share one writable group. Add the deploy user `dev` to `www-data`:

```bash
sudo usermod -aG www-data dev
sudo chown -R www-data:www-data /var/www/html/panel-test
sudo find /var/www/html/panel-test -type d -exec chmod 2775 {} +
sudo find /var/www/html/panel-test -type f -exec chmod 664 {} +
sudo chmod 660 /var/www/html/panel-test/includes/config.inc.php
sudo chmod 660 /var/www/html/panel-test/includes/install.state.json
```

Repair mode for panel-test:

```bash
sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel-test --owner www-data --group www-data
```

For production, keep the same group-writable pattern only where browser
install/repair or runtime writes are expected. Do not use world-writable modes.
An installing-user ownership model is also supported when the web server and
deploy user share the web-server group:

```bash
sudo chown -R "$USER":www-data /var/www/html/panel
sudo find /var/www/html/panel -type d -exec chmod 2775 {} +
sudo find /var/www/html/panel -type f -exec chmod 664 {} +
sudo chmod 660 /var/www/html/panel/includes/config.inc.php
sudo chmod 660 /var/www/html/panel/includes/install.state.json
sudo usermod -aG www-data "$USER"
```

Repair mode:

```bash
sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel --owner "$USER" --group www-data
```

### 1.5 Browser installer

Manual file-deploy users can still use the web installer:

1. Open `http://your-server/check.php` and review dependency status.
2. Open `http://your-server/install.php`.
3. Fill in domain/vhost, SSL, database credentials, initial admin credentials, and install options.
4. Click **Install Panel** to install.

If validation fails, the browser installer re-renders the form with safe values
preserved: database host, port, username, database name, table prefix, admin
username, and admin email. Password fields are intentionally left blank and must
be re-entered. The admin password must be at least 8 characters; the prompt says
`Minimum 8 characters` before submission.

Fresh installs and partial-install recovery use the canonical GSE schema path.
Built-in modules are registered as part of the base product and can be managed
in the admin UI; they are not installed one by one through the setup wizard.
Historical module migrations remain available for upgrades of existing installs,
including `tools/post_update_cli.php`, but fresh installs create the current
schema directly. Automatic port allocation defaults to `10` consecutive ports
per game server through the global `port_block_size` setting.

The default module registration is limited to the core Panel experience:
Dashboard, Game Monitor, Administration, Settings, Module Manager, Config Games,
Server Manager, Game Servers, User Admin, Update, Cron, FTP, LiteFM, Register,
Lost Password, Support, Server Content Manager, Steam Workshop, Billing, and
Subusers. Support Tickets, TShock, TS3 Admin/TeamSpeak 3, LGSL, DSI, and
Reseller remain available as module folders, but a fresh install does not
register them or show their top-level menu entries.

## 2. Dependency Check

`check.php` is a standalone page that checks the server environment before or after install. It never blocks installation and can be loaded at any time.

Checks include PHP version, PHP extensions, PEAR, writable paths, Linux commands, Apache rewrite support, and optional database connectivity when `includes/config.inc.php` exists.

`php-xmlrpc` and `php-pear` are required for the current release. The Panel still uses XML-RPC in `includes/lib_remote.php` for Panel-to-Agent communication, and `Crypt/XXTEA.php` requires `PEAR.php` for the current encryption helper. XML-RPC may be replaced in a future transport change, but it is required now.

## 3. Updating an Existing Panel Path

For production updates, run the release ZIP installer against the existing Panel path. It downloads the selected archive, validates the payload root, copies application files into place, preserves `includes/config.inc.php`, and keeps runtime directories such as `upload/`, `cache/`, `log/`, and `temp/`.

```bash
sudo bash scripts/install-panel.sh --channel stable --path /var/www/html/panel
php tools/post_update_cli.php
sudo bash scripts/install-panel.sh --repair-permissions --path /var/www/html/panel --owner "$USER" --group www-data
```

For unstable development/test updates:

```bash
sudo bash scripts/install-panel.sh --channel unstable --path /var/www/html/panel
```

For a custom archive in a controlled test:

```bash
sudo bash scripts/install-panel.sh --release-zip-url https://example.test/panel.zip --path /var/www/html/panel
```

`tools/post_update_cli.php` applies the repository's existing module migration mechanism (`db_version` steps in each module's `module.php`) and post-update cleanup for existing installs. Fresh installs do not replay those historical per-module install chains. If the host uses PHP-FPM, Apache worker pools, or opcode caching, reload the relevant service after the script succeeds.

Do not run standalone SQL files after every update unless the current release notes or a module migration explicitly names one.

## 4. Installer Fields

| Field | Default | Description |
|---|---|---|
| Install Path | `/var/www/html/panel` | Shell bootstrap web path when no override is supplied |
| Release Source / Channel | `stable` | Shell bootstrap source. `stable` is the normal install source; `unstable` is available for development/test installs; `custom` prompts for a ZIP URL. |
| Release ZIP URL | derived from channel | Shell bootstrap custom URL only. Stable: `https://git.runlevelsystems.com/GSE/Panel/archive/stable.zip`; unstable: `https://git.runlevelsystems.com/GSE/Panel/archive/unstable.zip`. Use `--release-zip-url URL` only for controlled custom archive tests. |
| Panel Domain / Hostname | empty | Browser installer field for the intended public hostname or IP. |
| Use Dedicated Vhost | off | Browser installer option recording whether the Panel should be served from its own vhost. |
| Use HTTPS / SSL | off | Browser installer option recording whether the public Panel URL should use HTTPS. |
| Database Host/IP | `localhost` | MySQL or MariaDB hostname/IP. `host:port` is accepted for IPv4/domain values such as `mysql.example.com:3307` or `127.0.0.1:3307`. Use `[IPv6]:port` for IPv6 with a port, or enter bracketless IPv6 in the host field and provide the port separately. |
| Database Port | `3306` | Database TCP port |
| Database Name | empty | Target database name. Letters, numbers, underscores, and hyphens are supported. |
| Database User | empty | Database username |
| Database Password | empty | Database password |
| Table Prefix | blank | Prefix for panel tables. Blank/no prefix is the product default. |
| Admin Username | `admin` | Initial panel administrator |
| Admin Email | `admin@localhost` | Initial admin email |
| Admin Password | empty | Initial admin password, minimum 8 characters |

Generated `includes/config.inc.php`:

```php
$db_host="HOST";
$db_port="3306";
$db_user="USER";
$db_pass="PASSWORD";
$db_name="DATABASE";
$table_prefix="";
$db_type="mysql";
```

## 5. Reinstall Flow

Successful installs write `includes/install.state.json`. Anonymous browser access
to `install.php` is locked only when the installer can verify a complete
installation: readable config, successful database connection, core schema
tables, required global port allocation tables, and at least one admin user.

If config or installer state exists after a failed or partial install but the
schema/admin checks are incomplete, the browser installer shows a recovery
screen instead of a permanent lock. From there, return to installation settings,
run dependency checks, or retry install/recovery. If the saved config can still
connect to the database, the retry form reuses those saved database settings
without displaying the database password and asks for the initial admin
credentials again. Schema creation and recovery failures are shown inside the
installer UI instead of as a plain text dead end.

Root/sudo reinstall:

```bash
sudo bash scripts/install-panel.sh
```

Browser reinstall or repair:

1. Log in to the existing Panel as an administrator.
2. Create a short-lived filesystem maintenance gate:
   ```bash
   sudo touch includes/install.maintenance
   sudo chown www-data:www-data includes/install.maintenance
   sudo chmod 660 includes/install.maintenance
   ```
3. Open `install.php` within one hour and follow the wizard.

The installer removes the maintenance gate after a successful run.

## 6. Reinstall Table Reset

During an explicit install or reinstall, the shared installer engine connects to
the configured database and verifies `SELECT DATABASE()` before any destructive
operation. It enumerates base tables only and drops existing tables that match
the configured table prefix, then creates the canonical GSE schema and registers
built-in modules again.

If the table prefix is non-empty, only tables beginning with that prefix are
dropped. If the prefix is blank, the installer detects known unprefixed Panel
tables. A fresh blank-prefix install with no unprefixed Panel table collisions
continues without a reset confirmation. A blank-prefix reinstall that would drop
existing unprefixed Panel tables requires explicit confirmation, and unrelated
tables are not dropped automatically.

The reset uses `SET FOREIGN_KEY_CHECKS=0` while dropping tables and restores
checks afterward. If any drop fails, schema creation does not continue. The
installer reports table names it dropped but never prints database credentials.

## 7. Security Notes

- Passwords are never printed in check.php or installer output.
- If `includes/config.inc.php` cannot be written, fix ownership or group write
  on `includes/` and the config file. For panel-test, expected ownership is
  `www-data:www-data`, directories are `2775`, writable files are `664`, and
  sensitive config/state files are `660`.
- The shell bootstrap no longer asks for or stores database/admin secrets.
- Browser reinstall requires an existing admin session plus the filesystem maintenance gate.
- CLI reinstall requires root/sudo.
- The initial admin password is stored as MD5 to match the legacy panel login system. Upgrade password hashing in a coordinated follow-up.

## 8. Common Installer Failure

If the CLI installer reports `opendir(lang/): Failed to open directory` or a `readdir()` type error, the installer was run with legacy language loading resolving `lang/` from the wrong working directory. Current builds change into the validated Panel base before loading installer code and resolve language files relative to the Panel base. Re-run from the updated checkout:

```bash
sudo bash scripts/install-panel.sh
```
