# Task

Version 2.7.1: keep one rollback copy, and show release tags on the install list and current version line.

## Change

- The update keeps one backup. The old one is deleted only after the new one is copied and checked.
- One button: "Roll back to <version> (<Tag>)", with the real backup path under it, or "No rollback copy yet."
- Removed the manual "Create Backup" button and the Apache restore checkbox (they made extra or replacing backups).
- Install dropdown is sorted by tag (Stable, Testing, Testing Latest). Current version line reads "<Tag> <version>", or "Unknown tag <version>".
- Tests: modules/update/tests/rollback_retention_test.php, release_source_test.php.
