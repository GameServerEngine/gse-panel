# Game Catalog and Pricing (decided 2026-10-21)

## Ownership
- The panel owns the catalog and all pricing. The storefront (modules/billing) only displays and orders.
- Each game comes from a game configuration in `config_homes` (loaded from the game XML).

## Catalog (panel admin)
- One row per game configuration. Per remote server (location): enabled, pricing mode, prices.
- Only enabled games appear on the storefront.

## Pricing modes (per game, per location)
1. **Per slot (default):** price per player slot per period, from the minimum to the game's maximum slots.
2. **Tiers:** four sizes, Small, Medium, Large, and Full, at 25%, 50%, 75%, and 100% of the game's maximum slots by default. Each tier has its own price (editable).
3. **Base plus extra:** a base price covering a base number of slots, plus a price per slot above the base. Setting the base to 0 slots and a per-slot price gives per-slot pricing.

## Billing periods and quantity
- Period: daily, monthly (default), or yearly. Default currency: USD.
- The customer picks a quantity. Total = unit price x quantity (quantity 4 on a monthly rate = 4 months).

## Starting prices
- Use the existing prices in `billing_services` as the starting values for each game, converted into the chosen pricing mode.

## Orders and renewals
- The price, period, and slot count are calculated on the server and locked onto the order when placed. Renewals charge the locked price, even if the catalog changes later.
- The browser never sets a price.
- `billing_services` stays as the order-time table, filled from the catalog, so the order, renewal, and cron code keep working during the move.

## Acceptance
- Each mode computes the correct total for minimum, middle, maximum, and out-of-range slot counts.
- Quantity multiplies the total correctly for daily, monthly, and yearly.
- Changing the catalog does not change an existing order's price.
- Only enabled games appear on the storefront, and only for their enabled location.
