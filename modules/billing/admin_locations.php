<?php
if (session_status() === PHP_SESSION_NONE) { session_name('gameserverpanel_web'); session_start(); }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/action_security.php';

function loc_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$db = mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null);
mysqli_set_charset($db, 'utf8mb4');
$p = isset($table_prefix) ? $table_prefix : '';
$csrfKey = 'billing_admin_locations_csrf';
$csrf = billing_action_csrf_token($csrfKey);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!billing_action_validate_csrf($_POST['csrf'] ?? '', $csrfKey)) {
        $msg = 'Invalid security token. Reload the page and try again.';
    } else {
        $act = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['location_id'] ?? 0);
        if ($act === 'add') {
            $kind = (($_POST['kind'] ?? '') === 'city') ? 'city' : 'region';
            $name = trim((string)($_POST['name'] ?? ''));
            $parent = (int)($_POST['parent_id'] ?? 0);
            if ($name === '') {
                $msg = 'Name is required.';
            } elseif ($kind === 'city' && $parent <= 0) {
                $msg = 'A city must belong to a region.';
            } else {
                $pv = $parent > 0 ? $parent : null;
                $s = $db->prepare('INSERT INTO ' . $p . 'billing_locations (parent_id, kind, name, enabled) VALUES (?, ?, ?, 1)');
                $s->bind_param('iss', $pv, $kind, $name);
                $s->execute();
                $s->close();
                $msg = 'Location added.';
            }
        } elseif ($act === 'rename' && $id > 0) {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $msg = 'Name is required.';
            } else {
                $s = $db->prepare('UPDATE ' . $p . 'billing_locations SET name = ? WHERE location_id = ?');
                $s->bind_param('si', $name, $id);
                $s->execute();
                $s->close();
                $msg = 'Location renamed.';
            }
        } elseif ($act === 'toggle' && $id > 0) {
            $s = $db->prepare('UPDATE ' . $p . 'billing_locations SET enabled = 1 - enabled WHERE location_id = ?');
            $s->bind_param('i', $id);
            $s->execute();
            $s->close();
            $msg = 'Location updated.';
        } elseif ($act === 'delete' && $id > 0) {
            $children = 0; $agents = 0;
            $s = $db->prepare('SELECT COUNT(*) FROM ' . $p . 'billing_locations WHERE parent_id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->bind_result($children); $s->fetch(); $s->close();
            $s = $db->prepare('SELECT COUNT(*) FROM ' . $p . 'remote_servers WHERE location_id = ?');
            $s->bind_param('i', $id); $s->execute(); $s->bind_result($agents); $s->fetch(); $s->close();
            if ($children > 0) {
                $msg = 'Delete refused: this location still has sub-locations.';
            } elseif ($agents > 0) {
                $msg = 'Delete refused: a machine is still assigned to this city.';
            } else {
                $s = $db->prepare('DELETE FROM ' . $p . 'billing_locations WHERE location_id = ?');
                $s->bind_param('i', $id);
                $s->execute();
                $s->close();
                $msg = 'Location deleted.';
            }
        }
    }
    $csrf = billing_action_csrf_token($csrfKey);
}

$rows = [];
$res = $db->query('SELECT location_id, parent_id, kind, name, enabled FROM ' . $p . 'billing_locations ORDER BY kind, name');
while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
$regions = array_values(array_filter($rows, function ($r) { return $r['kind'] === 'region'; }));
$children = [];
foreach ($rows as $r) { if ($r['parent_id'] !== null) { $children[(int)$r['parent_id']][] = $r; } }
mysqli_close($db);
?>
<h2>Locations</h2>
<?php if ($msg !== '') { echo '<p><b>' . loc_h($msg) . '</b></p>'; } ?>
<table>
<tr><th>Location</th><th>Kind</th><th>Enabled</th><th>Actions</th></tr>
<?php foreach ($regions as $reg): ?>
<tr><td><b><?php echo loc_h($reg['name']); ?></b></td><td>region</td><td><?php echo $reg['enabled'] ? 'yes' : 'no'; ?></td>
<td><?php $row = $reg; include __DIR__ . '/includes/location_row_actions.php'; ?></td></tr>
<?php foreach (($children[(int)$reg['location_id']] ?? []) as $city): ?>
<tr><td>&nbsp;&nbsp;&nbsp;<?php echo loc_h($city['name']); ?></td><td>city</td><td><?php echo $city['enabled'] ? 'yes' : 'no'; ?></td>
<td><?php $row = $city; include __DIR__ . '/includes/location_row_actions.php'; ?></td></tr>
<?php endforeach; ?>
<?php endforeach; ?>
</table>
<h3>Add location</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo loc_h($csrf); ?>">
<input type="hidden" name="action" value="add">
<select name="kind"><option value="region">Region</option><option value="city">City</option></select>
<input type="text" name="name" placeholder="Name" required>
<select name="parent_id"><option value="0">(no parent)</option>
<?php foreach ($regions as $reg): ?><option value="<?php echo (int)$reg['location_id']; ?>"><?php echo loc_h($reg['name']); ?></option><?php endforeach; ?>
</select>
<button type="submit">Add</button>
</form>
