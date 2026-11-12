#!/usr/bin/env bash
# MySQL 8.0 removed "GRANT ... IDENTIFIED BY" (it was a syntax error there, and it made the user on 5.7).
# The panel must create game database users with CREATE USER / ALTER USER so it works on 5.7.8+ and 8.0.
# This test fails if the old form comes back, and checks the schema file has no collation that 5.7 lacks.
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
if grep -rn "IDENTIFIED BY" --include=*.php "$root/modules" "$root/includes" 2>/dev/null | grep -v recaptcha; then
	echo "FAIL: GRANT ... IDENTIFIED BY is not valid on MySQL 8.0 (use CREATE USER and ALTER USER)"; fail=1
fi
if grep -n "0900_ai_ci" "$root/install/gse_panel.sql"; then
	echo "FAIL: utf8mb4_0900_ai_ci does not exist on MySQL 5.7 (leave the collation to the server default)"; fail=1
fi
for f in "$root"/modules/mysql/*.php; do php -l "$f" >/dev/null || fail=1; done
[ "$fail" -eq 0 ] && echo "mysql grants OK" || exit 1
