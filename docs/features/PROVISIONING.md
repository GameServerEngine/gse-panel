# Provisioning

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

Server provisioning flows are centered in `user_games` and supported by billing and server manager modules.

Important references:

- `modules/user_games/module.php`
- `modules/user_games/add_home.php`
- `modules/user_games/edit_home.php`
- `modules/user_games/assign_home.php`
- `modules/user_games/clone_home.php`
- `modules/user_games/check_expire.php`
- `includes/api_functions.php`
- `modules/billing/module.php`
- `modules/server/module.php`

## What Provisioning Must Handle

- server home creation
- port assignment
- passwords
- FTP access
- expiration and suspension
- clone/migrate behavior
- game XML and mod selection

## Recommended Direction

Provisioning should be validated and logged more like a commercial hosting platform:

- clear create/install status
- no port collisions
- secure default passwords
- clear billing lifecycle behavior
- post-create status verification

