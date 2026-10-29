# To-Do

Last updated: 2026-10-20. Plans with full detail live in `docs/plans/`. Active list is `docs/plans/ACTIVE.md`.

## Now
1. **Test panel.** Confirm login at https://dev.isaserver.net/panel/ (configured against `panel-testing`). Seed test data (2 remote servers, 2 IPs, a few homes) so the port work can be checked. Needs owner OK before seeding.
2. **Port cross-over investigation.** Reproduce with seeded data. Suspects: the mod filter subquery in `getHomesFor` (not tied to the current home) and the homes list join, which matches ports on `home_id` alone and does not show the IP. Schema itself is correct: primary key is `(ip_id, port)`.
3. **ntfy in Docker, installed by the Panel installer.** Data folder inside the Panel directory. Topic per server with a random 16-character suffix stored per server (`gse-<home_id>-<token>`). Customers subscribe at the Panel's IP and ntfy port. Admin topic receives everything. Needs a token table and updated callers before merge.
4. **Agent events to ntfy.** Watchdog start, stop, crash, and give-up events reach a Panel endpoint, which publishes them.
5. **Installer lock.** Test on a fresh install and on an installed panel that anonymous visitors are blocked. Document the reinstall path (admin maintenance gate).
6. **Scheduler v2, phase P2.** Panel-owned execution through existing agent calls. Tighten the conflict lock so it can tell read-only runs from mutating ones.

## Next
- MySQL in Docker, after ntfy in Docker works and backups are proven.
- Customer AI assistant. Waiting on the model provider decision. Design is in `docs/plans/ai-assistant/`.
- Global port pools and per-IP blocks. Plan in `docs/plans/global-port-allocation/`. Phase 1 is a read-only inventory.
- LGSL/GameQ removal. Plan in `docs/plans/lgsl-gameq-protocol-removal/`.
- Remove the legacy cron execution path after every server moves to scheduler v2.
- Installer cleanup: table-prefix placeholder mismatch, fixed "Database Type" field, simpler storefront default.

## Decisions needed
- ntfy: HTTPS or plain HTTP on the ntfy port. Plain HTTP sends alerts unencrypted.
- AI assistant: model provider and the monthly token budget that the flat plan covers.
- Test box: Windows license key for the WinDev test VM. Until then, it's a re-arm task every 150 days.

- Decide: block direct access to modules/billing when no storefront vhost exists (the storefront is otherwise reachable at /panel/modules/billing/index.php).
