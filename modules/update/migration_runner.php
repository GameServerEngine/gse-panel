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
 * Splits text on commas that are not inside parentheses, so DECIMAL(6,3) stays whole.
 */
function sql_split_top_level_commas($text)
{
	$parts = [];
	$current = '';
	$depth = 0;
	foreach (str_split((string)$text) as $ch) {
		if ($ch === '(') {
			$depth++;
		} elseif ($ch === ')') {
			$depth--;
		}
		if ($ch === ',' && $depth === 0) {
			$parts[] = trim($current);
			$current = '';
			continue;
		}
		$current .= $ch;
	}
	$parts[] = trim($current);
	return $parts;
}

/**
 * Reads "ALTER TABLE t ADD COLUMN a TYPE [NOT] NULL, ADD COLUMN b ..." into
 * ['table' => 't', 'columns' => [['name', 'type', 'nullable'], ...]].
 * Returns null for any other statement, or for any column clause that has
 * more than a name, a type and NULL / NOT NULL (a default, a comment, AFTER, ...).
 * Crash recovery only trusts what it can compare exactly, so anything else
 * is left to run normally.
 */
function migrations_parse_add_columns($statement)
{
	if (!preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(ADD\s+COLUMN\s.+)$/is', trim($statement), $m)) {
		return null;
	}
	$columns = [];
	foreach (sql_split_top_level_commas($m[2]) as $clause) {
		$ok = preg_match('/^ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+(?:\(\s*\d+(?:\s*,\s*\d+)?\s*\))?)(?:\s+(NOT\s+NULL|NULL))?$/is', $clause, $c);
		if (!$ok) {
			return null;
		}
		$columns[] = [
			'name' => $c[1],
			'type' => strtolower(preg_replace('/\s+/', '', $c[2])),
			'nullable' => !(isset($c[3]) && stripos($c[3], 'NOT') === 0),
		];
	}
	return ['table' => $m[1], 'columns' => $columns];
}

/**
 * Compares the ADD COLUMN columns with the live table and answers:
 *   'missing'  none of the columns exist, so the statement must run
 *   'present'  every column exists with exactly the declared type and nullability
 *              and no default, which is what a crash after the ALTER leaves behind
 *   'conflict' anything else (some columns exist, or a definition differs)
 * Returns 'missing' if the live table cannot be read, so the ALTER itself reports the problem.
 */
function migrations_add_columns_state($db, array $parsed)
{
	$table = $db->real_escape_string($parsed['table']);
	$rows = $db->resultQuery(
		"SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS "
		. "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'"
	);
	if (!is_array($rows)) {
		return 'missing';
	}
	$live = [];
	foreach ($rows as $row) {
		$live[strtolower($row['COLUMN_NAME'])] = $row;
	}
	$found = 0;
	$identical = 0;
	foreach ($parsed['columns'] as $column) {
		$row = $live[strtolower($column['name'])] ?? null;
		if ($row === null) {
			continue;
		}
		$found++;
		if (strtolower($row['COLUMN_TYPE']) === $column['type']
			&& ($row['IS_NULLABLE'] === 'YES') === $column['nullable']
			&& $row['COLUMN_DEFAULT'] === null
			&& (string)$row['EXTRA'] === '') {
			$identical++;
		}
	}
	if ($found === 0) {
		return 'missing';
	}
	return ($identical === count($parsed['columns'])) ? 'present' : 'conflict';
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
		$conflict = null;
		foreach ($statements as $statement) {
			// Crash recovery: a migration that was interrupted after its ALTER but
			// before it was recorded is finished only if every column is identical.
			$parsed = migrations_parse_add_columns($statement);
			if ($parsed !== null) {
				$state = migrations_add_columns_state($db, $parsed);
				if ($state === 'present') {
					$logger("Migration {$id}: columns of {$parsed['table']} already exist with the same definition; skipping that statement.");
					continue;
				}
				if ($state === 'conflict') {
					$ok = false;
					$conflict = "columns of {$parsed['table']} already exist with a different definition or only in part";
					break;
				}
			}
			$db->query($statement);
			if ((string)$db->getError() !== '') {
				$ok = false;
				break;
			}
		}

		if (!$ok) {
			$detail = $conflict ?? (method_exists($db, 'getError') ? $db->getError() : 'statement failed');
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
