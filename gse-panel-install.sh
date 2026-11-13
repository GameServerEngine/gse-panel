#!/usr/bin/env bash
# GSE Panel installer for Ubuntu 22.04+ and Debian 12+.
#
# What it does:
#   1. installs Apache, PHP and the PHP extensions the panel needs
#   2. optionally installs a local MySQL (Ubuntu) or MariaDB (Debian) server and
#      creates an empty panel database and database user
#   3. downloads the newest stable panel release from GitHub (falls back to the newest
#      testing release when there is no stable one), checks its SHA-256 and unpacks it
#
# The database connection and the first admin account are entered on the web setup page
# the first time you open the panel. That page writes includes/config.inc.php.
#
# Usage:
#   sudo bash gse-panel-install.sh [--path DIR] [--channel stable|testing] [--zip FILE] [--yes]
#     --path DIR     install folder (default /var/www/html/panel, served as http://<ip>/panel)
#     --channel      stable (default, falls back to testing) or testing
#     --zip FILE     install this local release ZIP instead of downloading one
#     --yes          accept every default without asking (also installs the local database)
set -euo pipefail

REPO="GameServerEngine/gse-panel"
PANEL_DIR="/var/www/html/panel"
CHANNEL="stable"
ZIP=""
ASSUME_YES=0
WEB_USER="www-data"
BACKUP_DIR="/var/lib/gse-panel/backups"
INFO_FILE="/root/gse-panel-install.txt"

die() { echo "ERROR: $*" >&2; exit 1; }
say() { echo "==> $*" >&2; }

# ask "Question" default  -> prints the answer (reads the terminal, so curl | bash works)
ask() {
	local answer=""
	if [ "$ASSUME_YES" = 1 ] || [ ! -r /dev/tty ]; then echo "$2"; return; fi
	read -r -p "$1 [$2]: " answer </dev/tty || answer=""
	echo "${answer:-$2}"
}

while [ $# -gt 0 ]; do
	case "$1" in
		--path) PANEL_DIR="${2%/}"; shift 2 ;;
		--channel) CHANNEL="$2"; shift 2 ;;
		--zip) ZIP="$2"; shift 2 ;;
		--yes|-y) ASSUME_YES=1; shift ;;
		-h|--help) sed -n '2,20p' "$0"; exit 0 ;;
		*) die "Unknown option: $1" ;;
	esac
done
case "$CHANNEL" in stable|testing) ;; *) die "--channel must be stable or testing." ;; esac

[ "$(id -u)" -eq 0 ] || die "Run this installer as root (sudo bash $0)."
[ -r /etc/os-release ] || die "/etc/os-release not found."
. /etc/os-release
case "$ID:${VERSION_ID%%.*}" in
	ubuntu:2[2-9]|ubuntu:[3-9]?|debian:1[2-9]) ;;
	*) die "Unsupported system $PRETTY_NAME. Use Ubuntu 22.04+ or Debian 12+." ;;
esac
case "$PANEL_DIR" in
	/|/bin*|/boot*|/dev*|/etc*|/lib*|/proc*|/root*|/sbin*|/sys*|/usr*) die "The panel cannot be installed in $PANEL_DIR." ;;
esac
[ ! -e "$PANEL_DIR/includes/config.inc.php" ] || die "A panel is already installed in $PANEL_DIR. Update it from the panel's Update page."

# ---------- questions ----------
WANT_DB=$(ask "Install a database server on this machine and create the panel database? (yes/no)" yes)

# ---------- packages ----------
say "Installing Apache and PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q apache2 libapache2-mod-php php-cli php-mysql php-curl php-gd \
	php-mbstring php-zip php-xml php-xmlrpc php-bcmath curl unzip ca-certificates openssl

