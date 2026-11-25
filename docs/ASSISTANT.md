# Assistant (AI) module

Help from the Claude API inside the panel. It finds problems in logs and files, answers questions about a server and its database, and prepares fixes that a person reviews and applies. It never changes anything by itself.

## What it does

**Customers** (AI Assistant button on a server in Game Monitor). The chat is tied to that one server.
- Reads the server log with filters: errors, security (failed logins, bans, kicks, rcon, injection), logins, recent.
- Lists and reads text files in the server's own folder (configs, scripts, other logs). Passwords and keys are blanked.
- Runs read-only queries (SELECT, SHOW, DESCRIBE, EXPLAIN) on the server's MySQL database with the database's own login.
- Checks status, the database login and the FTP login.
- Offers buttons: Start / Stop / Restart, new database password, new FTP password.
- Proposes file changes and new files (scripts, mods, configs). The customer sees the exact difference, presses **Apply**, and can **Undo** a change to an existing file.

**Admins** (Admin > Assistant). The same tools for any server (the model is given a `home_id`), plus:
- Panel health, MySQL health, game databases, every agent.
- Read the panel's own code and logs (`panel_list`, `panel_read`, `panel_search`), never `config.inc.php` or key files.
- Read files on agent machines, only in folders the admin lists in the settings (`agent_list`, `agent_read`).
- `propose_patch`: saves a patch for a panel file or an agent file. A patch is **never applied by the assistant**. Download it on the Assistant page and apply it with `git apply` on `working`, then go through working -> testing -> stable as usual. A new module is one patch per new file.

## Settings (all on the Admin > Assistant page)

On/off; customers allowed; model and thinking effort; answer length; tool steps per question; questions per minute; **monthly budget in USD (default $5, stops everyone including admins)** with the token prices used to estimate the cost; most tokens per question; cap per customer per month; **cap per server per month** with per-server overrides (`12=400000, 15=0`); switches for reading files, proposing edits, database queries and password buttons; folders the admin assistant may read on the panel server and on agents; the key file path. The key can be typed into the API key box on the same page; it is never shown again.

The same page shows this month's use and estimated cost, use per customer and per server, the patches waiting, and the recent activity log (times in US Central).

## How it is kept safe

- Only the panel calls the AI (`ask.php`). Agents and browsers never get the API key.
- **Scope.** A customer chat is bound to one server by a random token kept in the PHP session. The browser sends only the token. Customer tools take no server id. Every tool re-checks ownership. The admin chat uses the fixed token `admin` and is accepted only for administrators.
- **Tools come from one list** (`assistant_catalog.php`). The list the model sees and the list the panel will run are built from it, filtered by the admin switches, so they cannot disagree.
- **Nothing is written without a person pressing a button.** Password resets and file changes are buttons handled by `confirm.php` (CSRF checked, rights checked again, confirm box). Patches are only downloads.
- **File jail.** Customer file access uses the File Manager's jail (`litefm_safe_join_home_path`), so `..` and paths outside the server folder are refused. Secret-looking files (`.env`, `*.pem`, `*.key`, `.ssh`, `.git`, `config.inc.php*`, shadow, and so on) are never read.
- **Secrets are blanked** in everything the model sees (`assistant_redact`): `password`, `rcon`, `key`, `token`, `secret` and similar, in `name = value`, `name: value`, `name => value`, `-name value` and `name value` forms. It is best effort, so edits are built from the real file by the panel (`old_text` must match exactly once), never from the blanked text the model saw.
- **Untrusted text is marked as data.** Log, file and database text is wrapped in `<log_data>`, `<file_data>`, `<db_data>`; anything inside that looks like one of those tags is changed so it cannot close the tag. The prompt says it is data, not instructions.
- **Database guard.** One statement, no comments, no semicolons inside, must start with SELECT / SHOW / DESCRIBE / EXPLAIN, no INTO / LOAD_FILE / SLEEP / SET / CALL and similar; the session is read-only with a 5 second limit; 30 rows, 150 characters a cell; columns named like passwords are blanked.
- **Caps.** Budget, per-minute, per-customer, per-server, per-question tokens, answer length, tool steps. A customer can pass a cap by at most one question (limited by the per-question token cap).
- **Passwords** are made by the panel (`assistant_make_password`, three plain words with symbols), shown once on the customer's screen, not given to the model and not written to the log.
- **Audit.** Every question goes to `assistant_log` with its tools and tokens. Button presses are logged as `reset:<what>` and `proposal:<what>`, never with a password or file text. Settings saves are logged.
- Proposed file changes keep the exact old and new text in `assistant_proposals` (applied ones stay for undo and audit). Waiting proposals expire after 7 days; at most 20 wait per server and 50 patches overall.

## Turning it on

1. Either type the key into the **API key** box on Admin > Assistant (saved in the panel database, never shown again, only the last 4 characters), or put it in a file on the panel server (safer, and used first if both exist): `/etc/gse-panel/assistant.env` containing `ANTHROPIC_API_KEY=...`, owner root, group = the web user, mode 640.
2. Module Manager: update **Assistant** (adds `assistant_server_usage` and `assistant_proposals`; fresh installs get them from `install/gse_panel.sql`). The tables are also created on first use as a safety net.
3. Admin > Assistant: tick **Assistant switched on**, check the budget and caps, save.
4. Set a spend limit for the key in the Anthropic console as well. The panel's cost figure is an estimate from the prices in the settings.

## Tests

- `php modules/assistant/tests/assistant_lib_test.php` and `php modules/assistant/tests/assistant_features_test.php` (no database, network or API key).
- `php modules/assistant/tests/assistant_live_check.php` calls the real API with fake tools.
- Not yet exercised on a dev panel: the 2.16.0 file tools, database query, proposals and the admin settings page (code reviewed and unit tested only). Do one dev round before enabling for customers: apply and undo a change, check a refused path, check a cap.

## Known limits

- Redaction and the SQL guard are blunt on purpose. A real value can be blanked in a log, and a statement with `;`, `--` or `#` inside a string is refused.
- A symlink inside a customer's folder can point outside it; the File Manager has the same behaviour. The assistant reads through the agent the same way.
- Patches use plain text diffs. A file without a final newline can fail `git apply --check`; fix the last line by hand.
- Agent file access uses absolute Linux paths. Windows agents are not covered yet.
- Strings are English only.
- Undo only exists for changes to existing files. A file the assistant created has to be deleted in the File Manager.

## Ideas for later

- Scheduled log scans per server with an alert (ntfy or ticket) when something looks wrong, using the Haiku model and the per-server cap.
- Cache the security/error summary per log for a few minutes so repeated questions cost nothing.
- Per-customer billing or an "assistant pack" product, and a per-plan token allowance instead of one global default.
- Open a pull request from an approved patch instead of downloading it; run the unit tests on the patch first.
- A user-fix tool for administrators (unlock an account, reset a panel password) as confirmed buttons.
- Keep the last N applied changes per server in a history view, and let a customer restore any of them.
- Translate the chat and admin page through the language files.
- A "teach" file per game (known log lines, common fixes) that the assistant can read to answer faster and more cheaply.
- Windows agent file access and a delete tool for assistant-created files.
