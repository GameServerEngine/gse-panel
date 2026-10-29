#!/usr/bin/env bash
set -Eeuo pipefail

panel_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
config_json="$(mktemp)"
cleanup() {
  rm -f "$config_json"
}
trap cleanup EXIT

chmod 600 "$config_json"
cat >"$config_json" <<'JSON'
{
  "db_host": "localhost",
  "db_port": "3306",
  "db_user": "gsp_panel",
  "db_pass": "validation-secret",
  "db_name": "panel",
  "table_prefix": "",
  "admin_user": "admin",
  "admin_pass": "validation-secret",
  "admin_email": "admin@localhost"
}
JSON

output="$(
  cd /tmp
  php "$panel_root/tools/install_panel_cli.php" --base "$panel_root" --config "$config_json" --dry-run 2>&1
)"

if grep -Eq 'opendir\(lang/|readdir\(\): Argument #1' <<<"$output"; then
  printf '%s\n' "$output" >&2
  printf 'ERROR: CLI installer still resolves lang/ relative to the caller directory.\n' >&2
  exit 1
fi

if ! grep -q 'Dry run passed' <<<"$output"; then
  printf '%s\n' "$output" >&2
  printf 'ERROR: CLI installer dry-run did not complete successfully.\n' >&2
  exit 1
fi

printf 'CLI installer non-panel cwd dry-run passed.\n'
