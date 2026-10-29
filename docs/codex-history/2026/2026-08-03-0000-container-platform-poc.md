# Task

## User Request

Implement a proof of concept where a customer can browse database-backed applications, choose Empty Container or Server Content, configure resources and ports, submit provisioning, have the panel create a job, have the agent create and optionally install content into a managed container, report status back, and let the customer start, stop, restart, and delete the container.

## Problem

The storefront had billing and legacy game-server flows, and the Python container agent already had a useful container/content API, but there was no direct customer-facing application-catalog workflow tying the website to the container agent.

## Root Cause

The existing panel provisioning model is centered on legacy game homes and XML game configs. The container proof-of-concept needed isolated catalog, agent, job, port, and managed-container records rather than another patch to the old home creation path.

## Repositories Inspected

- `/home/dev/Projects/GSE-Panel`
- `/home/dev/Projects/container-engine-agent`

## Files Read

- `README.md`
- `AGENTS.md`
- `docs/ARCHITECTURE.md`
- `docs/PANEL_ARCHITECTURE.md`
- `docs/AGENT_COMMUNICATION.md`
- `docs/features/PROVISIONING.md`
- `docs/modules/SERVER_CONTENT_MANAGER.md`
- `modules/billing/README.md`
- `modules/billing/bootstrap.php`
- `modules/billing/includes/menu.php`
- `modules/billing/my_servers.php`
- `panel.sql`
- container-agent `README.md`
- container-agent `AGENTS.md`
- container-agent `docs/AGENT_API.md`
- container-agent `src/container_engine_agent/api/routes.py`
- container-agent `src/container_engine_agent/models/containers.py`

## Files Changed

- `README.md`
- `docs/features/CONTAINER_PLATFORM_POC.md`
- `docs/codex-history/2026/2026-08-03-0000-container-platform-poc.md`
- `modules/billing/admin.php`
- `modules/billing/admin_container_poc.php`
- `modules/billing/container_action.php`
- `modules/billing/container_poc.php`
- `modules/billing/css/header.css`
- `modules/billing/includes/container_poc.php`
- `modules/billing/includes/menu.php`
- `modules/billing/my_servers.php`
- `modules/billing/tests/ContainerPocWorkflowTest.php`
- `sql/004_container_platform_poc.sql`

## Architecture Decisions

The POC adds an isolated container path under `modules/billing` instead of replacing legacy game-server provisioning. The panel owns catalog, validation, port allocation, job state, and lifecycle UI. The Python container agent remains the execution boundary for container creation, content jobs, and lifecycle actions.

## Validation Performed

- `php -l modules/billing/includes/container_poc.php`
- `php -l modules/billing/container_poc.php`
- `php -l modules/billing/container_action.php`
- `php -l modules/billing/admin_container_poc.php`
- `php -l modules/billing/my_servers.php`
- `php modules/billing/tests/ContainerPocWorkflowTest.php`
- `php modules/billing/tests/BillingActionSecurityTest.php`
- `php modules/billing/tests/BillingCouponHelperTest.php`
- `php modules/billing/tests/BillingLifecycleServiceTest.php`
- `php modules/billing/tests/BillingRequestPathSourceTest.php`
- container-agent `.venv/bin/python -m compileall src`
- container-agent `PYTHONPATH=src .venv/bin/python -c "import container_engine_agent; print(container_engine_agent.__version__)"`
- container-agent `.venv/bin/python -m pytest -q`
- container-agent `.venv/bin/python -m ruff check src tests`
- container-agent `.venv/bin/python -m mypy src tests`
- `git diff --check` in both changed repositories

## Git Commits

Repository: none

Commit: none

Hash: none

## Remaining Work

Production deployment must apply the migration, register a real agent URL/token, and perform a live end-to-end container provisioning smoke test.
