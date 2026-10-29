# GSE features, todo and suggestions

Keep this list current. Items are grouped by status. Nothing here is a commitment to a date.

## Decided
- **Server content is the install system.** Install once, then configure. No multiple installs per server.
- **Expert mode** (per server, default ON). ON: configuration is done in the game's config files; panel option fields are hidden. OFF: the XML options show as checkboxes, text boxes and dropdowns, and their values are written to the config files on startup. The control password is only shown when Expert mode is OFF.
- **Control password** is hidden for now (it's set in server.cfg).
- **FTP** is one account per game server (`serverNN`), jailed to that server's `gamefiles`. Admin accounts (like `iaregamer`) are not tied to one folder.
- **Passwords** in the FTP table are stored as plain text (the panel has to show them).
- **Keep mods.** Mods are install variants, set in the XML.
- **Keep pre-install, post-install, pre-start and post-start commands** (XML schema, and custom versions can replace the defaults).

## To build
- **Server content: branch switching.** 7 Days to Die Stable and Unstable (`betaname`). Choose per server; switching applies on the next update.
- **Server content: Steam DLC** installed from Steam, as an option on the same install.
- **Server content: uninstall** (with its files), which mods don't have yet.
- **Server content: pre-start and post-start commands** run by the server content, for example backing up the last log and deleting older logs.
- **CPU priority** (nice level, per server). The panel saves it, but the game manager does not apply it when starting the game yet.
- **Pre-start and post-start commands** in the game manager. The old start path ran them; the current one doesn't.
- **Extra start parameters**: kept, shown only in Expert mode.
- **Expert mode:** write the option values into the config files at startup when Expert mode is OFF. Extend beyond the control password.
- **FTP accounts table** (`ftp_users`): one row per account, server accounts and admin accounts. Server rows link to it. Remove the three columns from `server_homes` once the links work.
- **FTP users page:** add, edit, disable, delete, reading from the accounts table.
- **Agent FTP account management:** the Windows agent's `ftp_mgr` is not fully working; it needs redeploying to KCWin and testing.
- **Per-server Windows accounts** (`server49` etc.) so each game runs as its own user and can't reach admin files. Needs a permissions design and a new branch.
- **Clean install migration:** the `expert_mode` column (and the FTP changes) need to be in the module install step, so a new install gets the same schema as the live panel.

## Known issues
- **File manager heading** shows the full path after a full sync; reapplied on core, needs adding to the repo's tests.
- **Deprecation warnings** from `str_replace()` on null values (`includes/functions.php` line 464, and similar in the edit page). Cast to string.
- **Monitor FTP row** should be checked on every server after each deploy.
- **Agent FTP list** on the FTP users page only shows while the KCWin agent answers.

## Suggestions for later
- **Log rotation** as a server-content post-start example (back up the last log, delete old logs).
- **Preset profiles** of startup parameters, saved per server, once expert mode is working.
- **Per-customer ownership** of server folders and FTP accounts.
- **Documentation** for customers: which settings are panel options and which are config-file edits.
