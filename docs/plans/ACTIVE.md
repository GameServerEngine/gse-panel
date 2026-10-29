# Active Plans

## LGSL/GameQ/protocol-removal

- Status: Active planning; documentation-only plan created, no runtime removal started.
- Owner repository: `/home/dev/Projects/GSE-Panel`
- Related repositories: `/home/dev/Projects/GSE-Agent-Linux`, `/home/dev/Projects/GSE-Agent-Windows`
- Next action: Execute Phase 1 read-only inventory from `docs/plans/lgsl-gameq-protocol-removal/PLAN.md`, then classify each dependency before changing code.
- Stop condition: Stop before code changes if lifecycle controls, XML import compatibility, agent status semantics, RCON, TeamSpeak ServerQuery, billing slot/player terminology, or the Panel `protocol/` folder cannot be separated safely from LGSL/GameQ removal.
- Detailed plan: `docs/plans/lgsl-gameq-protocol-removal/PLAN.md`

## Global Port Allocation

- Status: Active planning; documentation-only plan created, no runtime implementation started.
- Owner repository: `/home/dev/Projects/GSE-Panel`
- Related repositories: `/home/dev/Projects/GSE-Agent-Linux`, `/home/dev/Projects/GSE-Agent-Windows`
- Next action: Review `docs/plans/global-port-allocation/DATABASE_DESIGN.md`, `docs/plans/global-port-allocation/GAME_PORT_REVIEW_TABLE.md`, `docs/plans/global-port-allocation/PORT_POOLS.md`, and `docs/plans/global-port-allocation/STATIC_SERVICE_RESERVATIONS.md`; verify default-port values marked `needs external verification`; then approve or revise the schema, pool catalog, and migration/backfill approach.
- Stop condition: Stop before code changes if pool ranges, service reservations, existing `gsp_home_ip_ports` compatibility, billing/manual provisioning parity, or non-standard game offsets are not explicitly resolved.
- Detailed plan: `docs/plans/global-port-allocation/PLAN.md`

## Scheduler v2 (design)

- Status: Design written; awaiting approval. No runtime changes.
- Owner repository: `/home/dev/Projects/GSE-Panel`
- Related repositories: `/home/dev/Projects/GSE-Agent-Linux`, `/home/dev/Projects/GSE-Agent-Windows`
- Next action: review `docs/plans/scheduler-v2/PLAN.md`, answer the open questions.
- Stop condition: no runtime changes until the plan is approved.
- Detailed plan: `docs/plans/scheduler-v2/PLAN.md`

## Customer AI Assistant (design)

- Status: Design written; awaiting approval. No runtime changes.
- Owner repository: `/home/dev/Projects/GSE-Panel`
- Next action: decide the model provider, budget, and log retention; review `docs/plans/ai-assistant/PLAN.md`.
- Stop condition: no assistant code until the design is approved and a security review is scheduled.
- Detailed plan: `docs/plans/ai-assistant/PLAN.md`

## Notifications: ntfy (decided, in progress)

- Status: Module and isolated installer test done. Random-suffix topics and token storage not yet started.
- Decision: ntfy runs in Docker, installed by the Panel installer. Data folder lives inside the Panel directory. Customers subscribe at the Panel's IP and the ntfy port.
- Next action: add a per-server token table, update callers, then wire agent events through a Panel endpoint.
- Detailed plan: `docs/plans/scheduler-v2/PLAN.md` (Notifications section).

## Installer lock and reinstall path

- Status: Existing gate blocks anonymous access once installed. Needs testing on a fresh install and on an installed panel.
- Next action: test both cases, then document the reinstall procedure.

## Panel test environment

- Status: Configured test panel at `https://dev.isaserver.net/panel/`. Database is empty.
- Next action: seed test data after owner approval.

## Panel update: one restore point (decided, not started)

- Owner repository: `/home/dev/Projects/GSE-Panel`
- Plan: `docs/plans/panel-update-restore-point/PLAN.md`

## Agent updates from the panel (decided, not started)

- Plan: `docs/plans/agent-updates/PLAN.md`

## Leroy for panels and customer AI access (decided, design)

- Plan: `docs/plans/leroy-panel-instance/PLAN.md`

## Storefront: customer rental site (decided, not started)

- Plan: `docs/plans/storefront-customer-site/PLAN.md`
