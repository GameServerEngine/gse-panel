<?php
require_once __DIR__ . '/../includes/status_snapshot.php';

function status_check($cond, $msg) { if (!$cond) { throw new RuntimeException($msg); } }

$now = 1000000;
$snap = array(
    'generated' => $now - 30,
    'backup_at' => $now - 5 * 3600 - 120,
    'agents' => array(array('id' => 1, 'name' => 'Core', 'online' => true), array('id' => 2, 'name' => 'Edge <b>', 'online' => false)),
    'servers' => array(
        array('id' => 12, 'game' => 'Arma 3', 'status' => 'stopped'),
        array('id' => 3, 'game' => 'Rust', 'status' => 'running'),
        array('id' => 7, 'game' => '', 'status' => 'updating'),
    ),
);
$html = billing_status_render($snap, $now);
status_check(strpos($html, 'Control Panel') < strpos($html, 'Core'), 'control panel line comes first');
status_check(strpos($html, 'Core') < strpos($html, 'Last backup'), 'agents come before the backup line');
status_check(strpos($html, 'Last backup') < strpos($html, 'gsw-status-sep'), 'separator follows the backup line');
status_check(strpos($html, '#3 Rust') < strpos($html, '#7') && strpos($html, '#7') < strpos($html, '#12 Arma 3'), 'servers sorted by id');
status_check(strpos($html, '5 hours ago') !== false, 'backup shown in hours');
status_check(strpos($html, 'Edge &lt;b&gt;') !== false && strpos($html, 'Edge <b>') === false, 'agent name escaped');
status_check(strpos($html, 'st-good">Running') !== false, 'running is green');
status_check(strpos($html, 'st-bad">Stopped') !== false, 'stopped is red');
status_check(strpos($html, 'st-warn">Updating') !== false, 'updating is amber');
status_check(strpos($html, 'st-bad">Offline') !== false, 'offline agent is red');

// A stale snapshot must not claim anything is up.
$stale = $snap; $stale['generated'] = $now - 3600;
$staleHtml = billing_status_render($stale, $now);
status_check(strpos($staleHtml, 'Core</span><b class="gsw-st st-good') === false, 'stale snapshot shows no agent as up');
status_check(strpos($staleHtml, 'Control Panel</span><b class="gsw-st st-good">Online') !== false, 'control panel is up whenever the page renders');
status_check(strpos($staleHtml, 'status is behind') !== false, 'stale snapshot says it is behind');

status_check(strpos(billing_status_render(null, $now), 'Control Panel</span><b class="gsw-st st-good">Online') !== false, 'no snapshot still shows the panel up');
status_check(strpos(billing_status_render(null, $now), 'being gathered') !== false, 'no snapshot message');
$noBackup = $snap; unset($noBackup['backup_at']);
status_check(strpos(billing_status_render($noBackup, $now), 'Not reported') !== false, 'missing backup reported honestly');
status_check(billing_status_ago($now - 3 * 3600, $now) === '3 hours ago', 'hours text');
status_check(billing_status_ago($now - 3600, $now) === '1 hour ago', 'singular hour');

echo "StatusSnapshotTest passed\n";
