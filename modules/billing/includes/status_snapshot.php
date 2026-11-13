<?php
/**
 * Public service status, served from one cached snapshot.
 *
 * Asking every agent about every game server on each page view cannot scale to hundreds
 * of servers. Instead a refresher (tools/refresh_status_snapshot.php, run by cron about
 * once a minute) gathers the state once and stores it as a single JSON row. The support
 * page and status_feed.php only read that row: one indexed query, however many servers
 * there are. The page is current to within the refresh interval, and says so when the
 * snapshot is older than BILLING_STATUS_STALE_AFTER seconds.
 *
 * Snapshot shape:
 *   agents  => [ ['id' => int, 'name' => string, 'online' => bool], ... ]
 *   servers => [ ['id' => int, 'game' => string, 'status' => running|stopped|starting|updating|offline|unknown], ... ]
 *   backup_at => unix time of the newest backup, or null when none is reported
 */

const BILLING_STATUS_STALE_AFTER = 180;

if (!function_exists('billing_status_table')) {
    function billing_status_table(string $prefix): string
    {
        return '`' . str_replace('`', '', $prefix) . 'billing_status_snapshot`';
    }
}

if (!function_exists('billing_status_ensure_table')) {
    function billing_status_ensure_table(mysqli $db, string $prefix): void
    {
        $db->query(
            'CREATE TABLE IF NOT EXISTS ' . billing_status_table($prefix) . ' (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                payload MEDIUMTEXT NOT NULL,
                updated_at INT UNSIGNED NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}

if (!function_exists('billing_status_store')) {
    /** Replaces the snapshot. One REPLACE, so readers never see a half-written row. */
    function billing_status_store(mysqli $db, string $prefix, array $payload, ?int $now = null): bool
    {
        billing_status_ensure_table($db, $prefix);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        $stmt = $db->prepare('REPLACE INTO ' . billing_status_table($prefix) . ' (id, payload, updated_at) VALUES (1, ?, ?)');
        $time = $now ?? time();
        $stmt->bind_param('si', $json, $time);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('billing_status_load')) {
    /** The stored snapshot with 'generated' (unix time) added, or null when none exists. */
    function billing_status_load(mysqli $db, string $prefix): ?array
    {
        try {
            $result = $db->query('SELECT payload, updated_at FROM ' . billing_status_table($prefix) . ' WHERE id = 1');
        } catch (Throwable $e) {
            return null;
        }
        $row = $result ? $result->fetch_assoc() : null;
        if (!$row) {
            return null;
        }
        $data = json_decode((string)$row['payload'], true);
        if (!is_array($data)) {
            return null;
        }
        $data['generated'] = (int)$row['updated_at'];
        return $data;
    }
}

if (!function_exists('billing_status_ago')) {
    /** "3 hours ago" style text. Backups are described in hours, so under two days stays in hours. */
    function billing_status_ago(int $then, int $now): string
    {
        $seconds = max(0, $now - $then);
        if ($seconds < 90) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $minutes = (int)round($seconds / 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        if ($seconds < 48 * 3600) {
            $hours = (int)floor($seconds / 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }
        $days = (int)floor($seconds / 86400);
        return $days . ' days ago';
    }
}

if (!function_exists('billing_status_terms')) {
    /** Status code => [label, css class]. Colour is never the only cue: the label is always printed. */
    function billing_status_terms(): array
    {
        return array(
            'online'   => array('Online', 'st-good'),
            'running'  => array('Running', 'st-good'),
            'stopped'  => array('Stopped', 'st-bad'),
            'starting' => array('Starting', 'st-warn'),
            'updating' => array('Updating', 'st-warn'),
            'offline'  => array('Offline', 'st-bad'),
            'unknown'  => array('Unknown', 'st-unknown'),
        );
    }
}

if (!function_exists('billing_status_line')) {
    function billing_status_line(string $left, string $code): string
    {
        $terms = billing_status_terms();
        list($label, $class) = $terms[$code] ?? $terms['unknown'];
        return '<div class="gsw-status-line"><span>' . htmlspecialchars($left, ENT_QUOTES, 'UTF-8')
            . '</span><b class="gsw-st ' . $class . '">' . $label . '</b></div>';
    }
}

if (!function_exists('billing_status_render')) {
    /** The contents of the status frame. */
    function billing_status_render(?array $snap, ?int $now = null): string
    {
        $now = $now ?? time();
        if ($snap === null) {
            return '<p class="gsw-status-note">Status is not available yet.</p>';
        }
        $generated = (int)($snap['generated'] ?? 0);
        $fresh = $generated > 0 && ($now - $generated) <= BILLING_STATUS_STALE_AFTER;

        // The control panel is "online" while its refresher keeps reporting in.
        $html = billing_status_line('Control Panel', $fresh ? 'online' : 'unknown');
        foreach ((array)($snap['agents'] ?? array()) as $agent) {
            $code = !$fresh ? 'unknown' : (!empty($agent['online']) ? 'online' : 'offline');
            $html .= billing_status_line((string)($agent['name'] ?? 'Agent'), $code);
        }

        $backup = isset($snap['backup_at']) ? (int)$snap['backup_at'] : 0;
        $html .= '<div class="gsw-status-line"><span>Last backup</span><b>'
            . ($backup > 0 ? billing_status_ago($backup, $now) : 'Not reported') . '</b></div>';
        $html .= '<hr class="gsw-status-sep">';

        $servers = (array)($snap['servers'] ?? array());
        usort($servers, function ($a, $b) {
            return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
        });
        if (!$servers) {
            $html .= '<p class="gsw-status-note">No game servers yet.</p>';
        }
        foreach ($servers as $server) {
            $code = !$fresh ? 'unknown' : (string)($server['status'] ?? 'unknown');
            $name = '#' . (int)($server['id'] ?? 0);
            if (!empty($server['game'])) {
                $name .= ' ' . $server['game'];
            }
            $html .= billing_status_line($name, $code);
        }

        $html .= '<p class="gsw-status-note">' . ($generated > 0
            ? 'Updated ' . billing_status_ago($generated, $now) . ($fresh ? '' : ' (status is behind)')
            : 'Not updated yet') . '</p>';
        return $html;
    }
}
