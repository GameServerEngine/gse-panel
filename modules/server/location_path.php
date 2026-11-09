<?php
/* Location paths for the machine placement form. A path such as "USA / Central / Kansas City"
 * is resolved level by level; any level that does not exist yet is created under the one above. */
function location_tree_rows($db, $prefix)
{
	return $db->resultQuery('SELECT location_id, parent_id, kind, name FROM ' . $prefix . 'billing_locations WHERE enabled = 1 ORDER BY name');
}

/* Map of location_id => "Parent / Child / Node". */
function location_paths(array $rows)
{
	$byId = array();
	foreach ($rows as $r) {
		$byId[(int)$r['location_id']] = $r;
	}
	$paths = array();
	foreach ($byId as $id => $r) {
		$names = array();
		$cur = $r;
		$guard = 0;
		while ($cur !== null && $guard++ < 20) {
			array_unshift($names, (string)$cur['name']);
			$pid = (int)($cur['parent_id'] ?? 0);
			$cur = ($pid > 0 && isset($byId[$pid])) ? $byId[$pid] : null;
		}
		$paths[$id] = implode(' / ', $names);
	}
	return $paths;
}

/* Returns the location_id of the last level in the path (0 when the path is empty). */
function resolve_location_path($db, $prefix, $path)
{
	$parent = 0;
	foreach (explode('/', (string)$path) as $seg) {
		$name = trim($seg);
		if ($name === '') {
			continue;
		}
		$esc = $db->realEscapeSingle($name);
		$where = ($parent > 0 ? 'parent_id = ' . (int)$parent : 'parent_id IS NULL');
		$found = $db->resultQuery('SELECT location_id FROM ' . $prefix . 'billing_locations WHERE ' . $where . " AND LOWER(name) = LOWER('" . $esc . "') LIMIT 1");
		if (!empty($found[0]['location_id'])) {
			$parent = (int)$found[0]['location_id'];
			continue;
		}
		$parentSql = $parent > 0 ? (string)$parent : 'NULL';
		$db->query('INSERT INTO ' . $prefix . "billing_locations (parent_id, kind, name, enabled) VALUES (" . $parentSql . ", 'area', '" . $esc . "', 1)");
		$made = $db->resultQuery('SELECT location_id FROM ' . $prefix . 'billing_locations WHERE ' . $where . " AND name = '" . $esc . "' ORDER BY location_id DESC LIMIT 1");
		$parent = !empty($made[0]['location_id']) ? (int)$made[0]['location_id'] : $parent;
	}
	return $parent;
}
