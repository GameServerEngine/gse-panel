# FTP

Workspace reference: [`GSP-WORKSPACE.md`](../../../GSP-WORKSPACE.md)

## Purpose

FTP administration and access control for server homes.

## Current Status

- Functional
- Legacy-heavy

## Dependencies

- FTP service / access credentials
- file management permissions

## Database Tables

- None declared in module metadata

## Agent Interaction

- FTP manager helpers through `lib_remote.php`

## User Workflow

- receive FTP credentials
- upload/download files through FTP client software

## Admin Workflow

- configure FTP access behavior
- manage permissions

## Security Concerns

- credentials
- root/path exposure
- embedded legacy net2ftp code

## Known Issues

- old embedded FTP UI stack
- security review needed

## Missing Functionality

- cleaner modern FTP/SFTP guidance
- clear boundary to protect agent/control files

## Suggested Future Improvements

- keep FTP as an advanced access path
- audit or replace the embedded UI layer

## Recommendation

- Keep / Rewrite

