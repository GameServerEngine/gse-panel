# Status history

Default module. Admins can turn reporting off, and change the poll interval and the keep times.

## Parts

- `poller.php`: long-running CLI loop, started by `install/gse-status-poller.service`. It asks one
  agent per interval, round robin, so each server is refreshed every (servers x interval) seconds.
  Pages never call agents for this data.
- `includes/status_history_lib.php`: the pure arithmetic (percentages, rates, parsing, rollups).
  No database access, so it can be tested alone.
- Tables (migration `sql/migrations/0009_status_history.sql`):
  - `status_sample`: one row per poll, kept for the raw window (default 6 hours).
  - `status_hour`: one row per server per clock hour, kept for the hourly window (default 7 days).
  - `status_day`: one row per server per local day, kept for the daily window (default 365 days).
- `includes/game_usage_lib.php`: the same for game usage (parsing the `home.` and `container.`
  lines, building rows, hourly and daily rollups). Tests: `tests/game_usage_test.php`.
- Game usage tables (migration `sql/migrations/0013_game_usage.sql`), same keep times as above:
  - `game_sample`: one row per game server or container per poll.
  - `game_hour`, `game_day`: one row per game server or container per hour or local day. CPU is in
    cores in use, and averages and peaks count only the samples where the server was RUNNING, so
    they show what a game uses while it runs. Disk is the largest folder size seen.
  - A series is one game server (`home_id`, with the `game_key` of its game) or one container the
    panel does not manage (`home_id` 0, `label` = container name, `process` = its main process).
  - Administration > Resource Planner shows the last 7 days per game type and per container, next
    to the billing minimum set for the game.

## Settings (panel settings table)

| key | default | range |
| --- | --- | --- |
| `status_history_enabled` | 1 | 0 or 1 |
| `status_history_interval_seconds` | 30 | 10 to 3600 |
| `status_history_raw_hours` | 6 | 1 to 48 |
| `status_history_hourly_days` | 7 | 1 to 90 |
| `status_history_daily_days` | 365 | 30 to 3650 |

## Agent call: `status_sample`

Encrypted like `mon_stats`. The reply starts with `1;` and then one `key=value` per line:

- `cores`, `uptime` (seconds), `load1`
- `cpu_total`, `cpu_idle`: cumulative jiffies from `/proc/stat` (CPU percent is the change between samples)
- `net_rx`, `net_tx`: cumulative bytes over all non-loopback interfaces (rates are the change between samples)
- `mem_total`, `mem_used`: bytes (`mem_used` = total minus available)
- `disk_total`, `disk_used`: bytes for the root filesystem
- `proc_cpu.1` to `proc_cpu.5` and `proc_ram.1` to `proc_ram.5`: `pid|name|value`
  (value is CPU percent for `proc_cpu` and bytes of RAM for `proc_ram`)
- `home.<id>`: `state|cpu|ram|disk|disk_at`, one per game server with a status file. CPU is percent
  of the machine, RAM in bytes, both for the game and bot process trees (and the server's own
  container). `disk` is the folder size in bytes, measured by the agent in the background at most
  once an hour, and `disk_at` when; both are empty until the first measurement.
- `container.<n>` (Linux): `name|image|cpu|ram|process`, one per Docker container that is not a game
  server's own container.

Until an agent implements `status_sample`, its server shows UNKNOWN (`unknown_agent`), not DOWN.

## Status codes

- `ok`: UP
- `down`: no answer from the agent (DOWN)
- `unknown_agent`: the agent refused or does not know `status_sample`
- `unknown_key`: the encryption key does not match
- `unknown_reply`: the reply could not be read

## Install

Panel installs and updates run the migration. Then install the poller unit:
copy `install/gse-status-poller.service` to `/etc/systemd/system/`, check the paths, then
`systemctl enable --now gse-status-poller`.
