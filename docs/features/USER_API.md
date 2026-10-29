# User API

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Scope

This file documents the externally reachable API and webhook surfaces discovered in the repository.

Primary files:

- `gsp_api.php`
- `status_api.php`
- `Website/api/create_order.php`
- `Website/api/capture_order.php`
- `Website/api/log_error.php`
- `Website/webhook.php`
- `Website/paypal/webhook.php`

## Panel Automation API

Main endpoint:

- `gsp_api.php`

Transport:

- GET, POST, or JSON request body
- response is usually JSON
- `setting/get` returns plain text

Authentication:

- token-based
- token created through `token/create`
- host allowlist can be enforced with `api_authorized.hosts` and `api_authorized.fwd_hosts`

Important notes:

- the API is not a public anonymous API
- some routes are meaningful for normal users
- many routes are effectively admin-only because they mutate remote servers, create homes, or manage users

### Authentication Endpoints

| Endpoint | Auth | Purpose | Parameters | Returns |
|---|---|---|---|---|
| `gsp_api.php?token/create` | panel username/password | issue API token | `user`, `password` | JSON token payload |
| `gsp_api.php?token/test` | token | verify token | `token` | role/status |

### User-Visible Game Server Actions

| Endpoint | Auth | Purpose | Parameters | Returns |
|---|---|---|---|---|
| `gamemanager/start` | token + home access | start server | `ip`, `port`, optional `mod_key` | JSON status |
| `gamemanager/stop` | token + home access | stop server | `ip`, `port`, optional `mod_key` | JSON status |
| `gamemanager/restart` | token + home access | restart server | `ip`, `port`, optional `mod_key` | JSON status |
| `gamemanager/rcon` | token + home access | send RCON/console command | `ip`, `port`, optional `mod_key`, `command` | JSON command result |
| `gamemanager/update` | token + home access | update server | `ip`, `port`, optional `mod_key`, `type`, optional `manual_url` | JSON status |
| `litefm/list` | token + home access | list files | `ip`, `port`, `relative_path` | JSON listing |
| `litefm/get` | token + home access | read file | `ip`, `port`, `relative_path` | JSON file content |
| `litefm/save` | token + home access | write file | `ip`, `port`, `relative_path`, `contents` | JSON status |
| `litefm/remove` | token + home access | delete file | `ip`, `port`, `relative_path` | JSON status |
| `addonsmanager/list` | token | list add-on templates | `token` | JSON list |
| `addonsmanager/install` | token + home access | install named add-on | `ip`, `port`, `addon_id` | JSON status |
| `steam_workshop/install` | token + home access | legacy Workshop install | `ip`, `port`, optional `mod_key`, `mods_list` | JSON status |
| `server_content/run_scheduled_action` | token + home access | trigger typed server-content action | `home_id`, `action`, optional `options` | JSON status |

### Admin-Oriented API Routes

| Endpoint | Auth | Purpose | Parameters | Returns |
|---|---|---|---|---|
| `server/list` | admin token | list remote agents | `token` | JSON list |
| `server/status` | admin token | status of remote agent | `remote_server_id` | JSON status |
| `server/restart` | admin token | restart agent | `remote_server_id` | JSON status |
| `server/create` | admin token | create remote agent record | agent connection fields | JSON status |
| `server/remove` | admin token | remove remote agent record | `remote_server_id` | JSON status |
| `server/add_ip` | admin token | add IP to agent | `remote_server_id`, `ip` | JSON status |
| `server/remove_ip` | admin token | remove IP from agent | `remote_server_id`, `ip` | JSON status |
| `server/list_ips` | admin token | list assigned IPs | `remote_server_id` | JSON list |
| `server/edit_ip` | admin token | edit assigned IP | `remote_server_id`, `old_ip`, `new_ip` | JSON status |
| `user_games/list_games` | token | list game configs | `system`, `architecture` | JSON list |
| `user_games/list_servers` | token | list homes visible to token | none | JSON list |
| `user_games/create` | admin token | create game home | remote server, config, port, passwords, slots, affinity, nice | JSON status |
| `user_games/clone` | admin token | clone home | origin + new home fields | JSON status |
| `user_games/set_expiration` | admin token | change home expiry | `home_id`, `timestamp` | JSON status |
| `user_admin/*` | admin token | user CRUD and assignments | varies | JSON status |
| `gamemanager_admin/reorder` | admin token | reorder homes in UI | token | JSON status |
| `setting/get` | token | read setting | `setting_name` | plain text or `-1` |

## Public Status API

Endpoint:

- `status_api.php?token=...`

Authentication:

- shared query token stored in `status_api_local.php`

Purpose:

- public, read-only node summary
- intended for lightweight dashboards or public status pages

Behavior:

- caches agent stats locally for 30 seconds
- probes agents with TCP reachability
- normalizes CPU, memory, and disk stats when available

Returns:

- JSON object with `generated_at` and `nodes[]`

## Scheduler-As-API

The scheduler does not call agents directly at runtime. It stores cron lines on the agent that usually call back into:

- `gsp_api.php?gamemanager/*`
- `gsp_api.php?server_content/run_scheduled_action`

This makes `gsp_api.php` part of the internal scheduler runtime contract.

## Website API Endpoints

### Payment Creation And Capture

| Endpoint | Auth | Purpose | Parameters | Returns |
|---|---|---|---|---|
| `Website/api/create_order.php` | storefront session / checkout context | create PayPal order | checkout/cart payload | JSON PayPal order response |
| `Website/api/capture_order.php` | storefront session / checkout context | capture approved PayPal order | order/capture payload | JSON capture result |

These are thin compatibility wrappers that dispatch into the current billing runtime selected by:

- `Website/_compat_include.php`
- `website_billing_runtime_file(...)`

### Client Error Logging

| Endpoint | Auth | Purpose | Parameters | Returns |
|---|---|---|---|---|
| `Website/api/log_error.php` | none | receive cart/client JS error payloads | JSON body | JSON `{status: logged}` or error |

Security note:

- this endpoint is intentionally open
- it writes to `Website/logs/client_errors.log`
- rate limiting is not obvious in the current implementation

### Webhooks

| Endpoint | Source | Purpose | Auth Model |
|---|---|---|---|
| `Website/webhook.php` | PayPal | verify and process payment webhook | PayPal OAuth + webhook signature verification |
| `Website/paypal/webhook.php` | PayPal | compatibility entrypoint forwarding to `Website/webhook.php` runtime | same |
| `modules/billing/webhook.php` | payment runtime compatibility | billing-side webhook entrypoint | gateway-specific |

## Security Controls

| Control | Where |
|---|---|
| token auth | `gsp_api.php` |
| host allowlist | `api_authorized.hosts`, `api_authorized.fwd_hosts`, `settings/api_hosts.php` |
| role / ownership checks | inside `api_*` handlers in `gsp_api.php` |
| webhook signature verification | `Website/webhook.php` |

## Search Coverage Used For This Document

- `rg -n "^function api_" gsp_api.php`
- `sed -n '1,240p' gsp_api.php`
- `sed -n '1,240p' status_api.php`
- `find Website/api -maxdepth 1 -type f`
- `sed -n '1,220p' Website/webhook.php`
