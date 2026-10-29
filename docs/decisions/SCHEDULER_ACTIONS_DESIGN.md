# GSP Scheduler Actions Design

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This is an investigation and design report only. It does not implement code.

The goal is to redesign GSP's Scheduler / CRON feature into a safer, more useful automation system for game hosting customers and administrators.

Repository layout reviewed:

- `Agent-Windows`
- `Agent_Linux` (the Linux agent directory currently uses an underscore in this repository)
- `Panel`
- `Website`

## 1. Current Scheduler Module Findings

### Files inspected

Panel Scheduler module:

- `modules/cron/module.php`
- `modules/cron/navigation.xml`
- `modules/cron/cron.php`
- `modules/cron/user_cron.php`
- `modules/cron/shared_cron_functions.php`
- `modules/cron/events.php`
- `modules/cron/thetime.php`

Panel remote/API integration:

- `includes/lib_remote.php`
- `includes/api_functions.php`
- `modules/gamemanager/start_server.php`
- `modules/gamemanager/stop_server.php`
- `modules/gamemanager/restart_server.php`
- `modules/gamemanager/update_actions.php`
- `modules/gamemanager/rcon.php`
- `modules/addonsmanager/server_content_actions.php`

Agent scheduler implementation:

- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/gsp_agent.pl`

### Current database tables used

The current Scheduler module does not appear to own database tables. Module metadata has:

- `modules/cron/module.php`
- `$db_version = 0`

Scheduled jobs are stored on each agent in a flat file:

- Linux: `AGENT_RUN_DIR/Schedule/scheduler.tasks`
- Linux: `AGENT_RUN_DIR/Schedule/scheduler.pid`
- Linux: `AGENT_RUN_DIR/Schedule/scheduler.log`
- Windows/Cygwin: `AGENT_RUN_DIR/scheduler.tasks`
- Windows/Cygwin: `AGENT_RUN_DIR/scheduler.pid`
- Windows/Cygwin: `AGENT_RUN_DIR/scheduler.log`

This means the agent is currently the storage location for task definitions, and the Panel reconstructs task lists by asking each agent for its task file.

### Current actions

Current customer-visible scheduled actions from `get_action_selector()`:

- `restart`
- `stop`
- `start`
- `steam_auto_update` when the game XML installer is `steamcmd`

Additional server content actions are appended when `addonsmanager` is installed:

- `server_content_check_updates`
- `server_content_check_workshop_updates`
- `server_content_install_updates_if_stopped`
- `server_content_install_updates_next_restart`
- `server_content_install_updates_now`
- `server_content_install_updates_and_restart`
- `server_content_notify_updates_only`
- `server_content_update_all`
- `server_content_validate_files`
- `server_content_backup_before_update`

Admin-only raw command path:

- `modules/cron/cron.php` exposes a second form where an admin selects a remote server and enters a raw shell command.

### How tasks are created

Customer task creation path:

1. User opens `modules/cron/user_cron.php`.
2. User selects a game server and action.
3. Panel validates the five CRON fields using `checkCronInput()`.
4. Panel calls `build_cron_scheduler_command()`.
5. The command is built as a `wget` callback to `gsp_api.php`.
6. Panel sends the whole CRON line to the agent through `scheduler_add_task()`.
7. Agent appends the task line to `scheduler.tasks`.
8. Agent restarts its scheduler process.

Admin task creation path:

1. Admin opens `modules/cron/cron.php`.
2. Admin can use the same server/action selector.
3. Admin can also enter a raw command for a remote server.
4. Panel writes the raw command into the agent task file.

Current scheduled API callback examples:

```text
wget -qO- "<panel>/gsp_api.php?gamemanager/stop&token=<token>&ip=<ip>&port=<port>&mod_key=<mod_key>" --no-check-certificate > /dev/null 2>&1
```

```text
wget -qO- "<panel>/gsp_api.php?server_content/run_scheduled_action&token=<token>&home_id=<home_id>&action=<action>&options=<json>" --no-check-certificate > /dev/null 2>&1
```

### How tasks execute

Both agents use Perl `Schedule::Cron`.

Agent startup:

- Stops prior scheduler process using `scheduler_stop()`.
- Creates a `Schedule::Cron` object.
- Adds a read/reload task that runs every second:
  - `* * * * * *`
- Starts scheduler detached with `scheduler.pid`.

Agent task reload:

- `scheduler_read_tasks()` opens `scheduler.tasks`.
- It clears the in-memory timetable.
- It splits each line into five CRON fields plus command args.
- If args start with `%ACTION`, it uses `scheduler_server_action()`.
- Otherwise it adds a generic shell command task.

Current Panel-generated jobs are generic shell commands, not `%ACTION` jobs. They execute through:

- `scheduler_dispatcher()`
- backtick execution of the scheduled command
- append to `scheduler.log`

The older `%ACTION=start|stop|restart` direct-agent scheduler path still exists but does not appear to be the primary current Panel path.

### How task results are logged

Agent logging:

- `scheduler_log_events()` appends plain text to `scheduler.log`.
- Generic commands log:
  - the command text
  - any response text

Panel viewing:

- `modules/cron/events.php` reads `scheduler.log` from the selected remote server.
- It refreshes the log area periodically.

Limitations:

- No structured per-task run records.
- No status model such as pending/running/success/failed/skipped.
- No reliable per-run output attached to a task ID.
- No last run / next run / duration / exit code storage in the Panel DB.
- `wget` callbacks redirect output to `/dev/null`, so useful API responses are discarded.

### Current limitations and bugs

1. No Scheduler-owned database tables.
2. Tasks are stored per agent, so offline agents make task state invisible or stale.
3. Tasks contain API tokens in plain text inside agent task files.
4. Generic command scheduler can run arbitrary shell commands.
5. Admin raw command scheduling is powerful and should remain admin-only or be removed from the normal Scheduler UI.
6. Current customer tasks call the Panel through `wget`, so task execution depends on the agent reaching the Panel HTTP URL.
7. `--no-check-certificate` weakens TLS verification.
8. Task output is discarded for Panel API callbacks.
9. No retry policy.
10. No overlap prevention.
11. No conflict prevention, such as update and restart at the same time.
12. No job lock per game server.
13. No missed-run handling after agent downtime.
14. No clear timezone UX.
15. Admin and customer scheduling models are mixed in the same module.
16. Server content scheduled actions include duplicates and placeholders.
17. Some action names are not customer-friendly.
18. There is no typed argument system for warnings, backup paths, retention, RCON command allowlists, or wipe options.
19. There is no first-class notification support.
20. Linux and Windows store scheduler files in different relative locations.

## 2. Current Action Review

| Action | Keep/Remove/Admin Only | Why | Security Risk | Agent Support | Notes |
|---|---|---|---|---|---|
| `restart` | Keep | Core hosting feature. | Low if implemented through safe action. | Existing Panel API and agent restart support. | Should support warnings, save-world, lock, timeout, and result logging. |
| `stop` | Keep | Useful for scheduled shutdown windows and cost/resource control. | Low. | Existing Panel API and agent stop support. | Should verify stopped state through agent status. |
| `start` | Keep | Useful after maintenance windows. | Low. | Existing Panel API and agent start support. | Should show STARTING/ONLINE result, not only command fired. |
| `steam_auto_update` | Keep, rename | Useful but name is technical. | Medium due Steam credentials/update side effects. | Existing `steam_cmd` update path. | Rename to `update_server_files`; require game XML installer support. |
| `server_content_check_updates` | Keep internally, remove from customer dropdown for now | Useful as backend action but unclear to customers. | Low. | Partial Panel support. | Replace with clearer `check_content_updates`. |
| `server_content_check_workshop_updates` | Keep internally, remove from customer dropdown for now | Useful once Workshop system is mature. | Low/medium. | Partial support. | Expose later as `check_workshop_updates`. |
| `server_content_install_updates_if_stopped` | Keep internally | Safe behavior for automatic content updates. | Low. | Panel support. | Customer label should be `Update content when stopped`. |
| `server_content_install_updates_next_restart` | Keep internally | Useful queued-update pattern. | Low. | Panel support. | Needs real next-restart integration. |
| `server_content_install_updates_now` | Keep advanced customer/admin | Updates while server may be running can break files. | Medium. | Partial support. | Gate by game support and require warning. |
| `server_content_install_updates_and_restart` | Keep advanced customer/admin | Very useful but needs locking and warnings. | Medium. | Partial support. | Should become `update_mods_and_restart`. |
| `server_content_update_workshop` | Remove from dropdown; keep as internal alias | Duplicate with Workshop update action. | Medium. | Partial support. | Hide until Workshop redesign is implemented. |
| `server_content_update_all` | Remove/merge | Duplicate with install/update all. | Medium. | Partial support. | Replace with one clear `update_all_content`. |
| `server_content_notify_updates_only` | Remove for now | Name suggests notification but no notification system exists. | Low. | Partial check-only path. | Reintroduce after notifications exist. |
| `server_content_validate_files` | Keep admin/advanced | Useful repair/validate action. | Medium. | Partial support via generic script action. | Rename to `validate_content_files`; game support required. |
| `server_content_backup_before_update` | Remove or redesign | Currently sets an option but there is no clear backup implementation in that path. | Medium due false confidence. | Incomplete. | Replace with first-class backup action and update workflow option. |
| Raw remote shell command | Admin only or remove from normal UI | Powerful but dangerous. | High. | Existing generic scheduler execution. | Should not be available to customers. Should be audited if kept. |
| Legacy `%ACTION=start` | Remove/deprecate | Current Panel uses API callbacks. | Low. | Agent support exists. | Keep only during migration if old task files contain it. |
| Legacy `%ACTION=stop` | Remove/deprecate | Same as above. | Low. | Agent support exists. | Migrate to action registry. |
| Legacy `%ACTION=restart` | Remove/deprecate | Same as above. | Low. | Agent support exists. | Migrate to action registry. |

## 3. Competitor Feature Research

Sources reviewed:

- Nitrado automated tasks guide: https://server.nitrado.net/guides/automated-tasks-en
- Nitrado backup guide/FAQ: https://server.nitrado.net/en-US/guides/how-to-manage-and-restore-nitrado-server-backups/
- BisectHosting Starbase schedules: https://help.bisecthosting.com/hc/en-us/articles/40101097661083-How-to-Schedule-Tasks-on-the-Starbase-panel
- ZAP-Hosting scheduled tasks: https://zap-hosting.com/guides/docs/gameserver-scheduled-tasks
- Pterodactyl client schedules API docs: https://pterodactyl-panel.mintlify.app/api/client/schedules
- Shockbyte backups guide: https://shockbyte.com/help/knowledgebase/articles/how-to-backup-your-server-files
- PingPerfect scheduled server messages guide: https://pingperfect.com/knowledgebase/706/7-Days-to-Die--Scheduled-Server-Messages.html
- Host Havoc scheduled backups guide: https://hosthavoc.com/billing/knowledgebase/479/Creating-Scheduled-Backups.html
- GTXGaming Rust restart warning task guide: https://help.gtxgaming.co.uk/en/article/how-to-setup-restart-tasks-with-messages-for-your-rust-server-15zwnyr/

Common commercial features:

- Scheduled start/stop/restart.
- Scheduled backups.
- Scheduled console/RCON commands.
- Restart warning messages.
- Task offsets within a schedule.
- Backup retention limits.
- Restart-only-if-online option.
- Manual and automatic backup creation.
- Custom task scheduling.
- Game-specific tasks such as Rust wipes or server message tools.

Notable competitor patterns:

- Nitrado exposes simple automated power tasks: restart, start, stop. It also has automatic backups, but docs note timezone issues and game-specific backup behavior.
- BisectHosting Starbase schedules support separate schedules and tasks, including power actions and command tasks with time offsets.
- Pterodactyl's design is strong: schedules have multiple ordered tasks, time offsets, power actions, command actions, backup actions, `only_when_online`, and continue-on-failure behavior.
- ZAP-Hosting exposes start/stop/restart, restart-if-online, create backup, and execute command, with rate limits.
- Shockbyte emphasizes scheduled backup intervals and backup slot/auto-replace retention.
- PingPerfect supports scheduled messages and Console/RCON commands for games like 7 Days to Die.
- GTXGaming documents restart warnings/countdowns for Rust.

What GSP can do better:

- Use typed, safe game-aware actions instead of raw commands.
- Provide prebuilt restart workflows with save-world and warning steps.
- Tie Workshop/mod updates into the Scheduler.
- Add per-task locks and conflict prevention.
- Add structured logs and visible success/failure.
- Add notifications through Discord/email/panel.
- Add game XML capability detection so users only see actions that work.
- Add maintenance windows and "run when empty" automation.
- Add resource-based triggers using existing status/resource collection work.

## 4. Recommended GSP Scheduler Actions

### Customer-safe actions

- Restart server.
- Stop server.
- Start server.
- Backup server.
- Backup selected folders.
- Update Workshop mods.
- Update server content.
- Send warning message.
- Run allowed RCON command.
- Rotate logs.
- Delete old logs using admin-defined retention limits.
- Save world, if the game supports it.
- Check server status.
- Auto-restart if crashed.

### Advanced customer actions

- Scheduled wipe/reset for supported games.
- Validate/repair server files.
- Update SteamCMD game files.
- Clone backup to another server.
- Restore backup.
- Update mods and restart.
- Restart when player count is zero for X minutes.
- Restart if memory too high for X minutes.
- Restart if CPU stuck/high for X minutes.
- Scheduled config file replacement from approved templates.
- Scheduled database backup where applicable.

### Admin-only actions

- Arbitrary shell command.
- Raw script execution.
- Permission repair.
- Force kill process/session.
- Agent/node maintenance.
- Cleanup storage outside a server home.
- Clear global Workshop cache.
- Repair file ownership.
- Restart agent.
- Reboot node.
- Run panel update/maintenance.

## 5. Proposed Action System

Replace free-form action lists with a typed action registry.

Each action definition should include:

- `action_key`
- `display_name`
- `description`
- `category`
- `allowed_roles`
- `required_permissions`
- `supported_os`
- `required_agent_capability`
- `requires_game_running`
- `requires_game_stopped`
- `requires_rcon`
- `requires_workshop_support`
- `requires_steamcmd`
- `arguments_schema`
- `validation_rules`
- `timeout_seconds`
- `retry_policy`
- `overlap_policy`
- `conflict_group`
- `log_policy`
- `notification_events`

Example:

```yaml
scheduled_actions:
  restart_server:
    display_name: Restart Server
    role: customer
    agent_action: stop_wait_start
    required_permissions: [server.power.restart]
    args:
      warning_minutes:
        type: integer
        min: 0
        max: 60
        default: 5
      warning_message:
        type: string
        max_length: 160
        default: "Server restart in {minutes} minutes."
      save_world:
        type: boolean
        default: true
    timeout_seconds: 600
    conflict_group: server_power
    overlap_policy: skip_if_running
