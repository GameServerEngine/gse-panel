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
