# Agent Activity Events

GSP can record meaningful agent-detected server lifecycle events in the existing Panel activity log. User and administrator actions are still logged by the Panel as before; agents only report operational outcomes that the Panel cannot know by itself.

## Transport

Agents send JSON to:

```text
Panel/agent_event_receiver.php
```

The endpoint authenticates each request with:

- `X-GSP-Agent-Id`: the Panel `remote_server_id`
- `X-GSP-Agent-Timestamp`: current Unix timestamp
- `X-GSP-Agent-Signature`: `sha256=` plus HMAC-SHA256 of `timestamp.body`

The HMAC key is the existing remote server encryption key. Agents do not receive database credentials and never write directly to the Panel database.

## Event Types

Supported event types:

- `server_process_stopped`
- `server_process_started`
- `server_crash_detected`
- `server_unresponsive`
- `automatic_restart_started`
- `automatic_restart_succeeded`
- `automatic_restart_failed`
- `scheduled_restart_started`
- `scheduled_restart_succeeded`
- `scheduled_restart_failed`
- `server_stop_confirmed`
- `server_start_confirmed`
- `server_port_missing`
- `server_port_restored`
- `server_process_found_without_port`
- `server_port_found_without_managed_process`
- `restart_attempt_limit_reached`
- `agent_event_delivery_failed`
- `agent_event_queue_replayed`

Severity values are `info`, `notice`, `warning`, `error`, and `critical`. The receiver also accepts `success` for compatibility with existing UI wording.

## Payload

Agents include a UUID, UTC timestamp, type, severity, source, OS, hostname, remote server ID, home ID, path, IP, ports, session name, PID, restart reason, expected and actual states, message, technical details, and correlation ID where available.

Payloads must not contain passwords, Steam credentials, database credentials, encryption keys, full environment dumps, or sensitive command-line arguments.

## Validation And Deduplication

The Panel receiver:

1. Verifies the HMAC signature.
2. Rejects stale request timestamps.
3. Validates event type and severity.
4. Confirms `home_id` belongs to the reporting remote server when provided.
5. Truncates public fields to safe lengths.
6. Escapes activity-log output.
7. Deduplicates by `event_uuid`.

## Activity Log Schema

Existing logger rows remain valid. The database helper extends the logger table idempotently with:

- `source_type`
- `category`
- `event_type`
- `severity`
- `remote_server_id`
- `home_id`
- `event_uuid`
- `correlation_id`
- `actor`
- `metadata_json`

Fresh installs receive the extended schema from the administration module. Existing installs are migrated lazily when the logger or receiver is used.

## Filters

Administration -> Watch Logger supports combined filters for source, category, severity, user ID, agent ID, home ID, date range, and search text. Pagination preserves the active filters.

## Lifecycle Notes

The agents use existing process, screen/session, PID metadata, and port validation. They do not use `SERVER_STOPPED` marker files.

Normal Panel start/stop/restart button clicks remain Panel-side actions. Agents add confirmed operational outcomes, such as stop confirmation after process and port validation, start confirmation after status polling sees the required port, unexpected offline transitions, unresponsive process states, and scheduled restart outcomes.

External process kills, such as BEC terminating a DayZ Mod server, are detected when validated status polling observes an `ONLINE -> OFFLINE` transition without a stop hint. That transition is reported as `server_crash_detected`.

## Offline Queue

Agents append undelivered events to:

```text
events/pending-events.jsonl
```

Each line is one JSON event. The queue is retried when later events are delivered. The queue is rotated when it exceeds 1 MB. Agent lifecycle work continues even when the Panel is unavailable.

## Configuration

Agent `Cfg/Config.pm` should include:

```perl
agent_event_url => 'https://panel.example.com/agent_event_receiver.php',
remote_server_id => '1',
```

If `agent_event_url` is empty, the agent tries to derive the receiver URL from `web_api_url`. The `key` must match the Panel remote server encryption key.

## Manual Test Plan

1. Start a server from the Panel and confirm the existing Panel user action still appears.
2. Confirm the agent reports `server_start_confirmed` only after validated status shows the process/session and required port ready.
3. Stop from the Panel and confirm `server_stop_confirmed`.
4. Kill a game process outside the Panel and poll status; confirm `server_crash_detected`.
5. Trigger a scheduled restart and confirm scheduled start/success/failure events.
6. Disconnect the Panel, trigger an event, reconnect, and confirm queued delivery without duplicates.
7. Test Watch Logger filters for agent, lifecycle, warning/error, and home ID.
