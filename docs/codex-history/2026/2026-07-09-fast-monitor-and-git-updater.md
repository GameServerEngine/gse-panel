# Fast Game Monitor and Git updater hotfix

## Summary
Removed remaining slow Game Monitor render work and changed Panel updater defaults/behavior toward direct Git checkout updates.

## Changes
- Game Monitor now uses agent status only for lifecycle display.
- Removed Game Monitor ports panel rendering.
- Removed Game Monitor-side update log, startup file, and LGSL connect-link checks from render.
- Added UPDATING lifecycle display support.
- Panel updater default repo now points to the Panel repo.
- Panel updater uses existing checkout fetch/checkout/pull when repo root already exists, or clones when missing.
- Timestamp marker path now uses `Panel/modules/billing/timestamp.txt` instead of old `Website/timestamp.txt`.

## Validation
Pending final validation before commit.
