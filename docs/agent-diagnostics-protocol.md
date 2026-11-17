# Agent Diagnostics Protocol, version 1

This is the one contract between the panel and the agents (gse-agent-linux, gse-agent-windows)
for network and game diagnostics. The panel side is implemented in
`includes/diagnostics/contract.php` and `RemoteLibrary::diag_capabilities()` / `diag_run()`
(`includes/lib_remote.php`). Each agent implements the two RPC methods below.

Design rule: **the agent only performs the network exchange and reports raw facts.** It does not
decide who may test what (the panel does), and it does not parse game protocols (the panel does).
The agent still enforces the connection-time address rules in section 4, because the panel and
the agent can resolve names differently.

## 1. Transport

Same as every other agent call (see `includes/lib_remote.php`):

- XML-RPC over HTTP to `/RPC2`.
- Every parameter is `base64(XXTEA-encrypt(base64(value)))`, exactly like `encryptParam()` today.
- The last parameter is always the encryption check, `"Encryption checking OK"`, encrypted the same
  way. A wrong key: return the usual bad-encryption reply (see "Replies").
- Replies are a struct of base64-encoded strings, built like `component_update_response(...)`
  in `agent_core.pl`. The panel decodes them with `decode_component_response()`.

## 2. Methods

### 2.1 `diag_capabilities(enc_check)`

The capability probe. No other parameters. Cheap and read-only. It must never start a job.

Reply fields (all strings; JSON values are JSON text):

| field | value |
| --- | --- |
| `success` | `1` |
| `status` | `ok` (or `bad_encryption` with `success` = `0`) |
| `protocol_version` | `1` (integer as text) |
| `agent_version` | the agent's own version, e.g. `0.6.0` |
| `os` | `linux` or `windows` |
| `tools` | JSON array of tool names this agent supports, from: `tcp_connect`, `udp_exchange`, `traceroute`, `http_download` |
| `limits` | JSON object of this agent's hard limits (section 5). Panel limits are never above these. |

An agent may support a subset of the tools. The panel shows "needs update" for a missing tool.

**An agent that does not have the method** answers with the usual XML-RPC fault for an unknown
method. The panel treats a fault on `diag_capabilities` from an agent that passed `quick_chk`
as "diagnostics not supported". Do not special-case this on the agent.

### 2.2 `diag_run(tool, params_json, enc_check)`

- `tool`: one of the tool names above (encrypted string).
- `params_json`: a JSON object (encrypted string). Maximum 4096 bytes. Unknown keys are ignored.
- Runs the tool synchronously and returns when finished or when the runtime limit is hit.

Reply fields:

| field | value |
| --- | --- |
| `success` | `1` when the tool ran to a result (even a negative one, like "refused"), `0` for an error |
| `status` | `ok` or an error code (section 6) |
| `message` | short human text, English, no secrets |
| `tool` | echo of the tool name |
| `protocol_version` | `1` |
| `duration_ms` | whole job time, integer, from a monotonic clock |
| `result` | JSON text, shape per tool (section 3). Empty string when `success` is `0`. |
| `truncated` | `1` if output was cut at a limit, else `0` |

`result` JSON is at most 65536 bytes. A negative network result (refused, timeout, no reply) is
`success=1` with the outcome in `result`; `success=0` is only for rejected or failed jobs.

## 3. Tools

All `host_ip` values are IP literals (IPv4 or IPv6). **The panel resolves names and sends the IP.**
The agent must reject a `host_ip` that is not an IP literal with `bad_params`. This is what stops
DNS rebinding between check and use.

`allow_private` (bool, default `false`): the panel sets it `true` only when the owner policy allows
a private-range target (section 4). It never unlocks the "always blocked" ranges.

### 3.1 `tcp_connect`

Parameters:

| name | type | rules |
| --- | --- | --- |
| `host_ip` | string | IP literal |
| `port` | int | 1 to 65535 |
| `timeout_ms` | int | 100 to 10000 |
| `allow_private` | bool | |

Result:

```json
{
  "outcome": "connected",
  "connect_ms": 12.4,
  "remote_ip": "203.0.113.5",
  "remote_port": 27015,
  "detail": ""
}
```

