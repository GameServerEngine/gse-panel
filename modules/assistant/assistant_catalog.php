<?php
/*
 * The list of everything the assistant can do, in one place: what each tool is called, what it
 * takes, who may use it, and which admin switch turns it off. The tool definitions sent to the
 * model and the list of tools the panel will run are both built from this list, so they cannot
 * disagree. A customer's chat never gets a server id to choose: the panel knows the server.
 */

// group: core (always), files, edits, db, resets (each has an admin switch), admin (administrators only), agent (needs agent folders)
function assistant_catalog()
{
	$filter = array('type' => 'string', 'enum' => array('errors', 'security', 'logins', 'recent'), 'description' => 'errors = problems and crashes, security = failed logins, bans, kicks, rcon or injection attempts, logins = players joining and leaving, recent = the last lines.');
	$path = array('type' => 'string', 'description' => 'Path inside the server folder, like cfg/server.cfg. Empty means the top folder.');
	$read_opts = array(
		'lines' => array('type' => 'integer', 'description' => 'How many lines (5 to 200, default 80).'),
		'from' => array('type' => 'string', 'enum' => array('start', 'end'), 'description' => 'Take lines from the start or the end of the file.'),
		'contains' => array('type' => 'string', 'description' => 'Only lines that contain this text (not case sensitive).'),
		'filter' => array('type' => 'string', 'enum' => array('errors', 'security', 'logins', 'recent'), 'description' => 'Same filters as server_log.'),
	);
	$edits = array(
		'type' => 'array', 'maxItems' => 10, 'description' => 'For a file that exists: the changes. Each old_text must appear exactly once in the file.',
		'items' => array('type' => 'object', 'properties' => array('old_text' => array('type' => 'string'), 'new_text' => array('type' => 'string')), 'required' => array('old_text', 'new_text')),
	);
	$summary = array('type' => 'string', 'description' => 'One short sentence: what this change is for.');

	return array(
		array('name' => 'server_status', 'group' => 'core', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. Ask the agent whether the server process is running and its ports are open.', 'props' => array(), 'required' => array()),
		array('name' => 'server_log', 'group' => 'core', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. Recent lines of the game server log, filtered.', 'props' => array('filter' => $filter), 'required' => array('filter')),
		array('name' => 'database_info', 'group' => 'core', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. The server\'s MySQL databases: name, user, host, size, and whether the panel can log in with the stored password.', 'props' => array(), 'required' => array()),
		array('name' => 'ftp_info', 'group' => 'core', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. The server\'s FTP login name, host, port, and whether the account exists on the machine.', 'props' => array(), 'required' => array()),
		array('name' => 'propose_action', 'group' => 'core', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Offer a button to start, stop or restart the server. Nothing happens until the person presses it.',
			'props' => array('action' => array('type' => 'string', 'enum' => array('start', 'stop', 'restart'))), 'required' => array('action')),
		array('name' => 'propose_reset', 'group' => 'resets', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Offer a button that makes a new database password or a new FTP password. The panel makes the password and shows it only to the person when they press the button.',
			'props' => array('what' => array('type' => 'string', 'enum' => array('database_password', 'ftp_password')), 'db_id' => array('type' => 'integer', 'description' => 'Only for database_password when the server has more than one database: the db_id from database_info.')), 'required' => array('what')),
		array('name' => 'list_files', 'group' => 'files', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. List a folder inside the server\'s files.', 'props' => array('path' => $path), 'required' => array()),
		array('name' => 'read_file', 'group' => 'files', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. Read lines of a text file inside the server\'s files (a config, a script, a log). Passwords and keys are blanked.',
			'props' => array_merge(array('path' => $path), $read_opts), 'required' => array('path')),
		array('name' => 'db_query', 'group' => 'db', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Read-only. Run ONE SELECT, SHOW, DESCRIBE or EXPLAIN on the server\'s database, with the database\'s own login. At most 30 rows come back. No comments and no semicolons inside the statement.',
			'props' => array('sql' => array('type' => 'string'), 'db_id' => array('type' => 'integer', 'description' => 'Only when the server has more than one database.')), 'required' => array('sql')),
		array('name' => 'propose_file_edit', 'group' => 'edits', 'per_server' => true, 'scopes' => array('server', 'admin'),
			'description' => 'Propose a change to a text file in the server\'s files, or a new file (a script, a mod, a config). It is only saved as a proposal. The person reviews the exact change and presses Apply. Use small, exact edits. Never put passwords or keys in a file.',
			'props' => array('path' => $path, 'summary' => $summary, 'edits' => $edits, 'content' => array('type' => 'string', 'description' => 'For a NEW file only: its full text.')), 'required' => array('path', 'summary')),

		array('name' => 'propose_changes', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Propose a fix as ONE change set with as many steps as it needs. Each step is a file change on the panel or on an agent, an SQL statement on the panel database or any MySQL server, or a command on the panel or an agent. NOTHING runs now: the administrator sees every step as a diff and presses Apply, and the steps then run in order. There is no limit on what a step may touch.',
			'props' => array('summary' => $summary, 'steps' => array('type' => 'array', 'maxItems' => 40, 'description' => 'The steps, in the order they must run.', 'items' => array('type' => 'object', 'properties' => array(
				'kind' => array('type' => 'string', 'enum' => array('file', 'sql', 'command')),
				'where' => array('type' => 'string', 'enum' => array('panel', 'agent'), 'description' => 'For file and command steps.'),
				'server_id' => array('type' => 'integer', 'description' => 'The agent id from agents_health, when where is agent.'),
				'path' => array('type' => 'string', 'description' => 'File steps: absolute path, or relative to the panel folder for the panel.'),
				'edits' => $edits,
				'content' => array('type' => 'string', 'description' => 'File steps: the full text of a NEW file, or to replace a whole existing file.'),
				'database' => array('type' => 'string', 'enum' => array('panel', 'mysql'), 'description' => 'SQL steps.'),
				'mysql_server_id' => array('type' => 'integer', 'description' => 'SQL steps on mysql: the id from mysql_health.'),
				'sql' => array('type' => 'string', 'description' => 'SQL steps: one statement.'),
				'command' => array('type' => 'string', 'description' => 'Command steps: the shell command.')), 'required' => array('kind')))), 'required' => array('summary', 'steps')),
		array('name' => 'db_read', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Run one SELECT, SHOW, DESCRIBE or EXPLAIN on the panel database or on any MySQL server (use schema.table to reach any database there). Up to 100 rows.',
			'props' => array('database' => array('type' => 'string', 'enum' => array('panel', 'mysql')), 'mysql_server_id' => array('type' => 'integer', 'description' => 'Only for mysql: the id from mysql_health.'), 'sql' => array('type' => 'string')), 'required' => array('database', 'sql')),
		array('name' => 'list_servers', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'List every game server with its id, name, owner and address.', 'props' => array(), 'required' => array()),
		array('name' => 'panel_health', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Panel version, PHP version, disk space, user and server counts, recent PHP errors.', 'props' => array(), 'required' => array()),
		array('name' => 'mysql_health', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. For the panel database and each MySQL server: version, uptime, connections, database sizes. No row data.', 'props' => array(), 'required' => array()),
		array('name' => 'game_databases', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. The MySQL databases assigned to game servers: server, database name, size, enabled. No passwords.', 'props' => array(), 'required' => array()),
		array('name' => 'agents_health', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Every agent (machine): reachable or not, agent version, response time.', 'props' => array(), 'required' => array()),
		array('name' => 'panel_list', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. List a folder of the panel\'s own files (path relative to the panel folder), or a log folder the admin allowed.',
			'props' => array('path' => array('type' => 'string')), 'required' => array()),
		array('name' => 'panel_read', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Read lines of a panel file or an allowed log. The panel config file and key files are never readable.',
			'props' => array_merge(array('path' => array('type' => 'string')), $read_opts), 'required' => array('path')),
		array('name' => 'panel_search', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Find text in the panel\'s code or logs (up to 40 matching lines).',
			'props' => array('query' => array('type' => 'string'), 'path' => array('type' => 'string', 'description' => 'Folder to search in, default the whole panel.')), 'required' => array('query')),
		array('name' => 'agent_list', 'group' => 'agent', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. List a folder on an agent machine, in the folders the admin allowed.',
			'props' => array('server_id' => array('type' => 'integer', 'description' => 'The agent id from agents_health.'), 'path' => array('type' => 'string')), 'required' => array('server_id', 'path')),
		array('name' => 'agent_read', 'group' => 'agent', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Read-only. Read lines of a text file on an agent machine, in the folders the admin allowed.',
			'props' => array_merge(array('server_id' => array('type' => 'integer'), 'path' => array('type' => 'string')), $read_opts), 'required' => array('server_id', 'path')),
		array('name' => 'propose_patch', 'group' => 'admin', 'per_server' => false, 'scopes' => array('admin'),
			'description' => 'Save a patch for a panel file (path relative to the panel folder; a new module is several patches, one per new file) or an agent file. It is NOT applied. The admin downloads it and applies it through git. Use small exact edits.',
			'props' => array('target' => array('type' => 'string', 'enum' => array('panel', 'agent')), 'path' => array('type' => 'string'), 'server_id' => array('type' => 'integer', 'description' => 'Agent id, only for target agent.'),
				'summary' => $summary, 'edits' => $edits, 'content' => array('type' => 'string', 'description' => 'For a NEW file only: its full text.')), 'required' => array('target', 'path', 'summary')),
	);
}

// $cfg is the array from assistant_config(), or null to allow everything (tests).
function assistant_group_allowed($group, $cfg)
{
	if ($cfg === null || $group === 'core' || $group === 'admin') {
		return true;
	}
	$switch = array('files' => 'assistant_allow_files', 'edits' => 'assistant_allow_edits', 'db' => 'assistant_allow_db', 'resets' => 'assistant_allow_resets');
	if ($group === 'agent') {
		return !empty($cfg['agent_paths']);
	}
	return isset($switch[$group]) && $cfg[$switch[$group]] === '1';
}

function assistant_allowed_tools($scope_type, $cfg = null)
{
	$names = array();
	foreach (assistant_catalog() as $tool) {
		if (in_array($scope_type, $tool['scopes'], true) && assistant_group_allowed($tool['group'], $scope_type === 'admin' ? null : $cfg)) {
			$names[] = $tool['name'];
		}
	}
	return $names;
}

function assistant_tool_definitions($scope_type = 'server', $cfg = null)
{
	$defs = array();
	foreach (assistant_catalog() as $tool) {
		if (!in_array($scope_type, $tool['scopes'], true) || !assistant_group_allowed($tool['group'], $scope_type === 'admin' ? null : $cfg)) {
			continue;
		}
		$props = $tool['props'];
		$required = $tool['required'];
		if ($scope_type === 'admin' && $tool['per_server']) {
			$props = array_merge(array('home_id' => array('type' => 'integer', 'description' => 'The server id (home_id) from list_servers.')), $props);
			array_unshift($required, 'home_id');
		}
		$schema = array('type' => 'object', 'properties' => $props ? $props : new stdClass());
		if ($required) {
			$schema['required'] = $required;
		}
		$defs[] = array('name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $schema);
	}
	return $defs;
}
?>
