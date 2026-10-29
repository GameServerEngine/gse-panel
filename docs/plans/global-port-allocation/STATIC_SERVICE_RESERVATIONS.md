# Static Service Reservations

## Policy

Static services must be represented in the same allocation model as game blocks so game allocation cannot consume ports already needed by the host, Panel, agent, database, mail, VPN, or file-transfer services.

Safest default policy: reserve the containing 10-port block for any single well-known service port on an IP where that service is bound, unless a future product decision explicitly permits single-port reservations for low-numbered host-only services. This is conservative and makes collision checks simple. Single-port reservations may be acceptable later for localhost-only services, but that must be documented per deployment.

Reservations are per IP/host. Do not assume a service is bound on every public game IP unless deployment inspection proves it. Uncertain bindings below are marked `verify per deployment`.

| Service | Default port | Proposed reservation block or single-port reservation | Applies to which hosts/IPs | Confidence/source | Notes |
| --- | --- | --- | --- | --- | --- |
| SSH | 22 | Reserve block `20-29` by policy, or single-port if host-only exception is approved | Linux hosts/admin IPs where SSH is bound | local docs mention hard-coded SSH concerns; verify per deployment | Do not expose credentials or keys. |
| FTP control | 21 | Reserve block `20-29`; coordinate with SSH 22 | Agent hosts/public file-transfer IPs where FTP is enabled | repo docs and `remote_servers.ftp_port`; verify per deployment | Passive FTP range not found in Panel docs; must be collected from FTP daemon config. |
| HTTP | 80 | Reserve block `80-89` | Panel/website hosts and any public IP with Apache HTTP binding | deployment docs | Apache setup docs use HTTP to HTTPS redirect examples. |
| HTTPS | 443 | Reserve block `440-449` | Panel/website hosts and any public IP with Apache HTTPS binding | deployment docs | Required for production web/API endpoints where TLS is bound. |
| MySQL/MariaDB | 3306 | Reserve block `3300-3309` | Database hosts/IPs where MySQL listens | standard app dependency; verify per deployment | Prefer localhost/private binding; reserve if reachable on a host IP. |
| RDP | 3389 | Reserve block `3380-3389` | Windows hosts where RDP exists | user requirement; verify per deployment | Mark VPN-only when that is the deployment policy, but still reserve on the host where present. |
| Panel web/API | 80/443 unless customized | Reserve `80-89` and `440-449`, plus any custom vhost port | Panel/website hosts | deployment Apache docs; verify per deployment | Panel APIs are web routes on the Panel host, not game allocation ports. |
| Agent XML-RPC listener | configured `remote_servers.agent_port` | Reserve containing 10-port block for each configured agent listener | Agent management IP/host | `modules/server/module.php` stores `agent_port`; verify per deployment | Do not invent final port; read configured DB/deployment state during implementation. |
| Agent FTP service | configured `remote_servers.ftp_port` | Reserve containing 10-port block for each configured FTP listener | Agent FTP IP/host | `modules/server/module.php` stores `ftp_port`; verify per deployment | Also reserve FTP passive range once known. |
| SMTP | 25 | Reserve block `20-29` if local mail service is bound | Mail hosts/IPs | `docs/deployment/exim-SMTP.txt`; verify per deployment | Could overlap SSH/FTP block. |
| SMTP submission | 587 | Reserve block `580-589` if enabled | Mail hosts/IPs | deployment mail docs/settings; verify per deployment | Panel settings include `smtp_port`; outbound relay may not require inbound reservation. |
| SMTPS | 465 | Reserve block `460-469` if enabled | Mail hosts/IPs | deployment mail docs/settings; verify per deployment | Verify actual Exim/Postfix binding. |
| TeamSpeak ServerQuery | 10011 | Reserve block `10010-10019` where TeamSpeak management is hosted | Voice-server hosts/IPs | local TeamSpeak module config | This is service-specific, not normal game allocation. |
| TeamSpeak voice | 9987 | Reserve block `9980-9989` where TeamSpeak voice service is hosted | Voice-server hosts/IPs | TeamSpeak module/protocol docs; verify per deployment | Treat TeamSpeak profiles as service-specific until port-pool support is explicitly designed. |
| VPN OpenVPN | 1194 | Reserve block `1190-1199` if OpenVPN is used | VPN hosts/public IPs | user requirement category; verify per deployment | Not found as a final binding in repo docs. |
| VPN WireGuard | 51820 | Reserve block `51820-51829` if WireGuard is used | VPN hosts/public IPs | user requirement category; verify per deployment | Also check conflict with game XMLs that use high query offsets. |
| FTP passive range | deployment-specific | Reserve exact configured passive range or aligned 10-port blocks covering it | FTP hosts/public file-transfer IPs | not found locally; verify per deployment | Must be collected from Pure-FTPd/vsftpd/proftpd config before rollout. |

## Implementation Notes

- Store service reservations with `source=static_service` and a clear reason.
- Bindings must be attached to the specific `ip_id`/host scope where the service actually listens.
- Service reservations should participate in the same overlap checks as allocated game blocks.
- For low ports, the 10-port block policy is intentionally conservative; the product can later allow explicit single-port reservations for tightly scoped localhost-only services.
