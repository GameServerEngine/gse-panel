<?php
if (session_status() === PHP_SESSION_NONE) { session_name('gameserverpanel_web'); session_start(); }
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/action_security.php';

function loc_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/* Renders the location tree: each node is one row, indented by depth. */
function loc_render_tree(array $children, int $parentId, int $depth, string $csrf)
{
    foreach (($children[$parentId] ?? array()) as $r) {
        echo '<tr><td style="padding-left:' . (12 + 22 * $depth) . 'px">' . loc_h($r['name']) . '</td>';
        echo '<td>' . loc_h($r['kind']) . '</td>';
        echo '<td>' . ($r['enabled'] ? 'yes' : 'no') . '</td><td>';
        $row = $r;
        include __DIR__ . '/includes/location_row_actions.php';
        echo '</td></tr>';
        loc_render_tree($children, (int)$r['location_id'], $depth + 1, $csrf);
    }
}

/* Parent picker: every node, indented to show where it sits. */
function loc_parent_options(array $children, int $parentId, int $depth)
{
    foreach (($children[$parentId] ?? array()) as $r) {
        echo '<option value="' . (int)$r['location_id'] . '">' . str_repeat('&nbsp;&nbsp;', $depth) . loc_h($r['name']) . ' (' . loc_h($r['kind']) . ')</option>';
        loc_parent_options($children, (int)$r['location_id'], $depth + 1);
    }
}

$db = isset($db_host, $db_user, $db_pass, $db_name)
    ? @mysqli_connect($db_host, $db_user, $db_pass, $db_name, isset($db_port) ? (int)$db_port : null)
    : false;
if (!$db && billing_is_panel_context()) { echo '<div class="failure">Billing admin: database connection failed.</div>'; return; }
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
            $name = trim((string)($_POST['name'] ?? ''));
            $level = trim((string)($_POST['level'] ?? ''));
            $parent = (int)($_POST['parent_id'] ?? 0);
            if ($name === '') {
                $msg = 'Name is required.';
            } else {
                $level = $level === '' ? 'area' : substr($level, 0, 32);
                $pv = $parent > 0 ? $parent : null;
                $s = $db->prepare('INSERT INTO ' . $p . 'billing_locations (parent_id, kind, name, enabled) VALUES (?, ?, ?, 1)');
                $s->bind_param('iss', $pv, $level, $name);
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
                $msg = 'Delete refused: an agent is still assigned to this location.';
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

$rows = array();
$res = $db->query('SELECT location_id, parent_id, kind, name, enabled FROM ' . $p . 'billing_locations ORDER BY name');
while ($res && ($r = $res->fetch_assoc())) { $rows[] = $r; }
$children = array();
foreach ($rows as $r) {
    $key = $r['parent_id'] === null ? 0 : (int)$r['parent_id'];
    $children[$key][] = $r;
}
mysqli_close($db);
?>
<h2>Locations</h2>
<p class="muted">Build the location tree from any level: for example Continent, Country, State, then City. Each agent is assigned to one node, and the storefront shows every level above it.</p>
<?php if ($msg !== '') { echo '<p><b>' . loc_h($msg) . '</b></p>'; } ?>
<table>
<tr><th>Location</th><th>Level</th><th>Enabled</th><th>Actions</th></tr>
<?php loc_render_tree($children, 0, 0, $csrf); ?>
</table>
<h3>Add location</h3>
<form method="post">
<input type="hidden" name="csrf" value="<?php echo loc_h($csrf); ?>">
<input type="hidden" name="action" value="add">
<input type="text" name="name" placeholder="Name" required>
<input type="text" name="level" list="loc-levels" placeholder="Level (Country, State, City...)">
<datalist id="loc-levels"><option value="Continent"><option value="Country"><option value="State"><option value="Region"><option value="City"></datalist>
<select name="parent_id"><option value="0">(top level)</option>
<?php loc_parent_options($children, 0, 0); ?>
</select>
<button type="submit">Add</button>
</form>
