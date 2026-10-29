# Customer AI Assistant

Status: design only. No runtime changes until approved.
Owner repository: GSE-Panel. Related: scheduler-v2 plan, GSE-Agent-Linux, GSE-Agent-Windows.
Billing: customers pay a monthly plan that covers all AI API usage. The Panel calls our
own provider account; customers never supply keys.

## Purpose
Help a customer with their own servers: answer questions about games, hosting, ports,
and features; diagnose problems; write scripts and schedules; and make approved changes
such as installing Workshop items or restarting a server.

## Non-goals
- No access to other customers, the host, the agents, the database, or infrastructure.
- No raw shell, no arbitrary code execution on any machine.
- No changes to server content outside the customer's own Game folder and the
  Server Content actions.
- No promises about game-specific behavior the catalog does not cover.

## Architecture
Panel chat UI -> Assistant service (Panel-side) -> model API (our account)
                                   |
                         typed tool layer (fixed list)
                                   |
                 existing Panel APIs (ownership checked here)
                                   |
                         agent RPC (only via existing actions)

The model never calls agents, databases, or the filesystem directly. Every tool call
is checked server-side against the logged-in customer and the servers they own.

## Tool catalog
Read-only (no confirmation):
- list_my_servers: id, game, status, ports, plan limits. Owned servers only.
- get_server_status: running state, PID health, last start/stop, watchdog state.
- get_game_info: game catalog entry (ports, executable, supported Workshop, docs links).
- read_server_log: last N lines of the game or watchdog log, redacted, size-capped.
- list_scheduled_tasks and get_task_runs: the scheduler v2 history for their servers.
- read_config_file: allowlisted config files only, redacted values.

Mutating (customer must click Confirm; each one shows exactly what will happen):
- restart_server, stop_server, start_server (through scheduler-v2 typed actions)
- install_workshop_item, check_workshop_updates (through Server Content actions)
- create_scheduled_task / edit_scheduled_task: typed actions only, with the schema
  from scheduler-v2
- edit_config_file: allowlisted files only, with an automatic backup and a validation step

Diagnostic (read-only, fixed set):
- run_diagnostic(kind): kinds are port_check, process_check, log_summary,
  disk_space_on_server_partition, last_crash_reason. Each kind is a fixed,
  reviewed routine. The model picks a kind; it does not supply the command.

Scripts:
- The assistant may draft a script for the customer to read.
- It can be run only by turning it into a typed action or a reviewed Server Bot
  content package. Free-form scripts are never executed.

## Guardrails
Ownership and scope:
- Server ownership checked on every tool call, not trusted from the model or the chat.
- A conversation is bound to one customer. Tool results from other servers are rejected.

Safety for the host and other customers:
- No tool can change host settings, agent settings, ports outside the server's pool,
  firewall rules, or other servers.
- Mutating actions obey the same per-server lock and conflict policy as the scheduler.

Prompt-injection and data handling:
- Game logs, Workshop descriptions, config files, and player names are untrusted data.
  They are passed to the model as quoted data, never as instructions.
- Tool outputs are redacted before they go back to the model: passwords, RCON secrets,
  tokens, keys, and IP addresses of other customers.

Human control:
- Every mutating action requires an explicit Confirm click with a preview.
- An admin kill switch disables the assistant globally, and a per-customer switch
  disables it for one account.
- Every tool call is written to an audit log: time, customer, server, tool, arguments,
  result status. No secrets in the audit log.

Cost and abuse:
- Per-customer limits: requests per hour, tokens per month, mutating actions per day.
- Usage metered per customer for monitoring against the flat plan.
- Long logs are summarized before they reach the model, with a size cap.

## Knowledge
- Game catalog and port documentation supplied as retrieved context, versioned in the repo.
- Answers about our own product cite the docs they came from.
- The assistant says "I don't know" when the catalog doesn't cover a game.

## Phases
- A: read-only Q&A about the customer's servers and games (no tools that change anything).
- B: read-only diagnostics and log summaries.
- C: confirmed mutations: start, stop, restart, Workshop install, scheduled tasks.
- D: script drafting, with conversion to typed actions or reviewed content only.

Each phase needs a security review and an admin-visible audit report before it ships.

## Decisions needed
- Model provider and the monthly token budget that the flat plan can cover.
- Whether logs are stored by the model provider (zero-retention terms) or only summarized.
- Customer terms: what the assistant may and may not do, and who is responsible for
  changes a customer confirms.
- Rate and token limits per billing tier.

## Decisions (2026-10-19)
- Customers use our own provider account, covered by the flat monthly plan. No customer keys.
- Log handling: summarize logs before they reach the model, with a size cap. Prefer provider
  zero-retention terms. Provider choice still open.
- Plan limits (defaults until pricing is final): 10 scheduled tasks per server on the base tier,
  25 on higher tiers; minimum 15 minutes between runs; mutating AI actions capped per day; token
  budgets set per tier once the provider is chosen.