`outcome` is exactly one of:

- `connected`: handshake completed. Close the socket right away. Send no data.
- `refused`: the host answered with RST (ECONNREFUSED / WSAECONNREFUSED).
- `timeout`: no answer within `timeout_ms`.
- `unreachable`: no route / network unreachable / host unreachable (ENETUNREACH, EHOSTUNREACH).
- `error`: anything else; put the OS error text in `detail` (max 200 chars).

`connect_ms` is measured with a monotonic clock around the connect call only, one decimal.
It is `null` unless `outcome` is `connected` or `refused`.

### 3.2 `udp_exchange`

A dumb datagram exchange. The panel builds the game request and parses the replies.
This is how Server Query (A2S) and the UDP Port Checker work; the agent knows nothing about A2S.

Parameters:

| name | type | rules |
| --- | --- | --- |
| `host_ip` | string | IP literal |
| `port` | int | 1 to 65535 |
| `payload_b64` | string | base64 of the datagram to send, 1 to 1400 bytes decoded. Never empty. |
| `timeout_ms` | int | 100 to 10000: wait for the first reply |
| `idle_ms` | int | 50 to 2000: after the first reply, stop when no new datagram arrived for this long (collects split packets) |
| `max_datagrams` | int | 1 to 16 |
| `max_bytes` | int | 64 to 32768: total received bytes cap, across all datagrams |
| `allow_private` | bool | |

Behaviour:

1. Create a UDP socket, `connect()` it to `host_ip:port` (so only that peer's datagrams are read
   and ICMP errors are reported), send the payload once.
2. Read datagrams until `timeout_ms` (no reply yet), or `idle_ms` of silence after a reply,
   or `max_datagrams`, or `max_bytes`. A datagram larger than the remaining byte budget is cut
   and `truncated` is set.
3. Do not retry the send. The panel decides about retries.

Result:

```json
{
  "outcome": "replied",
  "datagrams": [
    {"t_ms": 31.2, "data_b64": "/////0k..."}
  ],
  "bytes": 214,
  "remote_ip": "203.0.113.5",
  "detail": ""
}
```

`outcome` is exactly one of:

- `replied`: at least one datagram came back.
- `no_reply`: nothing came back in `timeout_ms`. **This does not mean the port is closed.**
  Many game servers drop unknown packets, and a firewall can drop silently.
- `icmp_unreachable`: the OS reported ICMP port/host unreachable on the connected socket
  (Linux: ECONNREFUSED on send/recv; Windows: WSAECONNRESET on recv). Stronger evidence that
  nothing listens, still not proof.
- `error`: anything else; OS error text in `detail`.

`t_ms` is the time since the send, monotonic clock, one decimal. `datagrams[0].t_ms` is the
query latency the panel shows. Datagrams stay in arrival order.

### 3.3 `traceroute`

The agent runs the system tool with an **argument array** (no shell, no string concatenation):

- Linux: `traceroute -n -m <max_hops> -w <seconds> -q <probes> <host_ip>` (or `tracepath`/`mtr
  --report` if traceroute is missing; the agent picks, and reports `method`).
- Windows: `tracert -d -h <max_hops> -w <ms> <host_ip>`. (The Windows agent runs Cygwin; either
  the Windows `tracert.exe` or Cygwin `traceroute` is fine.)

Parameters:

| name | type | rules |
| --- | --- | --- |
| `host_ip` | string | IP literal |
| `max_hops` | int | 1 to 30 |
| `per_hop_timeout_ms` | int | 200 to 5000 |
| `probes_per_hop` | int | 1 to 3 (Windows tracert always sends 3; the agent keeps all 3 and the panel accepts 1 to 3) |
| `total_timeout_s` | int | 5 to 60: kill the child process at this time |
| `resolve_names` | bool | default `false`. When `true` the agent may reverse-look-up hop addresses itself (not by passing hostnames to the tool, and not through a shell). |
| `allow_private` | bool | applies to `host_ip` only; hop addresses are whatever the network returns |

Result:

