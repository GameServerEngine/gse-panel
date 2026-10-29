# Storefront: Customer Rental Site

Status: decided, not started. Decisions dated 2026-10-20.

## Three sites, three audiences
- **gameserverengine.com:** the product site for people who want to start a game server hosting
  business. It describes the whole Game Server Engine platform.
- **The storefront inside the panel (`modules/billing`):** the customer-facing site for people who
  want to rent a game server. It is the main entry point for most customers. It explains what
  they get, without the inner workings. It must say that the panel includes an AI assistant that
  helps them edit and configure their game servers.
- **The panel itself:** the management tool for game servers and agents. Staff and customers
  log in here.

## Goals
- Update `modules/billing` so the rental site carries all customer-side information: plans,
  features, games, ports, Workshop support, scheduling, FTP, backups, support, and the AI assistant.
- Keep the storefront's cart, checkout, and order pages working.
- Use only the customer-side message. Hosting-business and platform details stay on
  gameserverengine.com.

## Domain and virtual host
- The installer already asks for a storefront domain. Keep that step.
- When a domain is given, the installer creates the Apache (or nginx) virtual host for the
  storefront from the template, enables it, checks the config, and reloads the web server.
- The panel's own domain is set the same way.
- HTTPS is the default wherever a certificate can be issued. Plain HTTP is only for local or
  VPN-only test installs.

## Open items
- Which storefront pages to rewrite first.
- Content review for the AI assistant claims, so the site never promises more than the AI does.

## Note: storefront is reachable without a virtual host (2026-10-20)
- Without a storefront virtual host, the customer-facing site is reachable at
  https://<panel domain>/<panel path>/modules/billing/index.php. The panel path and the storefront
  share one web root.
- Decide before launch: either block direct access to modules/billing from the web root when no
  storefront vhost exists, or accept the path as the storefront entry point. Recorded as an open item.
- Either way, the panel login and admin pages must stay separate from the storefront pages.

