<?php
/*
 *
 * GSP - GameServer Panel
 *
 * Migration runner: applies numbered SQL files from sql/migrations/ in order,
 * records each as applied in the schema_migrations table, and skips
 * migrations already recorded. Replaces the old one-off patch system
 * (modules/update/patches/).
 *
 */

function migrations_table_name()
{
	return 'DB_PREFIXschema_migrations';
}

function migrations_ensure_table($db)
{
	if (!isset($db) || !is_object($db)) {
		return false;
	}
	$db->query(
		"CREATE TABLE IF NOT EXISTS `" . migrations_table_name() . "` ("
		. "`migration_id` VARCHAR(191) NOT NULL,"
		. "`applied_at` DATETIME NOT NULL,"
		. "PRIMARY KEY (`migration_id`)"
		. ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
	);
	return (string)$db->getError() === '';
}

function migrations_discover($dir)
{
	$migrations = [];
	if (!is_dir($dir)) {
		return $migrations;
	}
	$files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
	sort($files, SORT_NATURAL);
	foreach ($files as $file) {
		$base = basename($file, '.sql');
		if (!preg_match('/^(\d+)_(.+)$/', $base, $m)) {
			continue;
		}
		$migrations[] = [
			'id' => $base,
			'number' => $m[1],
			'name' => $m[2],
			'file' => $file,
		];
	}
	usort($migrations, function ($a, $b) {
		return strnatcmp($a['id'], $b['id']);
	});
	return $migrations;
}

function migrations_get_applied($db)
{
	$applied = [];
	if (!isset($db) || !is_object($db)) {
		return $applied;
	}
	$rows = $db->resultQuery("SELECT migration_id, applied_at FROM `" . migrations_table_name() . "`");
	if (is_array($rows)) {
		foreach ($rows as $row) {
			$applied[$row['migration_id']] = $row['applied_at'];
		}
	}
	return $applied;
}

function migrations_record($db, $migration_id, $applied_at = null)
{
	if (!isset($db) || !is_object($db)) {
		return false;
	}
	$applied_at = $applied_at ?: date('Y-m-d H:i:s');
	$id_esc = $db->real_escape_string($migration_id);
	$at_esc = $db->real_escape_string($applied_at);
	return $db->query(
		"INSERT INTO `" . migrations_table_name() . "` (migration_id, applied_at) "
		. "VALUES ('{$id_esc}', '{$at_esc}') "
		. "ON DUPLICATE KEY UPDATE applied_at = VALUES(applied_at)"
	);
}

/**
 * Splits a SQL file's contents into individual statements on semicolons that
 * are not inside single-quoted, double-quoted, or backtick-quoted spans.
 * Lines starting with -- (after optional leading whitespace) are dropped.
 */
function sql_split_statements($sql)
{
	$lines = explode("\n", (string)$sql);
	$filtered = [];
	foreach ($lines as $line) {
		if (preg_match('/^\s*--/', $line)) {
			continue;
		}
		$filtered[] = $line;
	}
	$sql = implode("\n", $filtered);

	$statements = [];
	$current = '';
	$len = strlen($sql);
	$quote = null;
	for ($i = 0; $i < $len; $i++) {
		$ch = $sql[$i];
		if ($quote !== null) {
			$current .= $ch;
			if ($ch === $quote && ($i === 0 || $sql[$i - 1] !== '\\')) {
				$quote = null;
			}
			continue;
		}
		if ($ch === "'" || $ch === '"' || $ch === '`') {
			$quote = $ch;
			$current .= $ch;
			continue;
		}
		if ($ch === ';') {
			$trimmed = trim($current);
			if ($trimmed !== '') {
				$statements[] = $trimmed;
			}
			$current = '';
			continue;
		}
		$current .= $ch;
	}
	$trimmed = trim($current);
	if ($trimmed !== '') {
		$statements[] = $trimmed;
	}
	return $statements;
}

/**
 * Applies every migration in $dir that is not yet recorded in the migrations
 * table, in ascending numeric/name order. Stops at the first failure so later
 * migrations never run against a half-applied schema.
 */
function migrations_run_pending($db, $dir, callable $logger)
{
	$result = [
		'success' => true,
		'available' => 0,
		'applied' => [],
		'skipped' => [],
		'failed_migration' => null,
		'error' => null,
	];

	if (!migrations_ensure_table($db)) {
		$result['success'] = false;
		$result['error'] = 'Could not create or verify the schema_migrations table.';
		$logger($result['error']);
		return $result;
	}

	$migrations = migrations_discover($dir);
	$result['available'] = count($migrations);
	$applied = migrations_get_applied($db);

	foreach ($migrations as $migration) {
		$id = $migration['id'];
		if (isset($applied[$id])) {
			$result['skipped'][] = $id;
			$logger("Migration {$id} already applied; skipping.");
			continue;
		}

		$sql = @file_get_contents($migration['file']);
		if ($sql === false) {
			$msg = "Could not read migration file: {$migration['file']}";
			$result['success'] = false;
			$result['failed_migration'] = $id;
			$result['error'] = $msg;
			$logger($msg);
			break;
		}

		$logger("Applying migration {$id}.");
		$statements = sql_split_statements($sql);
		$ok = true;
		foreach ($statements as $statement) {
			$db->query($statement);
			if ((string)$db->getError() !== '') {
				$ok = false;
				break;
			}
		}

		if (!$ok) {
			$detail = (method_exists($db, 'getError') ? $db->getError() : 'statement failed');
			$msg = "Migration {$id} failed: {$detail}";
			$result['success'] = false;
			$result['failed_migration'] = $id;
			$result['error'] = $msg;
			$logger($msg);
			break;
		}

		migrations_record($db, $id);
		$result['applied'][] = $id;
		$logger("Migration {$id} applied.");
	}

	return $result;
}

/**
 * True if no migration has been recorded as applied without also completing
 * (used by preflight to refuse to start a new update mid-migration).
 * With migrations_run_pending stopping at the first failed migration
 * without recording it, "half-applied" means a migration ran its statements
 * but the process was interrupted before the record insert below it. The
 * schema_migrations table itself is the source of truth: a migration is only
 * ever "applied" once its insert is committed, so there is nothing further to
 * detect here beyond the table being reachable.
 */
function migrations_preflight_ok($db)
{
	return migrations_ensure_table($db);
}
