# Agent Hardware Inventory Protocol, version 1

The panel asks an agent what hardware it can see. The answer is a read-only description of the machine.
This is the first step of GSE Resource Intelligence. Nothing here changes where games are placed yet.

## 1. Method

`agent_hardware_inventory(enc_check)` over the normal agent XML-RPC channel.
`enc_check` is the same encrypted "Encryption checking OK" value that `agent_resource_snapshot` uses.
Like every agent reply, each value in the answer is base64 encoded.

| Key | Meaning |
| --- | --- |
| `success` | `1` or `0` |
| `status` | `ok`, `error` or `bad_encryption` |
| `message` | Plain text. On `error` it holds the reason (the Windows agent trims it to 160 characters). |
| `agent_version`, `protocol_version` | Same as `agent_resource_snapshot` |
| `inventory_schema` | Schema version of `inventory_json`. Currently `1`. |
| `inventory_json` | The inventory as JSON (section 3) |

An agent that does not have this method answers with an XML-RPC fault. The panel records that as
`unsupported`. It is not an error, and it is not written to the panel log.
(`RemoteLibrary::agent_hardware_inventory()` calls `postRequest` for this reason. `sendRequest` would log the
fault and turn it into "agent unreachable".)

## 2. When the panel asks

`agent_refresh_snapshot()` (modules/server/agent_helpers.php) calls `agent_collect_inventory_if_due()` after a
snapshot succeeded, so the agent is known to be up. The logic is in includes/agent_inventory.php.

| Last recorded state | Ask again after |
| --- | --- |
| `ok` | 6 hours |
| `unsupported` | 1 hour (an agent that is updated later is noticed soon) |
| `error` or any unknown state | 15 minutes |
| nothing recorded, or the clock moved back | now |

A problem in this code is caught and never changes what the snapshot refresh returns, including database
exceptions in strict mode.

## 3. Inventory, schema 1

Unknown values are `null`. They are never `0`. Sizes say their unit in the key name.

| Key | Linux | Windows |
| --- | --- | --- |
| `schema_version` | `1` | `1` |
| `platform` | `linux` | `windows` |
| `kernel` | kernel release | OS version, for example `10.0.20348` |
| `os_name` | not set | OS caption |
| `architecture` | `uname -m` | `x86_64`, `x86`, `arm64`, or the raw text in lower case |
| `cpu.model` | `/proc/cpuinfo` | `Win32_Processor.Name`, padding trimmed |
| `cpu.logical_cpus_allowed` | CPUs this process may use (cpuset) | logical processors |
| `cpu.cpus_allowed_list` | for example `0-19` | `null` |
| `cpu.sockets`, `cpu.physical_cores` | not set | from `Win32_Processor` |
| `cpu.quota` | `source` (`cgroup_v2`, `cgroup_v1` or `none`), `quota_us`, `period_us`, `unlimited` | `source` is `none` |
| `cpu.hypervisor_flag` | `hypervisor` CPU flag | `HypervisorPresent` |
| `memory.total_mib` | `MemTotal` | `TotalVisibleMemorySize` |
| `memory.available_mib` | `MemAvailable` | `ComputerInfo.AvailablePhysicalMemory` (includes standby memory). If that fails, free memory with a warning that it understates availability. |
| `memory.cgroup_limit` | `source`, `limit_bytes` (`null` = no limit) | `source` is `none` |
| `storage.game_volume` | `path`, `filesystem`, `mount`, `total_bytes`, `available_bytes`, or `error` | same keys |
| `storage.block_devices[]` | `name`, `rotational`, `size_bytes` | adds `media_type`, `bus_type` |
| `warnings[]` | text | text |

`rotational` is `true`, `false` or `null`. Inside a virtual machine it is whatever the hypervisor reports and may
not describe the real media. Windows reports `null` for the media type `Unspecified`. It is descriptive only.

`storage.game_volume` is currently the volume of the agent's run directory. It should come from the game
configuration later (see docs/TODO.md).

## 4. What the panel stores

Both tables are InnoDB and are created on demand (includes/agent_inventory.php).

- `agent_hardware_inventory`: `id`, `remote_server_id`, `collected_at`, `schema_version`, `fingerprint`,
  `inventory_json`. A row is added only when the fingerprint differs from the agent's latest row.
- `agent_inventory_state`: `remote_server_id`, `checked_at`, `state`, `message`. Drives section 2.

The fingerprint is a SHA-256 over the stable fields: platform, architecture, CPU model, logical CPU count, CPU quota,
total memory, memory limit, block devices and the game volume's total size. It leaves out available memory and free
disk space, which change all the time. Key order does not matter. The panel rejects any `schema_version` other than 1.

## 5. Safety

- Read-only. No benchmark runs, nothing is written, no game is touched.
- Linux reads `/proc`, `/sys` and cgroup files and runs `df` with fixed arguments and no shell.
- Windows runs one fixed PowerShell script made of CIM queries. Only the drive letter is filled in, after it matches
  `^[A-Za-z]:$`.
- A failed source adds a warning and a `null`. It does not fail the whole call.

## 6. Not included yet

Benchmarks, storage speed, per-game usage, parent hosts, and taking the game volume from the game configuration.
