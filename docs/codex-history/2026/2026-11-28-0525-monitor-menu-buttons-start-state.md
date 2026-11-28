# Task
Three game monitor fixes from phone screenshots.

## User Request
1. Game Monitor menu shows game types, then servers under each; show the game servers directly, each with its game icon.
2. Lines appear beside the Restart/Stop buttons when pressed.
3. After Start, the page says started, returns to the monitor which shows the server not running; a reload shows it running.

## Root Cause
1. home.php built a two-level menu (game type, then servers).
2. The global `a:hover, a:focus { text-decoration: underline }` (themes/SimpleBootstrap/css/main.css) underlined the inline-block button links, drawn under the large icon glyph.
3. start_server.php used is_agent_status_online (RUNNING/ONLINE plus any runtime evidence, e.g. the process alone); server_monitor.php required RUNNING plus process plus game port listening, and showed anything else (other than STARTING/STOPPING) as STOPPED with the start form.

## Files Changed
home.php, modules/gamemanager/home_handling_functions.php (agent_status_ready), modules/gamemanager/start_server.php, modules/gamemanager/server_monitor.php, modules/gamemanager/tests/start_status_answer_test.php, themes/SimpleBootstrap/css/main.css (removed three rules for the old nested menu; overflow handling moved to the submenu link rule), themes/SimpleBootstrap/modules/gamemanager/game_monitor.css, CHANGELOG.md, docs/TODO.md.

## Validation Performed
php -l on changed files; start_status_answer_test passes, with a new case: process up but port not listening keeps waiting.

## Git Commits
See the commit that adds this file.
