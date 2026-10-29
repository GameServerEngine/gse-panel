# GameServer Engine Panel Repository Structure

Workspace reference: [`../../AGENTS.md`](../../AGENTS.md)

This repository is the GameServer Engine (GSE) Panel runtime and documentation repository. It is the control-plane Git repository inside the `/home/dev/Projects/GSP` multi-repository workspace container.

## Top-level layout

| Path | Purpose |
| --- | --- |
| `index.php`, `home.php`, `install.php` | Panel web entry points |
| `modules/` | Web panel modules, config game XML, update flow, billing/storefront module, and admin tools |
| `includes/` | Shared Panel libraries and runtime bootstrap helpers |
| `protocol/` | Game query protocol libraries |
| `sql/` | Panel SQL migrations and support scripts |
| `themes/` | Panel themes and theme assets |
| `docs/` | Architecture, module, feature, and development documentation |
| `README.md` | Repository overview and entry point |

## Panel layout

- `modules/` - user/admin modules
- `includes/` - shared panel libraries
- `modules/config_games/` - XML game definitions and schema
- `modules/update/` - panel update workflow
- `modules/gamemanager/` - lifecycle and status UI
- `modules/addonsmanager/` - server content and Workshop workflows
- `modules/billing/` - canonical Gameservers.World storefront and billing module

## Documentation rule

If a change affects a module boundary, update flow, or agent contract, document it here and in the more specific architecture or feature docs.
