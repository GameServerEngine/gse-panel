# GameServer Engine Command Flow

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

## Canonical flow

User
→ Panel module
→ panel request builder
→ communication layer
→ Linux or Windows agent
→ GSP Perl agent
→ OS command or process control
→ game server process

`GSP` in the agent step is a legacy compatibility identifier for the existing Perl agent/runtime naming. The current product name is GameServer Engine (GSE).

## Typical actions

- start / stop / restart
- monitor status
- read logs
- install content or Workshop items
- update panel or agent binaries
- run scheduled tasks

## Rule

The Panel owns the decision. The agent owns execution. Keep those responsibilities separate.
