-- Game servers get whole blocks of 100 ports per agent IP (2000-24000): the game port is the first
-- port of the block and every extra port of the game lies inside it (see includes/port_allocator.php).
-- A block that holds one of the reserved ports below is never given to a game server.

UPDATE `DB_PREFIXsettings` SET `value` = '100' WHERE `setting` = 'port_block_size';
INSERT INTO `DB_PREFIXsettings` (`setting`, `value`)
SELECT 'port_block_size', '100' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `DB_PREFIXsettings` WHERE `setting` = 'port_block_size');

-- Ports used by the machines that run agents: the agent itself, Windows (RDP and its services),
-- MySQL, and Proxmox (web, SPICE proxy, VNC consoles). Global: every agent IP.
INSERT IGNORE INTO `DB_PREFIXstatic_port_reservations`
  (`scope`, `ip_id`, `remote_server_id`, `service_name`, `reservation_type`, `port_start`, `port_end`, `protocol`, `source`, `status`, `notes`)
VALUES
  ('global', NULL, NULL, 'GSE agent', 'single_port', 12679, 12679, 'tcp', 'policy', 'active', 'Agent XML-RPC port'),
  ('global', NULL, NULL, 'Proxmox SPICE proxy', 'single_port', 3128, 3128, 'tcp', 'policy', 'active', NULL),
  ('global', NULL, NULL, 'MySQL', 'range', 3306, 3307, 'tcp', 'policy', 'active', 'Per-node MySQL VMs'),
  ('global', NULL, NULL, 'Windows RDP', 'single_port', 3389, 3389, 'tcp_udp', 'policy', 'active', NULL),
  ('global', NULL, NULL, 'Windows WS-Discovery', 'single_port', 3702, 3702, 'udp', 'policy', 'active', NULL),
  ('global', NULL, NULL, 'IPsec NAT traversal', 'single_port', 4500, 4500, 'udp', 'policy', 'active', NULL),
  ('global', NULL, NULL, 'mDNS, LLMNR, WSD', 'range', 5353, 5357, 'tcp_udp', 'policy', 'active', 'Windows name services'),
  ('global', NULL, NULL, 'Proxmox VNC consoles', 'range', 5900, 5999, 'tcp', 'policy', 'active', NULL),
  ('global', NULL, NULL, 'Proxmox web', 'single_port', 8006, 8006, 'tcp', 'policy', 'active', NULL);
