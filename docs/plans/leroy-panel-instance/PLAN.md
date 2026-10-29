# Leroy for Panels (customer and admin)

Status: decided, design only. Decisions dated 2026-10-20.

## Goal
Customers get an AI assistant inside the panel that edits and configures their own game servers.
Admins get a separate, more capable assistant for changing the panel and the agents.

## Naming and layout
- Front end: the panel chat widget, built from `cyber-support-platform`, shown as the lower-right
  icon in the panel and on the storefront.
- Back end: a separate instance of Leroy for panels, named Leroy, running as a service from a
  subfolder of the panel. It runs on the same machine as the chat widget.
- Communication: the chat widget talks to Panel Leroy over MCP on that machine.
- Our own Leroy (dev servers and dev CLIs) is not changed.

## Access model
- Customers: Panel Leroy receives a token from the panel session. The token is bound to one
  customer and to the servers that customer owns. Every tool call checks the token and the
  server ownership.
- Customer tools: the typed actions from `docs/plans/ai-assistant/PLAN.md`, limited to the
  customer's own game servers and game folders.
- Admin scope: a separate admin token. Only admins can reach CLI-based tools, and those tools
  are only for changing the panel or the agents.
- Dave/Leroy split for our own work stays as it is: ChatGPT (Dave) for planning, Claude CLI
  (Leroy) for development.

## Settings
- The AI configuration lives in the panel settings, not in files on disk:
  - provider (Claude or OpenAI, switchable)
  - model names and token budgets per plan
  - MCP endpoint address and the shared secret used by the chat widget
  - kill switch (global and per-customer)
- The MCP secret is stored in the panel's settings table and is never shown again after save.

## Open items
- Whether the chat widget stores conversations in the panel's MySQL database (decision pending).
- The first set of customer tools to ship, after the security review.
