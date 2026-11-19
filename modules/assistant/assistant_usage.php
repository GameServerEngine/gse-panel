<?php
/*
 * Usage limits and the audit log. Tables are created the first time they are needed,
 * the same way the API token table is. (Move them into the base schema before release.)
 */

function assistant_table($name)
{
	global $table_prefix;
	return '`' . $table_prefix . 'assistant_' . $name . '`';
}

function assistant_ensure_tables($db)
{
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

// Requests this user made in the last 60 seconds.
function assistant_requests_last_minute($db, $user_id)
{
	$rows = $db->resultQuery('SELECT COUNT(*) AS n FROM ' . assistant_table('log')
		. ' WHERE user_id = ' . (int)$user_id . ' AND created > (NOW() - INTERVAL 60 SECOND)');
	return isset($rows[0]['n']) ? (int)$rows[0]['n'] : 0;
}

function assistant_record($db, $user_id, $question, $result)
{
	$month = date('Y-m');
	$db->query('INSERT INTO ' . assistant_table('usage') . ' (user_id, month, input_tokens, output_tokens, requests) VALUES ('
		. (int)$user_id . ", '" . $month . "', " . (int)$result['input_tokens'] . ', ' . (int)$result['output_tokens'] . ', 1)'
		. ' ON DUPLICATE KEY UPDATE input_tokens = input_tokens + VALUES(input_tokens), '
		. 'output_tokens = output_tokens + VALUES(output_tokens), requests = requests + 1');
	$db->query('INSERT INTO ' . assistant_table('log') . ' (user_id, created, question, tools, answer, input_tokens, output_tokens) VALUES ('
		. (int)$user_id . ', NOW(), ' . "'" . $db->real_escape_string($question) . "', '"
		. $db->real_escape_string(implode(',', $result['tools'])) . "', '" . $db->real_escape_string($result['text']) . "', "
		. (int)$result['input_tokens'] . ', ' . (int)$result['output_tokens'] . ')');
}
?>
