# Assistant (AI) module

Help from the Claude API inside the panel, one server at a time.

## What it does

- **Customers:** each server in Game Monitor has an **AI Assistant** button. The chat is tied to that server. It can show status, read the log (errors, logins, recent lines), check the server's MySQL database and FTP login, and offer buttons: Start, Stop, Restart, new database password, new FTP password. A button is a link or a confirm box; the customer presses it. The assistant never acts by itself.
- **Admins:** Admin menu > **Assistant**. Read-only: panel version, disk and PHP errors; MySQL server status and database sizes (no row data); the game databases; every agent (online, version, response time); any server's status and log.

## How it is kept safe

- The AI is called only by the panel (`modules/assistant/ask.php`). Agents and browsers never get the API key.
- A customer chat has a **scope**: `chat.php` checks the user owns the server, stores the server id in the PHP session under a random token, and the browser sends only the token. The model has no way to name another server. Every tool checks ownership again.
- The model can call only the fixed tools for its scope (`assistant_allowed_tools`). Customer tools take no server id. Admin tools are read-only.
- Log text is wrapped in `<log_data>` and the prompt tells the model it is data, not instructions.
- Passwords are made by the panel (`assistant_make_password`, three plain words with symbols), applied by `confirm.php`, returned to the customer's screen once. They are not given to the model and not written to `assistant_log`.
- Limits: 6 questions per minute per user, a monthly token cap per customer (`assistant_monthly_tokens`, admins exempt), short answers (700 tokens), at most 5 tool rounds per question.
- Every question is recorded in `assistant_log` with its tools and token counts. Button presses are recorded as `reset:<what>` with no password.

## Turning it on

1. Put the key on the panel server only: `/etc/gse-panel/assistant.env` containing `ANTHROPIC_API_KEY=...`, owner root, group = the web user, mode 640.
2. In the panel settings table set: `assistant_enabled=1`, `assistant_model=claude-haiku-5-5`, `assistant_effort=medium`, `assistant_monthly_tokens=200000`, `assistant_key_file=/etc/gse-panel/assistant.env`.
3. Module Manager: install or update **Assistant** (creates `assistant_usage` and `assistant_log`; fresh installs get them from `install/gse_panel.sql`).
4. Set a low monthly spend limit for the key in the Anthropic console.

## Tests

- `php modules/assistant/tests/assistant_lib_test.php` (no database or network).
- `php modules/assistant/tests/assistant_live_check.php` (calls the real API with fake tools).
- Dev panel round: log in as two customers and an admin, check isolation (another customer's token, the admin token, no CSRF, an unknown token are all refused), the reset buttons, and that no password reaches `assistant_log`.
