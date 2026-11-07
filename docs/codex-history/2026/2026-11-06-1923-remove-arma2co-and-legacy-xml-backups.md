# 2026-11-06 Remove arma2co.xml and legacy game XML backups

## Request
Remove the Arma 2 CO game XML (CO is a combined-operations add-on, to be handled as server content later) and delete the legacy per-OS XML backup folder. Arma 2 and Arma 2 OA stay Windows-only.

## Changes
- Deleted `modules/config_games/server_configs/arma2co.xml`.
- Deleted `modules/config_games/server_configs/backup/` (258 files with OS-suffixed names and keys). Nothing in code reads that folder; history keeps the files.

## Checked before deleting
- No PHP code loads `arma2co.xml` by name. The lgsl protocol table uses `arma2co` as a query type string, which is unchanged.
- `dayz_arma2co.xml` has its own `<os>` blocks and does not depend on `arma2co.xml`.

## Not done here
- Arma 2 CO server content add-on (future).
- Testing and core are unchanged. Apply `working` to test game installs.
