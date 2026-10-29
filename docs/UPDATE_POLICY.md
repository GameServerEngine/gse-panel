# GameServer Engine Update Policy

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

## Update rule

Code updates must never remove customer-owned data.

Never overwrite or delete:

- home folders
- game server data
- save files
- configuration files
- Workshop content
- custom scripts
- uploads
- logs unless a documented rotation policy applies

## What may be updated

- application code
- bundled helper scripts explicitly owned by the product
- update metadata used by the Panel or agents

## Agent restart-time updates

Agent self-update requests are queued through `agent_self_update`. The running agent records pending state only; Linux `gsp_agent_run` and Windows `GSP/agent_launch.sh` apply the request on the next normal restart when `agent_auto_update=1`.

The Panel per-agent update action completes the workflow by queueing the request and then calling the normal `agent_restart` RPC for that agent. If the request cannot be queued, the Panel does not restart the agent. If restart-time updates are disabled on the host, the restart leaves the request in `pending` state so an admin can enable `agent_auto_update` or replace the request later.

The shared status contract is `pending`, `running`, `completed`, `failed`, and `rolled_back`. Update status includes the requested repo, branch, source path, and commit fields so the Panel can distinguish queued work from completed or failed work.

## Cross-repository rule

If an update path changes panel behavior, agent startup behavior, or filesystem ownership assumptions, update the docs in all affected repositories before the code change is considered complete.
