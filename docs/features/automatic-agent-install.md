# Automatic agent installation

Status: active; selected by owner after Resource Intelligence testing releases. Isolated checkout /home/dev/projects/gse-panel-agent-deploy, feature/panel-ssh-agent-install, baseline 357f25ae.

## Scope
Admin-driven SSH/SFTP deployment using existing installer and enrollment, safe credential handling, verified host keys, background job, retries and resource/port integration. Linux pilot Washington DC VM131. No production changes or public SSH.

## Checkpoint
2026-11-28 Claude Sonnet medium command-20261128192714-05652f3c completed exit 0. Fifteen files changed; reported PHP syntax and fake SSH/DB tests passed. No real install, browser or migration validation; incorrect installer arguments guessed, no ProxyJump, checksum asset availability unknown, migration 0017 collides with resource work. No commit or deployment. See isolated checkout docs/AGENT_AUTO_INSTALL.md and docs/codex-history/2026/2026-11-28-1200-agent-auto-install-ssh.md. Do not edit feature until selected. Next: correct installer flags against real script, renumber migration after resource merge, validate real private connectivity and test. MAC supplied with ambiguous VM number; no public IP change authorized by this checkpoint.

## 2026-11-28 resumption
Owner selected the next feature. Resource Intelligence testing releases published; production rollout not confirmed. VM131 game-node3 is running on the private network; VM130 Fileserver remains untouched. Previous isolated changes remain uncommitted. Next: verify real installer interface and artifact integrity, migration 0019, secure private SSH reachability, isolated panel enrollment and actual installation. Public IP/virtual MAC assignment remains unverified.
