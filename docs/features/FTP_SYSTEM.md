# FTP System

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

GSP includes an FTP module and FTP-related provisioning fields.

Important references:

- `modules/ftp/module.php`
- `modules/user_games/module.php`
- `modules/server/module.php`

## Current Notes

- FTP remains useful for advanced users.
- The embedded FTP UI is legacy-heavy and should be treated carefully.
- FTP credentials need to be protected and easy to reset.
- Agent-managed FTP accounts use the shared `ftp_mgr` RPC and Pure-FTPd-compatible virtual users on both Linux and Windows agents.

## Recommended Direction

- keep FTP as an advanced access method
- document it clearly
- avoid exposing control paths
- audit the legacy embedded file manager code
