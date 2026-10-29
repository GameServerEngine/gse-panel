# PHP compatibility hotfix for Game Monitor

## Summary
Removed PHP-version-sensitive syntax from recently changed Game Monitor files so the live Panel can run on older OGP-style PHP runtimes.

## Changes
- Removed scalar parameter and return type declarations from `get_server_billing_expiration_html`.
- Replaced null coalescing expressions with `isset(...) ? ... : ...` checks.
- Replaced short array literals introduced in recent Game Monitor paths with `array()`.
- Kept the fast Game Monitor behavior: non-blocking status lights and static port display.

## Validation
Pending final validation before commit.
