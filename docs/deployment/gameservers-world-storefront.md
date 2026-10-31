# GameServers.World storefront deployment

The canonical public storefront lives in:

```text
modules/billing
```

The old duplicate `modules/website` module has been removed to avoid two active public website copies.

GameServers.World is the official production hosting storefront and demonstration environment for the GameServer Engine product family. The storefront visual identity uses optimized project-owned GameServers.World assets copied into:

```text
modules/billing/images/brand
```

Brand provenance:

- Source globe asset: `/home/dev/Projects/gse-globe-logo.png`
- Source globe SHA-256: `26111a1968693b5386537e42e99e3710096b42f8c3788057db88905b4d353a59`
- Source text asset: `/home/dev/Projects/gse-text-logo.png`
- Source text SHA-256: `892faf37b67cebea0229ddbe4dd6b88fd22bf730fd354a88cadb05e3108a5442`
- The storefront must reference optimized copied assets, not source assets outside the repository.

Copied production assets:

| Storefront asset | Source asset | SHA-256 |
| --- | --- | --- |
| `modules/billing/images/brand/gsw-globe-logo.png` | `/home/dev/Projects/gse-globe-logo.png` | `ef58334384f148a22b68d93020cd7bbf0d375dd5066225b3bbf3f36ffbd3760f` |
| `modules/billing/images/brand/gsw-globe-icon.png` | `/home/dev/Projects/gse-globe-logo.png` | `f0cd01e0c392da5bf5a7dd147a020781ec507e01934e52e0f09007353677c6b1` |
| `modules/billing/images/brand/gsw-text-logo.png` | `/home/dev/Projects/gse-text-logo.png` | `e9efb1a8fa1b900fbc5dab5808218128a22af0fb50167ba7fcb146d4f5e091cb` |
| `modules/billing/images/brand/apple-touch-icon.png` | `/home/dev/Projects/gse-globe-logo.png` | `f0cd01e0c392da5bf5a7dd147a020781ec507e01934e52e0f09007353677c6b1` |
| `modules/billing/images/brand/favicon-16x16.png` | `/home/dev/Projects/gse-text-logo.png` | `8aa73660ce4ba498558ac19719f726d7570cc24dfe06ccb0723a1567d0650aee` |
| `modules/billing/images/brand/favicon-32x32.png` | `/home/dev/Projects/gse-text-logo.png` | `5ff77ca80f6790df346fb58c31f331e770eb622f5cb1a101a3218ae6f97faa03` |
| `modules/billing/images/brand/favicon-48x48.png` | `/home/dev/Projects/gse-globe-logo.png` | `284178c7fa4713b7e6d9e5e4d558e4c5c4ec61688e564c28198fdbb293a3809d` |

Apache should serve the storefront as its own virtual host with this document root:

```text
/var/www/html/GSE/Panel/modules/billing
```

Use `examples/apache/gameservers.world-billing-vhost.conf` as the vhost template.

The billing directory contains a `.htaccess` file that disables directory browsing and blocks direct HTTP access to config, data, logs, SQL, helper classes, and internal include directories. PHP filesystem includes are not affected by these HTTP restrictions.

The shared storefront shell lives in `modules/billing/includes/menu.php`, `modules/billing/includes/footer.php`, and `modules/billing/css/header.css`. Public pages must preserve billing helper URLs, login/logout behavior, account links, cart badge behavior, admin visibility, support/contact/docs routes, and control-panel access behavior.

The normal visible page experience must use a single Gameservers.World logo treatment in the shared header. Favicon and touch icon assets remain available for browser and device chrome, but homepage, trust, catalog, and footer sections should not repeat decorative storefront logo imagery. Desktop may show the combined globe plus wordmark inside the header link; mobile uses the same accessible brand link in a compact centered treatment.

Mobile navigation is intentionally a compact dropdown from the shared header, not a full-height stacked menu. Catalog cards should keep the product order clear: game name, concise description, slot/price metadata, and one restrained Configure Server action that preserves the existing order URL.

The homepage is a concise customer-first hosting landing page. It should not render a featured catalog or query internal runtime nodes for public location display. The full game-server catalog remains on `modules/billing/serverlist.php`; the homepage should instead summarize support, pricing, flexibility, practical hosting experience, current public hosting locations, and the primary browse/support actions.

The current public homepage location list is static customer-facing copy:

- Kansas City
- Los Angeles
- New York City
- Dallas
- Ireland

Do not expose hostnames, IP addresses, node IDs, or other infrastructure internals in the homepage location block.

## Live pull

From the live checkout root:

```bash
cd /var/www/html/GSE
git pull origin unstable
```

Do not use `sudo git pull` unless the checkout is intentionally owned by root. The preferred owner is the deployment user, for example `gameserver`.


## Remove old duplicate website folder

Older deployments may still have an untracked sibling folder:

```bash
rm -rf /var/www/html/GSE/Website
```

The repository now keeps only `modules/billing` for the public storefront.
