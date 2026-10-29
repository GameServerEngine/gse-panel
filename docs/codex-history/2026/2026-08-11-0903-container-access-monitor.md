# Task

## User Request

Complete the current Leroy workflow step: inspect the Container Engine panel current state after Agent SSH/SFTP proof, and if missing, expose real SSH/SFTP status, server-specific access username, password set/reset, and connection host/port in unified Container Monitor without fake credentials/status.

## Problem

The panel Container POC created, started, and listed managed containers, but it did not provision or display the Agent-native SSH/SFTP access profile.

## Root Cause

The POC agent contract was still limited to lifecycle, content-job, delete, and port-status endpoints. `my_servers.php` displayed only resource and game-port data, and `container_action.php` had no password reset action.

## Repositories Inspected

- `/home/dev/Projects/container-engine-agent`
- `/home/dev/Projects/GSE-Panel`

## Files Read

- `/home/dev/Projects/AGENTS.md`
- `/home/dev/Projects/container-engine-agent/AGENTS.md`
- `/home/dev/Projects/container-engine-agent/README.md`
- `/home/dev/Projects/container-engine-agent/docs/AGENT_API.md`
- `/home/dev/Projects/container-engine-agent/docs/ARCHITECTURE.md`
- `/home/dev/Projects/container-engine-agent/docs/CONFIGURATION.md`
- `/home/dev/Projects/GSE-Panel/AGENTS.md`
- `/home/dev/Projects/GSE-Panel/docs/features/CONTAINER_PLATFORM_POC.md`
- `/home/dev/Projects/GSE-Panel/modules/billing/includes/container_poc.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/container_poc.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/my_servers.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/container_action.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/tests/ContainerPocWorkflowTest.php`
- `/home/dev/Projects/GSE-Panel/sql/004_container_platform_poc.sql`

## Files Changed

- `/home/dev/Projects/GSE-Panel/docs/features/CONTAINER_PLATFORM_POC.md`
- `/home/dev/Projects/GSE-Panel/modules/billing/includes/container_poc.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/container_poc.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/my_servers.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/container_action.php`
- `/home/dev/Projects/GSE-Panel/modules/billing/tests/ContainerPocWorkflowTest.php`
- `/home/dev/Projects/GSE-Panel/sql/004_container_platform_poc.sql`
- `/home/dev/Projects/GSE-Panel/docs/codex-history/2026/2026-08-11-0903-container-access-monitor.md`

## Architecture Decisions

- The panel calls the Agent `/v1/containers/{container_id}/access` endpoint and stores only non-secret profile metadata.
- The generated one-time password is displayed only from the provision/reset response and is not persisted.
- Container Monitor displays host, port, server-specific username, protocol support, revocation state, and last reset time from the Agent-returned profile.
- Password reset remains scoped by the existing managed-container ownership query.

## Validation Performed

- `php -l modules/billing/includes/container_poc.php`
- `php -l modules/billing/container_poc.php`
- `php -l modules/billing/my_servers.php`
- `php -l modules/billing/container_action.php`
- `php modules/billing/tests/ContainerPocWorkflowTest.php`
- `git diff --check`

## Git Commits

Repository

`/home/dev/Projects/GSE-Panel`

Commit

Not committed by workflow instruction.

Hash

N/A

## Remaining Work

- Validate live dev display if a panel runtime is available in this step.
