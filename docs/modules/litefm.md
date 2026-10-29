# Lite File Manager

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

In-panel file manager and file editor for customer server homes.

## Current Status

- Functional
- Important core tool

## Dependencies

- file access rights
- remote file read/write/list helpers
- editor assets

## Database Tables

- None declared in module metadata

## Agent Interaction

- remote file listings
- read/write operations

Current listing flow uses `remote_dirlistfm`, where agent filenames are treated as exact values and preserved by the Panel.

## User Workflow

- browse files
- upload/download
- edit common configs
- use the green `Up one Level` button for parent-directory navigation on mobile and desktop

Special-character names are supported end-to-end in browse/navigation/actions, including:

- `@3739421199`
- `@CF`
- `My Mod Folder`
- `[Server] Configs`
- `profile.backup`
- `mod+keys`

## Admin Workflow

- set file management permissions
- configure file manager behavior

## Security Concerns

- path traversal
- protected control files
- shared secret exposure

Path safety model:

- Preserve exact filename display and action values (no destructive filename stripping).
- Escape output for HTML rendering only.
- Encode filename tokens in links using `rawurlencode`; decode with `rawurldecode`.
- Reject invalid path components (`..`, path separators, NUL byte).
- Normalize relative paths and verify final resolved paths remain inside the assigned game home.
- Distinguish user-facing errors:
	- `Server files have not been installed yet.` (home missing)
	- `Directory not found: <relative path>` (missing subdirectory)
	- `Directory exists but cannot be opened. Check permissions.` (listing/access failure)
	- `Path decode failed` (invalid filename token)

## Known Issues

- very large uploads/downloads still depend on chunking/browser limits

## Missing Functionality

- stronger backup-before-save
- better large file handling
- clearer read-only protected path UX

## Suggested Future Improvements

- make this the preferred editor and file browsing path

## Recommendation

- Keep / Improve