```

### Recommended schedule model

Move from "one CRON line equals one command" to:

- Schedule:
  - name
  - cron expression or interval
  - timezone
  - enabled
  - only_when_online
  - missed_run_policy

- Tasks:
  - ordered tasks within a schedule
  - action key
  - arguments
  - time offset
  - continue on failure

This matches the strongest commercial pattern and allows:

- 10 minutes before restart: send warning.
- 5 minutes before restart: save world.
- At restart time: restart server.
- 5 minutes after restart: send Discord notification.

### Suggested DB tables

`gsp_schedules`

- `id`
- `home_id`
- `remote_server_id`
- `name`
- `cron_minute`
- `cron_hour`
- `cron_day_of_month`
- `cron_month`
- `cron_day_of_week`
- `timezone`
- `enabled`
- `only_when_online`
- `missed_run_policy`
- `max_runtime_seconds`
- `created_by`
- `created_at`
- `updated_at`

`gsp_schedule_tasks`

- `id`
- `schedule_id`
- `sort_order`
- `time_offset_seconds`
- `action_key`
- `arguments_json`
- `continue_on_failure`
- `enabled`
- `created_at`
- `updated_at`

`gsp_schedule_runs`

- `id`
- `schedule_id`
- `home_id`
- `status`
- `scheduled_for`
- `started_at`
- `finished_at`
- `duration_seconds`
- `trigger`
- `last_error`

`gsp_schedule_task_runs`

- `id`
- `schedule_run_id`
- `schedule_task_id`
- `action_key`
- `status`
- `started_at`
- `finished_at`
- `exit_code`
- `message`
- `error`
- `log_path`
- `output_excerpt`

## 6. XML Integration

Game XML should declare game-specific Scheduler support.

Example:

```xml
<scheduler_support>
  <action key="restart" enabled="1" />
  <action key="rcon_warning" enabled="1" />
  <action key="world_save" enabled="1">
    <command>save</command>
  </action>
  <action key="workshop_update" enabled="1" />
  <action key="wipe" enabled="1">
    <strategy>rust_wipe</strategy>
  </action>