```json
{
  "outcome": "completed",
  "method": "traceroute",
  "reached": true,
  "hops": [
    {"hop": 1, "probes": [{"ip": "10.0.0.1", "host": null, "rtt_ms": 0.4, "timeout": false}]},
    {"hop": 2, "probes": [{"ip": null, "host": null, "rtt_ms": null, "timeout": true}]}
  ]
}
```

`outcome`: `completed` (destination reached), `max_hops` (limit reached first), `timeout`
(killed at `total_timeout_s`; return the hops collected so far), `error` (tool missing or failed;
`detail` field with text, max 200 chars). Hops are numbered from 1, in order, at most `max_hops`.
Never include the raw tool output in `result`; only parsed fields. The agent must parse locale
independently (use the numeric columns; set `LC_ALL=C` in the child environment on Linux).

### 3.4 `http_download`

Download speed test to an endpoint the panel admin configured. The agent never decides the URL.

Parameters:

| name | type | rules |
| --- | --- | --- |
| `url` | string | `http://` or `https://`, no userinfo, host is a name or an IP literal, path and query only from the panel. Max 512 chars. |
| `size_bytes` | int | requested test size, for the report only (the panel puts it in the URL it built) |
| `max_bytes` | int | hard stop: abort after this many bytes received, 1048576 to 268435456 |
| `timeout_s` | int | 3 to 60: total job time, connect included |
| `connections` | int | 1 to 4 parallel connections to the same URL |
| `allow_private` | bool | always `false` from the panel for this tool |

Behaviour (all mandatory):

1. Resolve the host yourself. Reject if **any** resolved address is in a blocked range
   (section 4), error `target_blocked`. Connect to a validated address literal, not to the name.
   For HTTPS keep the original host name for SNI and certificate validation, and verify the
   certificate (no insecure mode).
2. **Do not follow redirects.** Any 3xx is an error `redirect_blocked`. (This is the redirect-SSRF
   rule: a redirect target would otherwise skip the address check.)
3. Only a `200` (or `206`) status is a success. Anything else is `http_error` with `http_status`.
4. Stream the body and discard it. Never write it to disk. Count bytes.
5. Time with a monotonic clock, from the moment the request is written to the last byte read
   (`elapsed_ms`). Connect and TLS time are reported separately as `connect_ms`.
6. Stop at `max_bytes` or `timeout_s`. Reaching `max_bytes` is a normal finish.

Result:

```json
{
  "outcome": "ok",
  "bytes": 26214400,
  "elapsed_ms": 2310.6,
  "connect_ms": 41.2,
  "mbps": 90.8,
  "http_status": 200,
  "remote_ip": "203.0.113.20",
  "connections": 2
}
```

`mbps` = `bytes * 8 / 1000000 / (elapsed_ms / 1000)`, decimal megabits (1 Mbps = 1,000,000 bit/s).
`outcome`: `ok`, `timeout` (partial; `bytes`/`elapsed_ms`/`mbps` still reported), `http_error`,
`redirect_blocked`, `tls_error`, `dns_error`, `connect_error`, `error`. With several connections,
`bytes` is the sum and `elapsed_ms` is the wall time.

## 4. Address rules (enforced by the agent at connect time)

The agent must check every address it is about to connect to or send to. That means `host_ip`,
every resolved address for `http_download`, and nothing else (hop addresses are not connected to).
Check the address that is actually passed to `connect()`/`sendto()`; do not check an earlier
string.

Always blocked (error `target_blocked`, `allow_private` does not matter):

- IPv4: `0.0.0.0/8`, `127.0.0.0/8`, `169.254.0.0/16`, `224.0.0.0/4`, `240.0.0.0/4`
  (includes `255.255.255.255`)
- IPv6: `::`, `::1`, `fe80::/10`, `ff00::/8`
- IPv4-mapped IPv6 (`::ffff:a.b.c.d`) and NAT64 `64:ff9b::/96`: unwrap the IPv4 part and check it
  with the IPv4 rules.
- Documentation and benchmark ranges: `192.0.0.0/24`, `192.0.2.0/24`, `198.18.0.0/15`,
  `198.51.100.0/24`, `203.0.113.0/24`, `2001:db8::/32`

