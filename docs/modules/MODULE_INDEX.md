# Module Index

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

This is the master module inventory for the Panel. Use it as the first stop before opening module code.

| Module | Purpose | Status | Recommendation |
|---|---|---|---|
| [`TS3Admin`](TS3Admin.md) | Teamspeak 3 admin interface | Functional / niche / disabled by default | Keep optional; do not default-install |
| [`administration`](administration.md) | Admin utilities and logging helpers | Functional / legacy-leaning | Keep |
| [`addonsmanager`](SERVER_CONTENT_MANAGER.md) | Server Content Manager | Functional / actively evolving | Keep / Improve |
| [`backup-restore`](backup-restore.md) | Backup and restore UI | Broken | Remove / Replace |
| [`billing`](billing.md) | Billing, provisioning, commerce | Functional / partial | Keep / Rewrite |
| [`circular`](circular.md) | Notification and circular messages | Functional | Keep / Improve |
| [`config_games`](config_games.md) | XML game definitions and CLI builder | Production / functional | Keep / Improve |
| [`cron`](SCHEDULER.md) | Scheduler / CRON | Functional / partial | Rewrite / Improve |
| [`dashboard`](dashboard.md) | Main landing dashboard | Functional | Keep / Improve |
| [`dsi`](dsi.md) | Dynamic Server Image | Legacy / functional / disabled by default | Keep optional; move toward contextual server actions |
| [`editconfigfiles`](editconfigfiles.md) | Config file shortcuts | Functional / partial | Keep / Improve |
| [`faq`](faq.md) | FAQ and help content | Functional | Keep / Improve |
| [`fast_download`](fast_download.md) | FastDL support | Functional | Keep |
| [`ftp`](ftp.md) | FTP administration | Functional / legacy-heavy | Keep / Rewrite |
| [`gamemanager`](GAMEMANAGER.md) | Server monitor, lifecycle, logs, RCON | Production / functional | Keep / Improve |
| [`lgsl_with_img_mod`](lgsl_with_img_mod.md) | LGSL server status images | Legacy / disabled by default | Deprecate / Keep optional as legacy |
| [`litefm`](litefm.md) | In-panel file manager | Functional | Keep / Improve |
| [`lostpwd`](lostpwd.md) | Password recovery | Functional | Keep |
| [`modulemanager`](modulemanager.md) | Module installation and access rights | Functional | Keep |
| [`mysql`](mysql.md) | MySQL hosting/admin | Functional / future-facing | Keep / Future |
| [`news`](news.md) | Legacy news / announcements | Legacy | Deprecate / Hide unless modernized |
| [`rcon`](rcon.md) | RCON admin tool | Functional | Keep / Improve |
| [`register`](register.md) | Account registration | Functional | Keep |
| [`server`](server.md) | Remote server and node manager | Production / administrative | Keep / Improve |
| [`settings`](settings.md) | Panel settings and themes | Production | Keep |
| [`status`](status.md) | Status page | Experimental / alpha | Rewrite / Deprecate |
| [`steam_workshop`](steam_workshop.md) | Dedicated Workshop module | Functional | Keep |
| [`subusers`](subusers.md) | Subuser permissions | Functional | Keep / Improve |
| [`support`](support.md) | Support landing page | Functional | Keep / Merge with tickets workflow |
| [`teamspeak3`](teamspeak3.md) | Teamspeak 3 web interface | Functional if sold / disabled by default | Keep optional; do not default-install |
| [`tickets`](tickets.md) | Support ticket system | Disabled by default / not currently wanted | Do not default-install; keep general `support` only |
| [`tshock`](tshock.md) | Terraria/TShock utilities | Alpha / partial / disabled by default | Keep conditional; expose only from Terraria context |
| [`update`](update.md) | Panel update tooling | Production / admin-only | Keep |
| [`user_admin`](user_admin.md) | User management | Production | Keep / Improve |
| [`user_games`](user_games.md) | Server provisioning and assignment | Production / functional | Keep / Improve |
| [`util`](util.md) | Miscellaneous utility tools | Functional / mixed | Keep / Rewrite selectively |
| [`website`](website.md) | Legacy public storefront pointer; canonical site is `billing` | Removed / compatibility docs only | Do not recreate |

## Shared Dependencies

Common dependencies across many modules:

- `includes/lib_remote.php`
- auth/session and role checks
- `config_games` XML parsing
- database access helpers
- server home and IP/port records

## High-Value Modules

The modules most likely to matter in future investigations are:

1. `gamemanager`
2. `config_games`
3. `user_games`
4. `addonsmanager`
5. `cron`
6. `litefm`
7. `ftp`
8. `billing`
9. `tickets`
10. `subusers`

## Fresh Install Default Policy

Fresh installer registration is intentionally smaller than the full module
inventory. The default set is limited to the core Panel control surface and
normal server-management workflows:

- `modulemanager`
- `server`
- `settings`
- `gamemanager`
- `config_games`
- `administration`
- `user_games`
- `user_admin`
- `update`
- `dashboard`
- `cron`
- `ftp`
- `litefm`
- `register`
- `lostpwd`
- `support`
- `addonsmanager`
- `steam_workshop`
- `billing`
- `subusers`

Legacy or specialty modules stay on disk for manual installation where useful,
but they are not registered or shown in top-level navigation on fresh installs:
`tickets`, `tshock`, `TS3Admin`, `teamspeak3`, `lgsl_with_img_mod`, `dsi`, and
`reseller`.

The installer also leaves other non-core legacy utilities out of the fresh
registration list, including `fast_download`, `editconfigfiles`, `rcon`, `faq`,
`mysql`, `backup-restore`, `circular`, `news`, `status`, and `util`. These can
be reviewed and installed manually when a deployment needs them.

TODO: TShock should become a Terraria-specific Game Monitor/server action after
its missing-context and token handling are hardened. LGSL and DSI should become
contextual status/image actions instead of global default navigation.
