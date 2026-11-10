# Task

Close the open update-path issues from docs/TODO.md. Version 2.5.7.

## Changes

- includes/helpers.php: runPostUpdateOperations() declares `global $db`, needs no session, returns success/error. tools/post_update_cli.php exits 1 on error.
- scripts/install-panel.sh: repair_permissions (also used by install) creates /var/lib/gse-panel/backups owned by www-data, mode 0750.
- modules/update/update_safety.php: preflight creates the backup directory when the parent is writable, otherwise refuses with the repair command. The rollback check compares runtime files and a checksum manifest of the panel tree against the pre-update snapshot and names the differing file.
- Not changed on purpose: migration filenames and the channel/branch columns (they change which migrations run on live; owner decision).

## Tests

modules/update/tests/update_safety_test.php, post_update_no_session_test.php.