DB_NAME="gse_panel"; DB_USER="gse_panel"; DB_PASS=""
if [ "$WANT_DB" = yes ]; then
	if [ "$ID" = ubuntu ]; then DB_PKG=mysql-server; else DB_PKG=mariadb-server; fi
	# Game server databases on this server are used from Linux and Windows game hosts, so
	# table names are stored lower case (lower_case_table_names=1). MySQL 8 reads this
	# setting only when it creates its data folder, so it must be in place before the
	# server package is installed. An existing database server is left as it is.
	if [ ! -d /var/lib/mysql/mysql ]; then
		mkdir -p /etc/mysql/conf.d
		printf '[mysqld]\nlower_case_table_names = 1\n' > /etc/mysql/conf.d/gse-lower-case-table-names.cnf
	fi
	say "Installing $DB_PKG"
	apt-get install -y -q "$DB_PKG"
	systemctl enable --now mysql 2>/dev/null || systemctl enable --now mariadb 2>/dev/null || service mysql start 2>/dev/null || service mariadb start
	LCTN=$(mysql -uroot -N -e 'SELECT @@lower_case_table_names' 2>/dev/null || echo "?")
	if [ "$LCTN" != 1 ]; then
		echo "WARNING: this database server has lower_case_table_names=$LCTN. Game databases that Windows" >&2
		echo "         game servers use need 1. MySQL 8 can only change it when its data folder is recreated." >&2
	fi
	DB_PASS=$(openssl rand -hex 12)
	say "Creating database $DB_NAME and user $DB_USER"
	mysql -uroot <<-SQL
		CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
		CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
		ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
		GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
		FLUSH PRIVILEGES;
	SQL

	# Game servers need this database over the network, not just from this machine.
	say "Opening the database to the network"
	for f in /etc/mysql/mysql.conf.d/mysqld.cnf /etc/mysql/mariadb.conf.d/50-server.cnf; do
		[ -f "$f" ] && sed -i -E 's/^(bind-address[[:space:]]*=).*/\1 0.0.0.0/' "$f"
	done
	printf '[mysqld]\nbind-address = 0.0.0.0\n' > /etc/mysql/conf.d/gse-public.cnf
	systemctl restart mysql 2>/dev/null || systemctl restart mariadb 2>/dev/null || service mysql restart 2>/dev/null || service mariadb restart
	DB_PORT=$(mysql -uroot -N -e 'SELECT @@port' 2>/dev/null || echo 3306)
	if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q 'Status: active'; then
		ufw allow "$DB_PORT"/tcp comment 'GSE MySQL' >/dev/null
	fi

	# root must only ever log in locally, through the Unix socket as the root OS
	# user, never over the network with a password. Force that explicitly instead
	# of trusting the package defaults (Debian's MariaDB ships root with a
	# network-capable password plugin), and drop any root@'%' account.
	if [ "$ID" = ubuntu ]; then
		mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH auth_socket;" 2>/dev/null || true
	else
		mysql -uroot -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA unix_socket;" 2>/dev/null || true
	fi
	mysql -uroot -N -e "SELECT CONCAT(\"DROP USER '\",User,\"'@'\",Host,\"'\") FROM mysql.user WHERE User='root' AND Host!='localhost'" 2>/dev/null | mysql -uroot 2>/dev/null || true

	say "Installing phpMyAdmin"
	debconf-set-selections <<-SEL
		phpmyadmin phpmyadmin/dbconfig-install boolean false
		phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2
	SEL
	apt-get install -y -q phpmyadmin
fi

# ---------- release ----------
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

# Newest stable release that has a checksum, else the newest testing release.
release_zip_url() {
	local api="https://api.github.com/repos/$REPO/releases" urls
	if [ "$CHANNEL" = stable ]; then
		urls=$(curl -fsSL "$api/latest" 2>/dev/null | grep -o 'https://[^"]*/releases/download/[^"]*' || true)
		if echo "$urls" | grep -q '\.zip\.sha256$'; then
			echo "$urls" | grep '\.zip$' | head -n1
			return
		fi
		say "No stable release with a checksum yet; using the newest testing release."
	fi
	curl -fsSL "$api?per_page=30" | grep -o 'https://[^"]*/releases/download/testing-[^"]*\.zip' | head -n1 || true
}

if [ -n "$ZIP" ]; then
	[ -s "$ZIP" ] || die "ZIP file not found: $ZIP"
	cp "$ZIP" "$WORK/panel.zip"
	say "Using local ZIP $ZIP"
