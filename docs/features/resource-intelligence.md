# Resource Intelligence and placement

Status: active; validation outstanding. Selected by owner 2026-11-28. Baseline panel working HEAD 357f25ae. Linux agent checkout has uncommitted changes; Windows checkout absent from expected path.

## Scope and acceptance
Hardware-neutral resource scoring and telemetry, approved game profiles, capacity reservations with real transactional concurrency, location availability, configuration quote, checkout and provisioning. Fail closed when profiles/calibrations are absent or stale. Verify all checkout paths, MySQL migrations, Linux and Windows telemetry, browser flow, and benchmarks before enabling sales. Preserve existing uncommitted work and do not deploy to production without validation.

## Evidence and checkpoints
- 2026-11-28: Claude Sonnet medium run command-20261128193106-1dd20ee3 completed exit 0. Report: Linux process_window argument bug fixed, dead billing gate removed, SlotsDisabledTest updated. Resource, storefront, configuration, placement, inventory, Linux telemetry (13/13), billing and 23 PHP syntax checks reported passing. SQLite stand-in only; MySQL migration/locking, Windows, browser and actual calibration outstanding. Schema drift reported on 85 tables. No commit or deployment. See docs/GSE-RESOURCE-PLACEMENT-ROLLOUT.md and docs/codex-history/2026/2026-11-28-1400-resource-placement-resume.md.
- 2026-11-28: Leroy inspected git status, project rules and dev environment. Existing MySQL 8.0 Docker image available with sudo; documented dev database gse-mysql8. Windows expected checkout missing; bare repository candidate /home/dev/projects/_old-git/gse-agent-windows.git (not yet verified). Agent deploy isolated feature has conflicting migration 0017; leave paused and renumber later.
- Next: inspect migration runner and test design, create disposable isolated MySQL 8 database/container (never production or existing dev DB), run migrations 0017/0018 and real transactional placement validation, record results before proceeding to Windows.

## Files
See git status and linked history/rollout. Panel key files: includes/resource_model.php, modules/billing/includes/{placement_capacity,placement_store,configuration_quote}.php, checkout/provisioning paths, configuration.js, sql/migrations/0017_hardware_neutral_game_usage.sql and 0018_resource_placement.sql, tests/resource_model_test.php, modules/billing/tests/PlacementFlowTest.php. Linux: agent_core.pl, docs/PANEL_INTEGRATION.md, tests/status_main_thread_test.pl. All currently uncommitted.


- 2026-11-28T19:46Z: Leroy created isolated MySQL 8.0.46 container gse-resource-validation-20261128 (network none). Canonical install SQL and migrations 0009,0013,0017,0018 applied successfully using mysql CLI, verified new tables and columns. Runner replay and concurrency still pending. Archived Windows working branch abaa322 is stale versus origin 5a580a2; use current origin, not archive.

- 2026-11-28T19:46Z: Real MySQL PlacementFlowTest failed with Cannot reopen temporary table c (self-join); use normal isolated fixture tables and test real concurrency. Windows origin working cloned for next step.

## Checkpoint 2026-11-28 (Claude Sonnet 5.5, continuation run)
Actor: Claude. Read: /home/dev/projects/AGENTS.md, panel AGENTS.md/CLAUDE.md, docs/TODO.md, WORK_STATUS.md, this record.

**Attempted, denied:** `sudo -n docker ps` (needs approval in this session) and Leroy `leroy_run_command` (permission not granted). No MySQL server binary on this host and the Docker socket is denied to this user. So nothing below was run on real MySQL.

