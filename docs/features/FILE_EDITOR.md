# File Editor

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Current State

File editing is split across LiteFM, FTP, edit-config helpers, and agent remote read/write methods.

Important references:

- `modules/litefm/`
- `modules/ftp/`
- `modules/editconfigfiles/`
- `includes/lib_remote.php`

## What It Should Provide

- safe browsing inside server home roots
- text editing with syntax highlighting
- backups before save
- restore last saved version
- read-only protected paths
- large-file warnings

## Main Risk

Customer access must never extend into agent control files, shared secrets, or other users' server homes.

