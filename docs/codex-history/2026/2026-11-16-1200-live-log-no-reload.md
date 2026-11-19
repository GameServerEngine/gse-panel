# Task
Game manager: no full page reloads while a server starts/stops, log colors for SimpleBootstrap, wildcard log file names.

## User Request
Three improvements (A: replace meta-refresh loops with in-page polling; B: log colors from the netdiag report palette; C: check and support glob names in console_log). Do not change the agent repos.

## Problem
Start page reloaded every 3-5 s through view->refresh(). log.php polled only when running, with an inline style block.

## Root Cause
Status and log were refreshed by reloading the page. No shared script or CSS for the log box.

## Repositories Inspected
gse-panel, gse-agent-linux, gse-agent-windows (read only).

## Files Read
modules/gamemanager/log.php, start_server.php, stop_server.php, mini_start.php, update_server.php, restart_server.php, get_server_log.php, includes/view.php, includes/lib_remote.php, both agent_core.pl (get_log, resolve_latest_log_match), SimpleBootstrap css and main.js.

## Files Changed
includes/view.php, js/gse_live.js, modules/gamemanager/{log.php,log.css,start_server.php,stop_server.php,mini_start.php,home_handling_functions.php}, modules/gamemanager/tests/start_status_answer_test.php, themes/SimpleBootstrap/css/main.css, docs/TODO.md.

## Architecture Decisions
Polling goes through the same module page with type=cleared, so user/home checks are the page's own. Log text goes in with textContent only. Agents are not changed; they already resolve globs.

## Validation Performed
php -l on changed PHP, node syntax and highlighter check, start_status_answer_test.php passes. Existing get_server_log_endpoint_test and start_port_test fail the same before and after (xmlrpc missing, known in TODO).

## Git Commits
Repository: gse-panel. See git log on branch working.

## Remaining Work
update_server.php and restart_server.php reload loops; browser test; agents returning the resolved file name.
