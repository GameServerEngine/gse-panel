# Branching

| Branch | Purpose | Who merges into it |
|---|---|---|
| `stable` | Released code only. Every release is a tag on this branch (`vX.Y.Z`). | Only `testing`, after approval. |
| `testing` | Customer-facing pre-release channel. Release candidates are tagged `vX.Y.Z-rcN`. | Only `unstable`, when a candidate is ready. |
| `unstable` | Default development branch. Day-to-day work lands here. | Feature branches. |
| `feature/<name>` | One feature or fix, cut from `unstable`. | Its author, by merge request into `unstable`. |

## Flow
1. Cut `feature/<name>` from `unstable`.
2. Merge it into `unstable` when it passes the tests.
3. When `unstable` is ready for customers, fast-forward `testing` to it and tag `vX.Y.Z-rcN`.
4. After testing, fast-forward `stable` to the same commit, tag `vX.Y.Z`, and make a release.

Never force-push `stable` or `testing`.
