# Task
GSE Resource Intelligence, Phase 1 (architecture review) and Phase 2 (read-only hardware inventory in both agents and the panel).

## User Request
Development handoff "GSE Resource Intelligence, revision 2.0" (2026-11-26): benchmark hardware, score it, profile game servers, plan capacity and place games automatically. The operator asked for Phase 1, then Phase 2, and then told the work to continue.

## Problem
Placement only compares an agent's CPU, RAM and disk percentages with one headroom setting. The agent snapshot has percentages only, no history, and no absolute sizes. The agents report no hardware inventory.

## Root Cause
There was no hardware inventory anywhere in the agents or the panel. `agent_fits_requirements()` exists but only its test calls it. Checkout and provisioning have no capacity check. The storefront lets an order through when a snapshot is old, missing or has null values.

## Repositories Inspected
gse-panel (changed), gse-agent-linux (changed), gse-agent-windows (changed), gse-loadtest (read only: hw.go, bench.go, sys*.go).

## Files Read
AGENTS.md and CLAUDE.md (read late, after the first Phase 2 commits), docs/TODO.md in all three repos, the two reference files, includes/lib_remote.php, includes/database_mysqli.php, modules/server/agent_helpers.php, modules/billing/includes/{placement_capacity,storefront_locations,catalog_storefront}.php, modules/billing/order.php, add_to_cart.php, modules/administration/{resource_planner,resource_calc}.php, modules/status_history/module.php, install/gse_panel.sql, both agent_core.pl files, GameManagerWin.pm.

## Files Changed
Panel: includes/agent_inventory.php (new), includes/lib_remote.php, includes/database_mysqli.php (getMysqli), modules/server/agent_helpers.php, docs/agent-hardware-inventory-protocol.md, docs/GSE-RESOURCE-INTELLIGENCE-PHASE1.md, docs/TODO.md, tests (agent_inventory_test, agent_inventory_db_test, includes/tests/agent_inventory_remote_test, modules/server/tests/agent_refresh_inventory_test).
Linux agent: GSE/Resource/Inventory.pm (new), agent_core.pl, tests/inventory_linux_test.pl, docs/PANEL_INTEGRATION.md, docs/TODO.md.
Windows agent: AgentScripts/GSE/Resource/InventoryWin.pm (new), AgentScripts/agent_core.pl, tests/inventory_windows_test.pl, tests/fixtures/inventory_windows_game3_raw.json, docs/PANEL_INTEGRATION.md, docs/TODO.md. Earlier in the same session: the Windows service rename GSPAgent to GSE-Agent in the installer, agent_start.bat, tests and docs.

## Architecture Decisions
- One schema (version 1) for both agents. Unknown is null, never 0. Same keys on both platforms, even where a value does not apply (Windows reports cgroup source `none`).
- The agent only reports facts. The panel decides when to ask, stores a row only when the hardware fingerprint changes, and keeps its own state table.
- An agent that predates the call answers with an XML-RPC fault. That is "unsupported" and is rechecked hourly. `postRequest` is used because `sendRequest` logs the fault and reports "unreachable". This was a bug in the first version of the wrapper, found by a test against the real wrapper.
- Windows: PowerShell collects raw JSON, Perl normalises it, so the logic is testable on Linux. Available memory uses `ComputerInfo.AvailablePhysicalMemory` because game3's performance counters are broken.
- The inventory hook runs after a successful snapshot and swallows every failure, including database exceptions in strict mode.
- `agent_refresh_snapshot()` takes an optional `$remote` (default unchanged) so the whole path can be tested. `DatabaseMySQL::getMysqli()` was added instead of reaching into the protected connection.

## Validation Performed
Linux inventory: 17 fixture checks; live run on the dev VM; the agent's contract validator passes.
Windows inventory: 45 checks including a real game3 capture; the script was run read-only on game3; the panel parser and fingerprint accept the real Windows output. The 15 existing Windows validators give the same results before and after (7 already failed).
Panel: 30 pure checks, 7 checks of the real RemoteLibrary wrapper with a fake transport, 35 database checks and 12 end-to-end snapshot checks against a scratch MariaDB 10.11 database (not MySQL 8; removed afterwards). All 73 panel test files compared with the previous commit: no regressions; 6 test files fail both before and after (see docs/TODO.md).

## Git Commits
Repository: gse-panel, branch working. 1dfa6443 (inventory storage and parsing), 05e0f6b2 (database tests), e95b348d (collect from the snapshot refresh), and the documentation commit that adds this record (see git log).
Repository: gse-agent-linux, branch working. 57f3a79 (read-only Linux hardware inventory), plus a documentation commit.
Repository: gse-agent-windows, branch working. a3eec57c (service rename GSPAgent to GSE-Agent), 8e0202be (read-only Windows hardware inventory), plus a documentation commit.

## Remaining Work
Nothing is deployed. Deploy the panel to core, then update the agents on game5, game6 and game3 (each restarts a live agent). The repo rules (TODO rows, this record, the protocol document) were read after the first code commits and were written at the end; the rows in docs/TODO.md list the rest: game3's evaluation licence ends 2027-04-24, its performance counters are broken, the game volume path should come from the game configuration, the three Phase 1 decisions, Phases 3 to 16, and the rest of the GSP to GSE rename.
