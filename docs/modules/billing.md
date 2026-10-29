# Billing

Workspace reference: [`../../../AGENTS.md`](../../../AGENTS.md)

## Purpose

Commercial billing, provisioning, invoices, orders, transactions, coupons, and payment gateway integration.

`modules/billing` is also the canonical Gameservers.World customer-facing hosting storefront inside the GameServer Engine Panel repository. It is not a separate top-level repository and is not the whole Panel.

## Current Status

- Functional
- Partial / complex

## Dependencies

- Payment gateways
- provisioning flow
- server lifecycle and expiration logic
- docs under `modules/billing/docs/`

## Database Tables

- `billing_services`
- `billing_orders`
- `billing_invoices`
- `billing_transactions`
- `billing_coupons`
- `billing_config`
- `billing_paypal_webhook_events`
- `billing_paypal_errors`

## Agent Interaction

- indirect through provisioning and server lifecycle

## User Workflow

- shop/service purchase
- invoice/payment flow
- renewals
- account/service status

## Admin Workflow

- configure payment gateways
- manage coupons and pricing
- inspect invoices/transactions
- manage provisioning behavior

## Security Concerns

- payment keys and webhook secrets
- user identity and billing data
- service suspension/expiration behavior

## Known Issues

- large and complex module surface
- requires strong testing around provisioning lifecycle

## Missing Functionality

- cleaner linkage from billing events to server state
- clearer expiration/suspension docs and UX

## Suggested Future Improvements

- simplify provisioning audit
- add clearer service lifecycle feedback
- link billing more directly to support and server monitor

## Recommendation

- Keep / Rewrite
