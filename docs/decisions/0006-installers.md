# Decision 0006: Installers Must Be Game-Capability Driven

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Status

Accepted

## Decision

Installer behavior should be driven by game XML capabilities and module metadata instead of ad hoc shell scripts or one-off module pages.

## Reasoning

- Different games need different install strategies.
- Some games are content-copy based.
- Some are SteamCMD based.
- Some need key copying, startup parameter edits, or profile transforms.
- The Panel needs a structured model to support all of them cleanly.

## Alternatives Considered

- per-game custom shell scripts only
- raw customer-provided installer commands

## Why Those Were Not Chosen

- They are harder to secure and harder to document.
- They do not scale cleanly across games or platforms.

