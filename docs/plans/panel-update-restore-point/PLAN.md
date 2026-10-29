# Panel Update: One Restore Point

Status: decided, not started. Decisions dated 2026-10-20.

## Goal
Every update can run safely no matter which features changed, and there is always exactly one
restore point: the database and panel files from the version before the last update.

## Decisions
- One restore point. Each successful update replaces the previous restore point.
- No retention count. The `backup_retention` setting is removed.
- Database changes ship as numbered migration files in `sql/migrations/`, applied in order and
  recorded in the database. An update runs only the migrations it has not seen.
- One-off patch scripts (`modules/update/patches/`) are retired. Any patch that still matters
  becomes a migration.
- The restore point is taken before any code or migration runs. If anything fails, the restore
  point is restored automatically and the failure is logged.

## Update flow
1. Preflight: check disk space, write access, PHP and MySQL versions, and that no migration is
   half-applied.
2. Snapshot: dump the database and archive the panel files into `backups/current/`, replacing
   the old snapshot only after the new one is complete.
3. Apply code from the release.
4. Run pending migrations in order. Each migration is idempotent where possible.
5. Verify: health check, login page, and a migration status check.
6. On failure at any step: restore from `backups/current/`, then report.

## Known breakages this fixes
- Patches written for an older code layout failing after a feature change.
- Updates that deploy new code before the database has the matching columns.

## Open items
- Where `backups/current/` lives relative to the web root, so it isn't downloadable.
- How long a failed update leaves the panel in maintenance mode before the automatic restore.

## Restore point for the agent side
See `docs/plans/agent-updates/PLAN.md`.