else
	URL=$(release_zip_url)
	[ -n "$URL" ] || die "Could not find a panel release on GitHub."
	say "Downloading $URL"
	curl -fL --retry 3 --connect-timeout 20 -o "$WORK/panel.zip" "$URL"
	SUM=$(curl -fsSL --retry 3 "$URL.sha256" | awk '{print $1}')
	[ -n "$SUM" ] || die "The release has no checksum file."
	echo "$SUM  $WORK/panel.zip" | sha256sum -c --quiet - || die "Checksum mismatch. Nothing was installed."
	say "Checksum OK"
fi

unzip -q "$WORK/panel.zip" -d "$WORK/x"
SRC="$WORK/x"
# Release ZIPs hold one top folder; use it.
if [ "$(ls -A "$SRC" | wc -l)" = 1 ] && [ -d "$SRC/$(ls -A "$SRC")" ]; then SRC="$SRC/$(ls -A "$SRC")"; fi
[ -f "$SRC/install.php" ] && [ -d "$SRC/includes" ] || die "The ZIP does not contain the panel."

say "Copying the panel to $PANEL_DIR"
mkdir -p "$PANEL_DIR"
cp -a "$SRC/." "$PANEL_DIR/"
chown -R "$WEB_USER:$WEB_USER" "$PANEL_DIR"
mkdir -p "$BACKUP_DIR"
chown "$WEB_USER:$WEB_USER" "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR"

# ---------- Apache ----------
# AllowOverride lets the panel's .htaccess files protect private folders.
say "Configuring Apache"
ALIAS=""
case "$PANEL_DIR" in
	/var/www/html) URL_PATH="" ;;
	/var/www/html/*) URL_PATH="${PANEL_DIR#/var/www/html}" ;;
	*) URL_PATH="/$(basename "$PANEL_DIR")"; ALIAS="Alias $URL_PATH $PANEL_DIR" ;;
esac
# Apache's default welcome page would hide the panel when it is installed at the web root.
if [ "$PANEL_DIR" = /var/www/html ] && grep -qs "Apache2 .*Default Page" /var/www/html/index.html; then
	rm -f /var/www/html/index.html
fi
{
	echo "# GSE Panel (written by gse-panel-install.sh)"
	[ -z "$ALIAS" ] || echo "$ALIAS"
	echo "<Directory $PANEL_DIR>"
	echo "    Options -Indexes +FollowSymLinks"
	echo "    AllowOverride All"
	echo "    Require all granted"
	echo "</Directory>"
} > /etc/apache2/conf-available/gse-panel.conf
a2enconf -q gse-panel >/dev/null
a2enmod -q rewrite >/dev/null
apache2ctl configtest >/dev/null 2>&1 || die "Apache configuration test failed (run apache2ctl configtest)."
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl restart apache2 2>/dev/null || service apache2 restart

# ---------- done ----------
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
SETUP_URL="http://${IP:-<server-ip>}$URL_PATH/install.php"
{
	echo "GSE Panel installed $(date)"
	echo "Folder:     $PANEL_DIR"
	echo "Setup page: $SETUP_URL"
	if [ -n "$DB_PASS" ]; then
		echo "Database host:     localhost"
		echo "Database name:     $DB_NAME"
		echo "Database user:     $DB_USER"
		echo "Database password: $DB_PASS"
		echo "Database is reachable on the network on port ${DB_PORT:-3306}; phpMyAdmin: http://${IP:-<server-ip>}/phpmyadmin"
	fi
} > "$INFO_FILE"
chmod 600 "$INFO_FILE"

echo
echo "GSE Panel files are installed."
echo "Open $SETUP_URL to finish: enter the database settings and create the admin account."
if [ -n "$DB_PASS" ]; then
	echo "  Database host: localhost   name: $DB_NAME   user: $DB_USER   password: $DB_PASS"
else
	echo "  Create a MySQL 5.7.8+/8.0 database and user for the panel first, then enter them on that page."
fi
echo "These details are saved in $INFO_FILE (readable by root only)."
