# Scheduler

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current Implementation

The scheduler lives in the `cron` module on the Panel and in scheduler methods inside the agents.

Important files:

- `modules/cron/module.php`
- `modules/cron/cron.php`
- `modules/cron/shared_cron_functions.php`
- `Agent_Linux/gsp_agent.pl`
- `Agent-Windows/GSP/gsp_agent.pl`

## How It Works Today

The Panel builds cron-like jobs and sends them to the agent using RPC methods such as:

- `scheduler_add_task`
- `scheduler_edit_task`
- `scheduler_del_task`
- `scheduler_list_tasks`
- `scheduler_read_tasks`

The agent stores and executes the tasks locally.

## Current Task Model

Current jobs are built as cron expressions plus a command string.

The command can be:

- a safe predefined server action
- an update-related action
- a server content action
- a raw admin command

## Scheduled Actions Observed

From the current codebase and related Server Content work, the action set includes:

- restart server
- stop server
- start server
- Steam auto update
- server content update checks
- server content install/update actions
- validate files
- backup before update
- notify-only update flow

## Problems With the Current Design

- The task model is not strongly typed.
- Customer-safe and admin-only actions are too easy to blur together.
- Task output and run history are not user-friendly enough.
- The agent is the executor, but the Panel needs a better task record and result view.
- Overlap/conflict rules are not explicit.
- Missed-task behavior after reboot should be documented and normalized.

## Recommended Next-Step Shape

Use typed actions instead of raw command strings for customer-facing scheduler tasks.

Suggested fields:

- action key
- display name
- role
- arguments
- timeout
- retry behavior
- conflict rules
- log policy

## Agent Interaction Pattern

```text
Panel schedules task
  -> Panel stores intent and UI data
  -> Panel sends job to agent
  -> agent writes/loads scheduler state
  -> agent executes action at the right time
  -> agent logs result
  -> Panel reads back task state
```

## What To Inspect in Code

If scheduler behavior needs deeper investigation, start with:

- `modules/cron/cron.php`
- `modules/cron/shared_cron_functions.php`
- `Agent_Linux/gsp_agent.pl` scheduler subroutines
- `Agent-Windows/GSP/gsp_agent.pl` scheduler subroutines

## Current Panel Update Finding

The current scheduler does not provide a first-class path for scheduling Panel, Website, or remote agent self-updates. Existing scheduled update actions are game-server oriented, for example `gamemanager/update&type=steam` and Server Content actions. Backup and retention behavior in `modules/administration/panel_update.php` therefore applies to manual/admin-triggered updater flows, not to any existing scheduled self-update job.