**Implemented (code only; MySQL runs outstanding):**
- `modules/billing/tests/placement_mysql.inc` (new): env-driven connection, database-name guard (placement_test* or gse_resource_validation*), normal InnoDB fixture tables with unique prefix `pf<8 hex>_`, drop limited to that exact prefix, shared seed.
- `modules/billing/tests/PlacementFlowTest.php`: no TEMPORARY tables (root cause of "Cannot reopen temporary table c": billing_locations is joined twice). Uses the shared fixture; SQLite stand-in still default.
- `modules/billing/tests/PlacementConcurrencyTest.php` (new): measures sequential capacity N, then 2N+4 forked connections reserve at one instant; expects exactly N winners and N hold rows. Prints SKIP without env.
- `modules/update/tests/migration_replay_mysql_test.php` (new): requires an EMPTY database named gse_resource_validation_replay*; loads install/gse_panel.sql, runs the real runner, runs again (all skipped), checks 0017/0018 columns/tables, then documents that an unrecorded 0018 cannot be replayed (ALTER ADD COLUMN not repeatable).

**Tested (executed):** `php -l` on all four files OK; `php modules/billing/tests/PlacementFlowTest.php` passes on the SQLite stand-in; concurrency and replay tests print SKIP without env (verified). Not tested: any of this on MySQL.

**Exact privileged commands for Leroy** (container is `--network none`, so the tests need a unix socket; recreate with a socket volume; no password stored, socket dir is owner-only):
```
sudo mkdir -p /tmp/gse-rv-sock && sudo chown dev:dev /tmp/gse-rv-sock && sudo chmod 700 /tmp/gse-rv-sock
sudo docker run -d --name gse-resource-validation-20261128-sock --network none -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -v /tmp/gse-rv-sock:/var/run/mysqld mysql:8.0
sudo docker exec gse-resource-validation-20261128-sock mysql -uroot -e "CREATE DATABASE placement_test; CREATE DATABASE gse_resource_validation_replay;"
# then as dev (socket owner may need chmod 777 on the .sock file):
export GSE_PLACEMENT_TEST_SOCKET=/tmp/gse-rv-sock/mysqld.sock GSE_PLACEMENT_TEST_USER=root
GSE_PLACEMENT_TEST_DB=placement_test php modules/billing/tests/PlacementFlowTest.php
GSE_PLACEMENT_TEST_DB=placement_test php modules/billing/tests/PlacementConcurrencyTest.php
GSE_PLACEMENT_TEST_DB=gse_resource_validation_replay php modules/update/tests/migration_replay_mysql_test.php
# if early migrations fail on the empty DB, narrow: GSE_REPLAY_MIGRATIONS=0009,0013,0017,0018
```
Alternative with no new container: run the same three tests inside the existing container with `docker cp` of the repo plus a php-cli image sharing its network namespace.

**Next:** Windows agent telemetry parity in /home/dev/projects/gse-agent-windows (done below).

## Checkpoint 2026-11-28 (Windows parity, regression runs, handoff)
Actor: Claude. Windows checkout /home/dev/projects/gse-agent-windows (branch `working`, uncommitted changes).

