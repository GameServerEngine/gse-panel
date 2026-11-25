<?php
/*
 * Usage counters, the limits that read them, and the audit log. The tables come from the module's
 * migrations and install/gse_panel.sql; assistant_ensure_tables() is only a safety net.
 */

function assistant_table($name)
{
	global $table_prefix;
	return '`' . $table_prefix . 'assistant_' . $name . '`';
}

function assistant_ensure_tables($db)
{
	$db->query('CREATE TABLE IF NOT EXISTS ' . assistant_table('server_usage') . ' ('
		. '`home_id` int NOT NULL, `month` char(7) NOT NULL, `input_tokens` bigint NOT NULL DEFAULT 0, '
		. '`output_tokens` bigint NOT NULL DEFAULT 0, `requests` int NOT NULL DEFAULT 0, PRIMARY KEY (`home_id`, `month`))');
	$db->query('CREATE TABLE IF NOT EXISTS ' . assistant_table('proposals') . ' ('
		. '`id` bigint NOT NULL AUTO_INCREMENT, `user_id` int NOT NULL, `home_id` int NOT NULL DEFAULT 0, `kind` varchar(12) NOT NULL, '
		. '`target` varchar(300) NOT NULL, `old_content` mediumblob, `new_content` mediumblob, `note` varchar(300), '
		. '`status` varchar(10) NOT NULL DEFAULT \'pending\', `created` datetime NOT NULL, `decided` datetime, '
		. 'PRIMARY KEY (`id`), KEY `home_status` (`home_id`, `status`), KEY `kind_status` (`kind`, `status`))');
	$db->query('CREATE TABLE IF NOT EXISTS ' . assistant_table('usage') . ' ('
		. '`user_id` int NOT NULL, `month` char(7) NOT NULL, `input_tokens` bigint NOT NULL DEFAULT 0, '
		. '`output_tokens` bigint NOT NULL DEFAULT 0, `requests` int NOT NULL DEFAULT 0, PRIMARY KEY (`user_id`, `month`))');
	$db->query('CREATE TABLE IF NOT EXISTS ' . assistant_table('log') . ' ('
		. '`id` bigint NOT NULL AUTO_INCREMENT, `user_id` int NOT NULL, `created` datetime NOT NULL, '
		. '`question` text, `tools` varchar(255), `answer` text, `input_tokens` int NOT NULL DEFAULT 0, '
		. '`output_tokens` int NOT NULL DEFAULT 0, PRIMARY KEY (`id`), KEY `user_time` (`user_id`, `created`))');
}

// Tokens (input + output) this user has used this month.
function assistant_tokens_this_month($db, $user_id)
{
	$rows = $db->resultQuery('SELECT input_tokens + output_tokens AS used FROM ' . assistant_table('usage')
		. ' WHERE user_id = ' . (int)$user_id . " AND month = '" . date('Y-m') . "'");
	return isset($rows[0]['used']) ? (int)$rows[0]['used'] : 0;
}

// Requests this user made in the last N seconds (the gap between questions and the hourly limit both use it).
function assistant_requests_since($db, $user_id, $seconds)
{
	$rows = $db->resultQuery('SELECT COUNT(*) AS n FROM ' . assistant_table('log')
		. ' WHERE user_id = ' . (int)$user_id . ' AND created > (NOW() - INTERVAL ' . (int)$seconds . ' SECOND)');
	return isset($rows[0]['n']) ? (int)$rows[0]['n'] : 0;
}

// Tokens (input + output) this server has used this month, for the per-server cap.
function assistant_server_tokens_this_month($db, $home_id)
{
	$rows = $db->resultQuery('SELECT input_tokens + output_tokens AS used FROM ' . assistant_table('server_usage')
		. ' WHERE home_id = ' . (int)$home_id . " AND month = '" . date('Y-m') . "'");
	return isset($rows[0]['used']) ? (int)$rows[0]['used'] : 0;
}

// Everything used this month by everyone: array(input_tokens, output_tokens, requests).
function assistant_month_totals($db)
{
	$rows = $db->resultQuery('SELECT COALESCE(SUM(input_tokens), 0) AS i, COALESCE(SUM(output_tokens), 0) AS o, COALESCE(SUM(requests), 0) AS r FROM '
		. assistant_table('usage') . " WHERE month = '" . date('Y-m') . "'");
	return isset($rows[0]) ? array((int)$rows[0]['i'], (int)$rows[0]['o'], (int)$rows[0]['r']) : array(0, 0, 0);
}

// $home_id is the server a customer chat belongs to (0 for the admin chat, which has no server).
function assistant_record($db, $user_id, $question, $result, $scope_key = '', $home_id = 0)
{
	$month = date('Y-m');
	if ((int)$home_id > 0) {
		$db->query('INSERT INTO ' . assistant_table('server_usage') . ' (home_id, month, input_tokens, output_tokens, requests) VALUES ('
			. (int)$home_id . ", '" . $month . "', " . (int)$result['input_tokens'] . ', ' . (int)$result['output_tokens'] . ', 1)'
			. ' ON DUPLICATE KEY UPDATE input_tokens = input_tokens + VALUES(input_tokens), '
			. 'output_tokens = output_tokens + VALUES(output_tokens), requests = requests + 1');
	}
	$db->query('INSERT INTO ' . assistant_table('usage') . ' (user_id, month, input_tokens, output_tokens, requests) VALUES ('
		. (int)$user_id . ", '" . $month . "', " . (int)$result['input_tokens'] . ', ' . (int)$result['output_tokens'] . ', 1)'
		. ' ON DUPLICATE KEY UPDATE input_tokens = input_tokens + VALUES(input_tokens), '
		. 'output_tokens = output_tokens + VALUES(output_tokens), requests = requests + 1');
	$db->query('INSERT INTO ' . assistant_table('log') . ' (user_id, created, question, tools, answer, input_tokens, output_tokens) VALUES ('
		. (int)$user_id . ', NOW(), ' . "'" . $db->real_escape_string(($scope_key !== '' ? '[' . $scope_key . '] ' : '') . $question) . "', '"
		. $db->real_escape_string(implode(',', $result['tools'])) . "', '" . $db->real_escape_string($result['text']) . "', "
		. (int)$result['input_tokens'] . ', ' . (int)$result['output_tokens'] . ')');
}

// A log line for something that is not a model answer (a button press). Not counted as a chat request.
function assistant_audit($db, $user_id, $what, $tools, $note)
{
	$db->query('INSERT INTO ' . assistant_table('log') . ' (user_id, created, question, tools, answer, input_tokens, output_tokens) VALUES ('
		. (int)$user_id . ", '1970-01-01 00:00:00', '" . $db->real_escape_string($what) . "', '" . $db->real_escape_string($tools) . "', '" . $db->real_escape_string($note) . "', 0, 0)");
}
?>
