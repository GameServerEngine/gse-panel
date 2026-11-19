# Task
One status color palette for message boxes and status text in SimpleBootstrap, Evolution, Revolution, Revolution-Light, AdminLTE and AdminLTE-2.

## User Request
Replace pale pink/light message boxes on dark themes with the netdiag palette (tinted background, 1px border, 4px left accent, colored text). Palette as CSS custom properties per theme. Do not edit vendor CSS. No push.

## Problem
.failure/.success (print_failure, print_success), Bootstrap alert-*, label-*, callout-* and inline red/yellow styles used pale or hard-coded colors that read badly on dark pages.

## Root Cause
Colors were written per rule (red, purple, #fff, inline style="color:red"), not from one palette.

## Repositories Inspected
gse-panel only (no agent change).

## Files Changed
themes/SimpleBootstrap/css/main.css, themes/Evolution/style.css, themes/Evolution/modules/gamemanager/log.css, themes/Revolution/style.css, themes/Revolution-Light/style.css, themes/AdminLTE/dist/css/main.css, themes/AdminLTE-2/dist/css/main.css, modules/gamemanager/server_monitor.php, modules/gamemanager/home_handling_functions.php, docs/TODO.md.

## Architecture Decisions
--gse-{bad,good,info,warn}[-bg|-border] in :root at the top of each stylesheet. p/div .failure/.success/.warning are boxes; span/li/b versions and .log-* are text only. Revolution-Light uses the light palette. SimpleBootstrap main_light.css keeps a dark page background, so it uses the dark palette. AdminLTE vendor .dark-mode rules are beaten by selector order and specificity (text/badge use !important because Bootstrap does).

## Validation Performed
php -l on both changed PHP files; brace balance checked on every changed CSS. No browser available here, so no render.

## Git Commits
Repository: gse-panel, branch working.

## Remaining Work
Browser check on the test panel; Silver, Obsidian, Metro, MasterControlProgram, mobile have no .log-* rules yet.