**Implemented, Windows agent** (`AgentScripts/agent_core.pl`): `status_win_proc_snapshot` also reads the creation time (100 ns ticks) as the process start identity. New subs: `status_win_parse_processes`, `status_win_thread_snapshot` (Win32_PerfRawData_PerfProc_Thread, only the server's PIDs), `status_win_parse_threads`, `status_main_thread_cores` (same rule as Linux: thread set must match at both ends), `pid_union`, `process_window` (PID + start-time identity, CPU only), `status_win_cpu_detail`, `status_install_build`, `status_running_homes`. `status_home_lines` now emits `usage.<id>` JSON (version 2, backend native) and the sixth `home.<id>` field. Windows process I/O is deliberately NOT reported: it includes pipe and network traffic, not storage bytes, so the panel's `io_kind` check falls back to volume figures. Docs: Windows `docs/PANEL_INTEGRATION.md`, `docs/TODO.md` rows 45-46. The Linux "Status sample" instructions were reviewed and the Windows text matches their shape.

**Executed and passed (this host, Linux, no MySQL):**
- `perl tests/status_telemetry_windows_test.pl` in gse-agent-windows: 26 checks ok (fixture text only).
- `perl tests/status_main_thread_test.pl` in gse-agent-linux: all passed (agent unchanged by me).
- Panel: `php tests/resource_model_test.php`, `php tests/game_usage_test.php`, `php modules/billing/tests/{PlacementCapacityTest,StorefrontLocationTest,SlotsDisabledTest,BillingLifecycleServiceTest,PlacementFlowTest}.php` (PlacementFlowTest on the SQLite stand-in), `node modules/billing/tests/ConfigurationViewTest.js`. `php -l` on the new panel test files.
- Not re-run this checkpoint: `modules/update/tests/migration_runner_test.php` (runner untouched).

**NOT executed (blockers):**
- Anything on real MySQL (flow, concurrency, replay): Docker needs sudo approval here, Leroy command permission was not granted, no local mysqld. Commands are in the previous checkpoint.
- The Windows PowerShell commands (CreationDate ticks, PerfRawData thread class, `\$(if ...)` through Cygwin sh) never ran on Windows; only the parsers did. Whole-file `perl -c` of the Windows agent was not run (needs Cfg::Config copied from the .default files; the command was blocked here).
- Browser flow (no browser installed here), real benchmark calibration, provisioning against a real agent. Calibration needs an owner-approved run on a profiling host; `tools/resource_profile.php` reads JSONL game_sample exports offline and has not been fed real data.

**Findings:**
- Replaying migration 0017 or 0018 after a crash between ALTER and the schema_migrations insert fails (ADD COLUMN is not repeatable; MySQL 8 has no IF NOT EXISTS). The replay test asserts this as a known limitation. Decision: should the runner tolerate errors 1060/1061?
- Windows `resource_host` (limits, volume window, inventory) is still missing, so Windows hosts stay closed for placement by design.

**Git:** nothing committed in any repository, because validation on MySQL is incomplete. Uncommitted: the panel files in git status plus the new test files; Windows `AgentScripts/agent_core.pl`, `docs/PANEL_INTEGRATION.md`, `docs/TODO.md`, `tests/status_telemetry_windows_test.pl`. The paused `feature/panel-ssh-agent-install` branch was not touched. No deployment.

**Next safe action:** Leroy runs the privileged block above and the three MySQL tests, and records results here. Then run `status_sample` on a dev Windows VM (not game3, which is customer-facing). Then owner-approved benchmark runs.

- 2026-11-28T20:09Z Leroy validation: real MySQL PlacementFlowTest passed (operation command-20261128200733-639e6497); concurrency initially failed because children inherited parent mysqli socket. Edited modules/billing/tests/PlacementConcurrencyTest.php to close before fork, reconnect after wait, and open fresh cleanup connection; PHP lint and actual six-connection concurrency test passed (command-20261128200855-aa0ca4fe). Full canonical-schema migration replay failed on legacy 0005 duplicate enabled column. Scoped 0009,0013,0017,0018 migration runner and repeat skip passed on a fresh isolated DB (command-20261128200909-6003e90d). Partial-DDL crash recovery still not safe. Next: Windows resource_host telemetry and migration crash safety; live Windows validation, benchmarks and browser/provisioning remain. No production changes.

## Checkpoint 2026-11-28 (Claude Sonnet 5.5, crash recovery and Windows resource_host)
Actor: Claude. Read: /home/dev/projects/AGENTS.md, panel AGENTS.md, WORK_STATUS.md, this record, migrations 0017/0018, migration_runner.php, replay test, Windows agent_core.pl and InventoryWin.pm, Linux status_sample/cgroup/volume code, resource_model.php. Leroy's concurrency fix in PlacementConcurrencyTest.php was not touched.

**Implemented**
- `modules/update/migration_runner.php`: before each statement, `ALTER TABLE t ADD COLUMN ...` is parsed (`migrations_parse_add_columns`) and compared with information_schema (`migrations_add_columns_state`). All columns present with identical type, nullability, no default, no EXTRA -> that statement is skipped (crash-after-ALTER recovery). Some present, or any difference -> the migration fails and is not recorded. None present -> the ALTER runs. Statements with a DEFAULT, COMMENT, AFTER or other clauses are never recovered. No duplicate-error ignoring. CREATE TABLE IF NOT EXISTS / INSERT IGNORE in 0017/0018 were already repeatable.
- Tests: `migration_runner_test.php` +16 checks (parser, present/missing/type mismatch/partial/nullability/default/generated). `migration_replay_mysql_test.php` now un-records 0017 and 0018 and expects recovery, then changes `status_sample.resource_json` to TEXT and expects 0018 to fail closed and stay unrecorded.
- Windows agent `AgentScripts/agent_core.pl`: `status_win_volume_snapshot/parse_volume/volume_rates`, `status_win_host`; `status_sample` emits `resource_host=` only when every required fact is known. See Windows docs/PANEL_INTEGRATION.md for fields and known limits. Static limits: windows_visible_capacity, hypervisor host limits `not_visible_from_guest`, no steal, no process I/O.
- Panel: `tests/fixtures/windows_resource_host.json` (built by the agent code from the captured game3 inventory) and `test_windows_host_report()` in tests/resource_model_test.php: report accepted by `resource_host_window`, calibrated Windows host can place, each unknown figure closes the host, changed limits change the fingerprint.

**Executed and passed (this host, no MySQL, no Windows)**
- `php modules/update/tests/migration_runner_test.php` (all pass), `php modules/update/tests/update_safety_test.php` (50), `php tests/resource_model_test.php`, `php tests/game_usage_test.php`, `php modules/billing/tests/PlacementFlowTest.php` (SQLite), `php -l modules/update/migration_runner.php`.
- Windows repo: `perl tests/status_telemetry_windows_test.pl` (all pass, includes ~35 new checks), `perl -I AgentScripts tests/inventory_windows_test.pl` (all pass).
- Concurrency/replay MySQL tests print SKIP without env (expected).

**NOT executed / blockers**
- Leroy tools (`leroy_list_projects`) and sudo docker were not permitted in this session, so the extended replay test (crash recovery) has not run on MySQL. Run: the privileged block in the first checkpoint, then `GSE_PLACEMENT_TEST_DB=gse_resource_validation_replay GSE_REPLAY_MIGRATIONS=0009,0013,0017,0018 php modules/update/tests/migration_replay_mysql_test.php`.
- Windows PowerShell commands (drive counter class property names, quoting through Cygwin sh) never ran on Windows. No dev Windows VM could be identified from this session: game3 is customer-facing and was not touched; no dev VM is recorded in the repos. Needed: a dev/isolated Windows VM (Server 2022, Cygwin agent, one game home on the game drive), PowerShell access, and the owner's approval for the benchmark.
- Whole-file `perl -c` of the Windows agent (needs Cfg::Config); browser flow; real benchmark calibration; provisioning against a real agent. Not run, no benchmark on any customer machine.
- Legacy migration 0005 on the canonical install schema: documented in docs/TODO.md only (not caused by this feature, scoped replay works).

**Git:** nothing committed (validation incomplete; unrelated dirty work present in panel). Uncommitted this checkpoint: panel modules/update/migration_runner.php, modules/update/tests/{migration_runner_test,migration_replay_mysql_test}.php, tests/resource_model_test.php, tests/fixtures/windows_resource_host.json, docs/TODO.md, this record, WORK_STATUS.md, history record; Windows AgentScripts/agent_core.pl, tests/status_telemetry_windows_test.pl, docs/PANEL_INTEGRATION.md, docs/TODO.md. Automatic installer feature untouched and paused. No deployment.

**Next safe action:** Leroy runs the MySQL replay command above; then an owner-named dev Windows VM runs `status_sample` (no benchmark on customer machines), then owner-approved calibration, then the browser and provisioning checks.

## Release request and validation gate
Owner requested commit, push, merge and deployment. Scoped MySQL replay, real placement and concurrent reservation tests, panel regressions and agent fixture tests were rerun before the pre-release. Production remains gated on live agent telemetry, approved host calibration/game profiles, and browser/provisioning smoke tests; no synthetic scores are to be approved. Preserve the paused SSH deployment feature and never force-push a branch. See release orchestration logs for commit and workflow results.