</scheduler_support>
```

Global actions:

- Start server.
- Stop server.
- Restart server.
- Backup server files.
- Rotate logs.
- Delete old backups/logs.
- Check status.

Game-specific actions:

- Send RCON warning.
- Save world.
- Run console command.
- Workshop update.
- Mod update.
- Wipe/reset.
- Database backup.
- Validate files.

Actions requiring RCON:

- Warning message.
- Save world.
- Player-count-aware empty restart if query is not enough.
- Allowed RCON command.
- Game-specific graceful shutdown.

Actions requiring SteamCMD:

- Update SteamCMD game files.
- Validate/repair Steam game files.

Actions requiring Workshop support:

- Update Workshop mods.
- Repair Workshop mods.
- Update mods and restart.

Actions requiring backup support:

- Backup server.
- Backup selected folders.
- Restore backup.
- Clone backup.

## 7. Agent Integration

### Preferred direction

The agent should execute typed scheduled actions, not raw customer shell text.

New agent methods could be:

- `scheduler_action_start(home_id, action_manifest_json)`
- `scheduler_action_status(home_id, action_run_id)`
- `scheduler_action_log(home_id, action_run_id, offset)`
- `scheduler_action_cancel(home_id, action_run_id)`

The Panel should store schedules and send due actions to agents, or the agent should receive a structured schedule manifest from Panel. The cleanest long-term design is Panel-owned schedules plus an agent-side runner for actions.

### Start/stop/restart

Agent should:

- Use existing start/stop/restart functions.
- Use the new agent status model as source of truth.
- Wait for state transitions.
- Return structured result.

Restart should:

1. Optional RCON warning.
2. Optional save-world.
3. Stop.
4. Wait configured seconds.
5. Start.
6. Poll until STARTING/ONLINE/UNRESPONSIVE.

### Backup

Agent should:

- Create compressed archives through a typed backup action.
- Support include/exclude folders from safe config.
- Store backup manifests.
- Enforce retention.
- Avoid backing up transient cache/log folders unless configured.

### Update

Agent should:

- Run SteamCMD update or server content update through typed job actions.
- Avoid overlapping update with running backup/restart.
- Mark restart required when applicable.

### RCON/console command

Agent should:

- Use existing `send_rcon_command` support.
- Validate commands against action rules.
- Log command and response.
- Redact credentials.

Customer-safe RCON should use templates:

- `say {message}`
- `save`
- `save-all`
- game-specific warning command

Raw RCON text should be advanced/admin controlled.

### Mod update

Agent should:

- Run Workshop/server-content job runner from the Workshop design.
- Return job status and logs.
- Mark restart required.

### Log cleanup

Agent should:

- Delete only configured log paths.
- Enforce age/size limits.
- Log every removed path count/bytes.

### Status/resource actions

Agent should:

- Check process/session/port status.
- Optionally check memory/CPU samples.
- Execute conditional restart only after threshold duration.

### Timeouts and failure reporting

Every action should have:

- timeout
- retry count
- retry delay
- result status
- error message
- log excerpt
- correlation ID

## 8. Task Logs and User Feedback

Recommended run statuses:

- `pending`
- `running`
- `success`
- `failed`
- `skipped`
- `canceled`
- `timed_out`

The UI should show:

- schedule name
- enabled/disabled
- next run time
- last run time
- last status
- last duration
- current running task
- output log
- error message
- retry count

Run details should show:

- each task in the schedule
- action arguments summary
- start time
- finish time
- result
- output/log

Do not rely only on `scheduler.log`.

## 9. Notifications

Supported notification channels:

- Panel notification.
- Email.
- Discord webhook.
- Generic webhook later.

Notification events:

- Before restart.
- After restart.
- Backup succeeded.
- Backup failed.
- Update available.
- Update installed.
- Task skipped because server was offline/running.
- Task failed.
- Disk retention cleanup ran.

Security:

- Webhook URLs must be stored securely.
- Do not expose tokens in task logs.
- Customers should not be able to send arbitrary webhooks from shared infrastructure unless allowed by policy.

Pre-restart warning types:

- RCON in-game message.
- Console command.
- Discord/webhook message.
- Panel notification.

## 10. Implementation Phases

### Phase 1: Inventory/report only

- Complete this report.
- Do not modify code.

### Phase 2: Remove or hide useless actions

- Hide duplicate server-content actions from customer dropdown.
- Keep internal aliases for backward compatibility.
- Hide `server_content_backup_before_update` until real backup exists.
- Keep raw remote command admin-only.

### Phase 3: Safe action registry

- Add PHP action registry.
- Define roles, permissions, arguments, validation, and display names.
- Replace hardcoded dropdown arrays.

### Phase 4: Task logging

- Add schedule/task/run tables.
- Store run status and results.
- Keep agent `scheduler.log` as low-level debug only.

### Phase 5: Restart/backup/update actions

- Implement typed restart with warning/save-world hooks.
- Implement first-class server backup action.
- Implement update server files action.

### Phase 6: RCON warnings

- Add game XML `scheduler_support`.
- Add allowed warning/save commands.
- Add command templates and validation.

### Phase 7: Workshop update integration

- Integrate with the redesigned Workshop/server-content job system.
- Add update mods and update mods then restart workflows.

### Phase 8: Notifications

- Add panel notifications.
- Add Discord webhook.
- Add email.

### Phase 9: Commercial polish

- Multi-task schedules with offsets.
- Clone schedule to another server.
- Maintenance window mode.
- Conditional empty-server restart.
- Resource threshold triggers.
- Missed-run handling.
- Conflict and overlap visualization.

## 11. Final Recommendation

### Remove or hide

- Hide raw server-content internal actions from customer dropdown.
- Remove customer-facing `server_content_notify_updates_only` until notifications exist.
- Remove customer-facing `server_content_backup_before_update` until backup is real.
- Merge duplicate update actions into clear labels.
- Deprecate legacy `%ACTION=` task format after migration.

### Keep

- Start server.
- Stop server.
- Restart server.
- SteamCMD update, renamed to `Update server files`.
- Server content / Workshop update, once the Workshop system is mature.
- Admin raw command only behind explicit admin permissions.

### Build first

1. Typed action registry.
2. DB-backed schedules and run logs.
3. Restart server with warning and optional save-world.
4. Backup server with retention.
5. Update server files.
6. Update Workshop mods.
7. Notifications.

### Admin-only

- Shell command.
- Raw script execution.
- Force kill.
- Permission repair.
- Node cleanup.
- Agent restart/reboot.

### Delay until later

- Resource-triggered restarts.
- Wipe/reset workflows.
- Restore backup scheduling.
- Clone schedules.
- Generic webhooks.
- Advanced conditional schedules.

## Summary

The current GSP Scheduler is functional but primitive. It stores CRON lines on agents, executes shell commands, and often calls back into the Panel through `wget`. That makes it flexible, but it does not provide the safety, visibility, or polish expected from a modern commercial game hosting panel.

The recommended path is a typed, DB-backed schedule system with safe action definitions, game XML capability flags, agent-side action execution, structured run logs, notifications, and first-class workflows for restart, backup, update, Workshop mods, and RCON warnings.

