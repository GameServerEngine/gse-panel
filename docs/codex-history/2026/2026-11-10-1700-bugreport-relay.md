# Task

## User Request
Change the bug report module to send reports to the bug report relay (https://gameserverengine.com/bugreport/) instead of GitHub. Work on branch `working`, commit, do not push.

## Problem
The panel held a GitHub token and posted to api.github.com.

## Root Cause
Design: the token belongs on the relay, not in the panel.

## Repositories Inspected
gse-panel

## Files Read
AGENTS.md, docs/TODO.md, modules/bugreport/*

## Files Changed
modules/bugreport/bugreport_lib.php, bugreport.php, module.php, tests/bugreport_test.php, docs/TODO.md

## Architecture Decisions
- Form stays in the panel; bugreport_send_to_relay() POSTs JSON via the existing bugreport_http_post (20 s timeout), no auth header.
- Body keys: type (bug/feature), title, description, module, version, reporter, website (empty honeypot).
- Success only on HTTP 200 with ok true and issue_url; otherwise relay error text or a fallback message. The form keeps the admin's text.

## Validation Performed
php -l on all changed PHP files; php modules/bugreport/tests/bugreport_test.php (39 checks passed). No network calls.

## Git Commits
Repository: gse-panel, branch working. Hash: see git log.

## Remaining Work
See new rows in docs/TODO.md.
