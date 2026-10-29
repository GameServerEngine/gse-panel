# Container Platform Proof Of Concept

Status: version 0.1 proof of concept.

This slice proves the customer website to panel to agent to managed container loop. It intentionally avoids rewriting legacy game-server home provisioning.

## Customer Flow

1. Customer logs into Gameservers.World.
2. Customer opens `modules/billing/container_poc.php`.
3. The page loads active rows from `gsp_container_applications`.
4. `None / Empty Container` is seeded with sort order `0` and selected by default.
5. Customer selects an application, reviews recommendations, configures CPU, memory, storage, location, and ports.
6. The panel validates resources against application minimums.
7. The panel creates a row in `gsp_container_provisioning_jobs`.
8. The panel reserves ports in `gsp_container_reserved_ports`.
9. The panel calls the selected registered agent at `/v1/containers`.
10. If the application has Server Content, the panel calls `/v1/containers/{container_id}/content-jobs`.
11. The panel starts the container and records it in `gsp_managed_containers`.
12. The panel provisions an Agent-native SSH/SFTP access profile for the
    container and stores only non-secret connection metadata.
13. The customer sees the running container in the unified Container Monitor on
    `modules/billing/my_servers.php` and can start, stop, restart, reset the
    SSH/SFTP password, or delete it.

## Administration

Administrators use `modules/billing/admin_container_poc.php` to manage the proof-of-concept control-plane data:

- Locations
- Agents
- Application Catalog / Server Content definitions
- Container plans
- Port pools
- Reserved ports through allocation records
- Recent provisioning jobs

Agent records include base URL, token, enabled state, heartbeat timestamp, platform, version, available CPU, memory, storage, IP addresses, capabilities, current container count, and current application count. The visible state is derived as `Online`, `Offline`, `Busy`, or `Disabled`.

## Database

Apply `sql/004_container_platform_poc.sql`, or load a POC page as an authenticated user/admin and let `container_poc_ensure_schema()` create the same tables.

Important tables:

- `gsp_container_locations`
- `gsp_container_agents`
- `gsp_container_applications`
- `gsp_container_plans`
- `gsp_container_port_pools`
- `gsp_container_reserved_ports`
- `gsp_container_provisioning_jobs`
- `gsp_managed_containers`

`gsp_managed_containers.access_profile_json` stores the Agent-returned
non-secret access profile: host, port, server-specific username, support flags,
revocation state, and timestamps. Plaintext access passwords are never stored;
the reset/provision response displays the one-time generated password only for
the current browser response.

The migration seeds:

- `None / Empty Container`
- `Minecraft POC`
- `Kansas City`
- two small plans
- a local POC port pool

## Agent Contract

The panel talks to the Python Container Engine Agent with bearer authentication:

- `POST /v1/containers`
- `POST /v1/containers/{container_id}/content-jobs`
- `POST /v1/containers/{container_id}/start`
- `POST /v1/containers/{container_id}/stop`
- `POST /v1/containers/{container_id}/restart`
- `DELETE /v1/containers/{container_id}`
- `GET /v1/containers/{container_id}/ports/status`
- `POST /v1/containers/{container_id}/access`
- `GET /v1/containers/{container_id}/access`
- `GET /v1/access/profiles`

The agent token is stored in `gsp_container_agents.api_token`; do not commit production tokens.

## Validation

Safe local checks:

```bash
php -l modules/billing/includes/container_poc.php
php -l modules/billing/container_poc.php
php -l modules/billing/admin_container_poc.php
php modules/billing/tests/ContainerPocWorkflowTest.php
```

Agent contract check:

```bash
cd /home/dev/Projects/container-engine-agent
.venv/bin/python -m pytest tests/test_panel_poc_contract.py -q
```

## Migration Notes

This proof of concept adds a new container provisioning path beside existing billing/game-server pages. It does not remove `serverlist.php`, `billing_orders`, `server_homes`, legacy XML game configs, or OGP/GSP-compatible agent communication. The new path uses the Python container agent for Docker-oriented managed containers.
