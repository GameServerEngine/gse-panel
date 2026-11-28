# Active work status

Updated: 2026-11-28 UTC. See project TODO and permanent engineering history for additional detail.

| Feature | Status | Checkout | Next action |
|---|---|---|---|
| [Resource Intelligence and placement](features/resource-intelligence.md) | Selected; real MySQL flow, concurrency and scoped replay passed (Leroy); migration crash recovery and Windows resource_host implemented and fixture-tested, NOT yet run on MySQL/Windows | working (panel), working (gse-agent-windows) | Leroy: run extended MySQL replay test. Owner: name a dev Windows VM for status_sample; then calibration, browser and provisioning checks |
| [Automatic agent deployment](features/automatic-agent-install.md) | Paused by owner; unit tests passed, integration outstanding | feature/panel-ssh-agent-install | Resume only after Resource Intelligence; fix real installer interface, migration collision, private network reachability |
