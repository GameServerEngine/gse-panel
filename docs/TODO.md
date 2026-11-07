# TODO and Issues

Read this file before you start work in this repo. Update it every time you work here.
The rules are in AGENTS.md under Work Tracking.

Type: todo, issue, goal, need.
Status: open, doing, done, invalid.
Older lists (for example docs/plans/BACKLOG.md) are copied into this file. Add new items only here.

| ID | Type | Status | Title | Owner | Opened | Updated | GitLab |
|---|---|---|---|---|---|---|---|
| T-001 | todo | done | Switch restic backup, restore, and delete to start and poll the agent job in function.php. Done: each restic step runs as an agent job polled by the panel, capped at 10 minutes. | Claude | 2026-11-05 | 2026-11-05 | - |
| T-002 | todo | done | Drop -r from restic_lib.php argv and pass the repo URL in the environment. Done: repo URL is RESTIC_REPOSITORY in the job environment. | Claude | 2026-11-05 | 2026-11-05 | - |
| T-003 | todo | open | Install cron for cron-purge.php on core | User | 2026-11-05 | 2026-11-05 | - |
| T-004 | todo | open | Confirm the DR denied-command check for restic-home-admin-ssh | User | 2026-11-05 | 2026-11-05 | - |
| T-005 | goal | open | Move TODO.md, docs/FEATURES_TODO.md, docs/development/COPILOT_TODO.md, and docs/plans/BACKLOG.md items into this file | Claude | 2026-11-05 | 2026-11-05 | - |
| T-006 | todo | open | Customer backups longer than 10 minutes: move job polling out of the web request so a long backup still records its snapshot | Claude | 2026-11-05 | 2026-11-05 | - |
| T-007 | todo | done | Cap restic backup uploads at 2048 KiB/s with --limit-upload before 'backup'. The KCWIN agent must accept the flag (agent change in GSE-Agent-Windows). | Claude | 2026-11-06 | 2026-11-06 | - |
| T-008 | todo | done | Add a Delete button next to Restore on the customer backup page. Confirm by typing DELETE; runs restic forget --prune on that one snapshot; baseline backups cannot be deleted. | Claude | 2026-11-06 | 2026-11-06 | - |
| T-009 | issue | done | Account setup (set/purge) sent the rest user in quotes, which the DR forced command rejects (exit 126). Also admin.php did not require restic_lib.php. | Claude | 2026-11-06 | 2026-11-06 | - |
| T-010 | issue | open | Core panel checkout was re-cloned and reset to testing on 2026-11-06, dropping uncommitted panel fixes. Re-applied in this commit. Confirm core deploys from git, not in-place edits. | Claude | 2026-11-06 | 2026-11-06 | - |
