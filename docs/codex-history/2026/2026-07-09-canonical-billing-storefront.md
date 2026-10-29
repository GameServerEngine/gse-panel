# Canonical billing storefront

## Summary
Made `Panel/modules/billing` the single active public GameServers.World storefront and removed the duplicate legacy `Panel/modules/website` copy.

## Changes
- Removed `Panel/modules/website` from the repo.
- Added `Panel/modules/billing/.htaccess` to make the billing module safe as a standalone document root.
- Added `examples/apache/gameservers.world-billing-vhost.conf` showing the vhost target for the live site.
- Added deployment notes under `docs/deployment/gameservers-world-storefront.md`.

## Intended Apache DocumentRoot

```text
/var/www/html/GSP/Panel/modules/billing
```

## Validation
Pending final validation before commit.
