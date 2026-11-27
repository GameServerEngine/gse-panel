# Task
Roll the agent builds with the update helper fixes out to game5, game6, the demo agent and game3, and check everything is running.

## User Request
"Continue" (after "make all those fixes right now and update and make sure everything is running").

## Problem
The update helper replaces itself while it runs. Tests showed that an update whose helper differs from the installed one stalls or fails at most sizes of change. All four hosts had such a helper installed, and the Windows 1.8.2 release shipped a different one, so sending the update through the installed helpers was not safe.

## Root Cause
See the helper records in gse-agent-linux and gse-agent-windows (docs/codex-history/2026, update-helper-self-overwrite).

## Repositories Inspected
gse-agent-linux, gse-agent-windows (merged into testing, releases built); gse-panel (read only: update_agents, agent_perform_release_update).

## Files Read
The helpers' logs and update state on all four hosts, agent process trees, the panel's agent status.

## Files Changed
GitHub: working merged into testing in both agent repositories (6211cd3, 3e5fcdd1); the Release workflow on testing published testing-1.8.4 (Linux, bump commit 71ca72f) and testing-1.8.3 (Windows, bump commit 2eb6eb14). Hosts: agent code on game5, game6, the demo agent and game3; the helper file on each was replaced first.

## Architecture Decisions
- Place the released helper before sending the update: copy it beside the installed one, check its SHA-256 against the released file, rename it over the old one (on Windows also check there are no CRLF bytes). The update then runs the fixed helper, whose self-replacement is harmless.
- Order: game5, game6 (no game servers), the demo agent, then game3 (live Arma and DayZ servers) at 14:11, away from the 15:00 scheduled restart.
- Used the panel's own update path through a one-off script on core that refuses to send unless the archive checksum equals the published one; removed afterwards.
- The Linux supervisors on game5 and game6 are still the old script; the helper's freshness check covers them.

## Validation Performed
Per host: helper SHA-256 equal to the release before the update; each helper stage logged exactly once; state ok with version_after equal to the target and no rollback; no hold file left; the running agent started after the files were copied; the panel shows the new version.
game5 and game6: the old supervisor restarted a stale agent during the copy (as predicted), and the helper detected it ("Agent started before the new files were in place") and restarted it by itself. Demo: no stale agent (hold-aware supervisor), one short outage. game3: the helper logged Starting the GSE-Agent service, the service is Running under a new service host process, the agent port and FTP are up, and all nine game and BEC processes had identical PIDs and start times before and after.
After the rollout the panel's inventory refresh worked against all four updated agents.

## Git Commits
gse-agent-linux testing 6211cd3 then bump 71ca72f; gse-agent-windows testing 3e5fcdd1 then bump 2eb6eb14; documentation commits on working in the three repositories.

## Remaining Work
Item 39 in gse-agent-windows docs/TODO.md (game3 service account and ACLs) needs a decision. T-040 in gse-agent-linux (old supervisors on game5 and game6). Testing is a documentation commit behind working in each repository.
