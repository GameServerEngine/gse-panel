#!/usr/bin/env bash
# Thin wrapper around tools/build_gse_panel_schema.php.
#
# Regenerates install/gse_panel.sql (the canonical schema dump) from
# panel.sql plus every module's install_queries, replayed against a
# disposable scratch database. Only ever targets a database whose name
# contains "gse_schema_build" (enforced by the PHP tool itself as a safety
# guard against accidentally pointing this at a real Panel database), and
# drops that scratch database again when finished unless --keep is passed.
#
# Usage:
#   scripts/build-schema.sh [--host=H] [--port=P] [--user=U] [--pass=P] \
#       [--db=gse_schema_build] [--out=install/gse_panel.sql] [--keep]
#
# Requires a reachable MySQL/MariaDB server plus the `mysql` and
# `mysqldump` client binaries on PATH. Never run with sudo; this only
# needs credentials for the scratch database, not root/system access.

set -Eeuo pipefail

panel_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

db_name="gse_schema_build"
extra_args=()

for arg in "$@"; do
  case "$arg" in
    --db=*)
      db_name="${arg#--db=}"
      ;;
    *)
      extra_args+=("$arg")
      ;;
  esac
done

if [[ "$db_name" != *gse_schema_build* ]]; then
  echo "ERROR: --db must contain 'gse_schema_build'. Refusing to target a non-scratch database." >&2
  exit 1
fi

command -v php >/dev/null 2>&1 || { echo "ERROR: php CLI is required." >&2; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo "ERROR: mysql client is required." >&2; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { echo "ERROR: mysqldump client is required." >&2; exit 1; }

exec php "$panel_root/tools/build_gse_panel_schema.php" "${extra_args[@]}" --db="$db_name"
