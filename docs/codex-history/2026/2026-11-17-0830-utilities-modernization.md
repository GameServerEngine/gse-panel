# Task
Modernize the Utilities module (panel side): category page, agent diagnostics client and protocol, Server Query, target policy, Log Analyzer in the File Manager, Bandwidth and Storage calculators.

## User Request
Owner specification for the GSE Utilities modernization (Network Tools, Port Checker, TCP Connection Test, Server Query with A2S, Traceroute, Download Speed Test, Log Analyzer in the File Manager, Bandwidth and Storage calculators in Administration, security requirements, tests). Scope of this run: panel repo only. Design ONE versioned diagnostics RPC contract for the agents (docs/agent-diagnostics-protocol.md) and implement the panel client with feature detection; the agents are changed in separate runs. Develop and commit on `working`, do not push.

## Problem
Utilities had a long tab row, a Network Tools page that built shell command strings from user input and listed every agent to every user, and no game query, port, speed or log tools. The agents have no diagnostics methods yet.

## Root Cause
Network Tools sent `which <cmd>` and `<cmd> <target>` through the agent `exec` call with a character blacklist. There was no target policy, rate limit, audit trail or contract for diagnostics.

## Repositories Inspected
gse-panel (changed). gse-agent-linux and gse-agent-windows (read only: agent_core.pl quick_chk, agent_version, readfile, get_file_part, shell_action size, component_update_response).

## Files Read
AGENTS.md, CLAUDE.md, BRANCHING.md, docs/TODO.md, modules/util/*, js/modules/util.js, includes/lib_remote.php, includes/database_mysqli.php (settings, remote servers, user server rows, logger), modules/litefm/*, modules/administration/*, modules/billing/admin_catalog.php, includes/lang.php, includes/view.php, theme message box CSS, existing tests.

## Files Changed
See `git log --stat` for the three commits. New: docs/agent-diagnostics-protocol.md, includes/diagnostics/*, modules/util/{diag,diag_env,diag_settings,util_view}.php, modules/util/tools/*, modules/litefm/analyze_log.php, modules/administration/{resource_planner,resource_calc}.php, tests under includes/tests, modules/*/tests, tests/run_diagnostics_tests.sh.

## Architecture Decisions
- The agent only moves bytes and runs system tools; the panel decides (policy, limits) and parses (A2S). Two RPC methods: diag_capabilities (probe) and diag_run (tool, JSON params) with tools tcp_connect, udp_exchange, traceroute, http_download.
- The panel resolves names and sends IP literals, so DNS rebinding cannot change the target; the agent re-checks blocked ranges at connect time.
- Ping stays on the agent `exec` call but only with a validated IP (not part of the contract yet).
- One table, diag_audit, is audit trail, rate limit and concurrency counter; the attempt row is written before the agent is called.
- Log Analyzer reuses the agents' existing size and get_file_part calls; AI is opt-in, redacted, rate limited.
- Status boxes use the themes' existing div.success/warning/failure classes.

## Validation Performed
tests/run_diagnostics_tests.sh: all pass (unit, real UDP socket, real XML-RPC + XXTEA against a fake agent process, page tests with fake database). Pre-existing failures not touched: start_port_test (xmlrpc extension installed), check_schema_drift. No browser here: the pages and util.js were not seen rendered (node --check on util.js only).

## Git Commits
Repository: gse-panel, branch working. feat(utilities), feat(filemanager), feat(admin). Hashes: see `git log`.

## Remaining Work
Agent runs must implement docs/agent-diagnostics-protocol.md (section 8). Browser tests on the test panel. Items are in docs/TODO.md.