Blocked unless `allow_private` is `true`:

- `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `100.64.0.0/10`, `fc00::/7`

The panel keeps the same table in `includes/diagnostics/target_policy.php`
(`diag_ip_class()`); keep the two in sync.

## 5. Limits

The panel sends values inside these ranges. The agent **re-checks** every range and rejects values
outside with `bad_params` (do not silently clamp).

Agent hard limits (reported in `diag_capabilities.limits`):

```json
{
  "max_runtime_s": 60,
  "max_concurrent_jobs": 2,
  "max_result_bytes": 65536,
  "max_udp_payload": 1400,
  "max_udp_bytes": 32768,
  "max_download_bytes": 268435456
}
```

- More than `max_concurrent_jobs` running at once: `busy`.
- A job that runs past `max_runtime_s` is stopped: `timeout` (with the partial result where one
  exists).
- Child processes (traceroute) are killed on timeout, and are never left running.
- No tool may write files, read files, or accept a command line from the panel.

Panel limits (the panel enforces these before calling): see `includes/diagnostics/limits.php`.
Default: 12 runs per user per 5 minutes, 30 runs per agent per 5 minutes, 3 running jobs per
agent, 1 running job per user.

## 6. Error codes (`status` when `success` = 0)

| code | meaning |
| --- | --- |
| `bad_encryption` | the encryption check failed |
| `bad_params` | missing, wrong type, out of range, not an IP literal, oversized JSON |
| `unsupported_tool` | tool name not in this agent's `tools` |
| `target_blocked` | address rule (section 4) |
| `busy` | concurrent job limit |
| `timeout` | runtime limit hit and no usable result |
| `tool_missing` | the system tool (traceroute/tracert) is not installed |
| `redirect_blocked` | only for `http_download`, when reported as an error instead of a result |
| `internal_error` | agent bug; message has a short text, details go to the agent log |

## 7. Panel behaviour

- Before a run the panel calls `diag_capabilities` (cached for 5 minutes per agent in the session).
  - XML-RPC fault, with `quick_chk` OK: **unsupported**. The tool shows
    "This agent needs to be updated to version X to run this test", X from
    `DIAG_MIN_AGENT_VERSION` in `includes/diagnostics/contract.php` for the agent's OS.
  - `protocol_version` lower than the panel's minimum or tool not listed: same message.
  - Agent offline: "agent offline" message, not the update message.
- The panel validates target, ports, timeouts and protocol, applies the target policy, rate limits
  and concurrency caps, resolves names, and sends IP literals only.
- Every run writes one audit row (`diag_audit` table): user, agent, tool, target, port, outcome,
  duration, client IP. Denied and rate-limited attempts are written too.

## 8. What each agent run must implement

1. `diag_capabilities` and `diag_run` XML-RPC methods, registered like the other methods,
   decrypting parameters with the existing helpers.
2. Tools `tcp_connect`, `udp_exchange`, `traceroute`, `http_download` as specified above.
3. The address rules in section 4, applied at connect time, plus the limits in section 5.
4. Concurrency counter (max 2 jobs) and a monotonic clock for all timings.
5. Bump `AGENT_VERSION` to the version the panel lists in `DIAG_MIN_AGENT_VERSION` for that OS
   (panel placeholder values: linux `0.6.0`, windows `1.6.0`). If the release version differs,
   update that constant in the panel to match.
6. No new method for reading logs: the File Manager Log Analyzer uses the existing
   `get_file_part` (seekable, about 3.4 MB per call). Nothing to do for logs.
7. Tests on the agent side: refused port, closed UDP port (ICMP), silent UDP port, blocked
   ranges, redirect refusal, oversized parameters, concurrency `busy`, traceroute timeout kill.

## 9. Not covered in version 1

- Ping through this contract. The panel still uses the existing `exec` path for ping, with the
  panel-resolved IP literal. A `ping` tool can be added as protocol version 1 minor addition later
  (new tool name in `tools`; no panel change needed except the Network Tools handler).
- Asynchronous jobs with polling. Version 1 is synchronous; the longest job is 60 seconds.
- Authenticated or custom-header HTTP tests.
