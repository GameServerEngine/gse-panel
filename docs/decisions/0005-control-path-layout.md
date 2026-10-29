# Decision 0005: Keep Managed Control Paths Outside Customer-Easy Edit Paths

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted

## Decision

Managed control files, manifests, scheduler state, and helper scripts should live in protected control locations rather than in customer-editable startup files where possible.

## Reasoning

- Customer-editable startup areas are too easy to tamper with.
- Managed state should not depend on files that customers can modify through FTP or file manager.
- Secure and auditable behavior is easier when control files are outside the customer content path.

## Alternatives Considered

- keep helper scripts in the game home
- keep runtime manifests next to the game executable

## Why Those Were Not Chosen

- Those options make it too easy for customers to alter managed execution.
- They complicate cleanup and lifecycle tracking.

