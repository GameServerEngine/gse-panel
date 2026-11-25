# Branching

| Branch | Purpose | Who merges into it |
|---|---|---|
| `stable` | Released code only. Every release is a tag on this branch (`vX.Y.Z`). | Only `testing`, after approval. |
| `testing` | Customer-facing pre-release channel. Each version that works well enough to build is tagged `testing-X.Y.Z`. Several people develop against it, so nobody edits it directly. | Only `working`, by fast-forward, when a version is ready. |
| `working` | Day-to-day development branch. All work and commits go here. | The people and tools doing the work. |

## Flow
1. Do the work on `working`. Each push to `working` gets an automatic `chore(release): working X.Y.Z` version bump from CI, so fetch and merge before you push if a push is rejected.
2. Every change updates `docs/TODO.md` and, for anything customers will notice, `CHANGELOG.md`, in the same commit.
3. When a version works well enough to build, set `VERSION` and `includes/panel_version.php` to it, add the CHANGELOG entry, then fast-forward `testing` to `working` and tag `testing-X.Y.Z`.
4. After testing, and with approval, fast-forward `stable` to the same commit, tag `vX.Y.Z`, and make a release.

Never force-push `stable` or `testing`.
