<?php
require_once(dirname(__DIR__) . '/migration_runner.php');

function migtest_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

/**
 * In-memory stand-in for DatabaseMySQL, just enough surface for the
 * migration runner: query(), resultQuery(), real_escape_string(), getError().
 * Tracks every statement it was asked to run so tests can assert order.
 */
class MigrationTestDb
{
    public $tables = [];
    public $executed = [];
    public $fail_on_substring = null;
    private $error = '';

    public function real_escape_string($s)
    {
        return addslashes((string)$s);
    }

    public function getError()
    {
        return $this->error;
    }

    public function query($sql)
    {
        $this->executed[] = $sql;

        if ($this->fail_on_substring !== null && strpos($sql, $this->fail_on_substring) !== false) {
            $this->error = 'simulated failure for: ' . $this->fail_on_substring;
            return false;
        }

        if (preg_match('/^CREATE TABLE IF NOT EXISTS `DB_PREFIXschema_migrations`/', $sql)) {
            $this->tables['schema_migrations'] = true;
            return true;
        }
        if (preg_match('/^INSERT INTO `DB_PREFIXschema_migrations` \(migration_id, applied_at\) VALUES \(\'([^\']+)\', \'([^\']+)\'\)/', $sql, $m)) {
            $this->tables['schema_migrations_rows'][$m[1]] = $m[2];
            return true;
        }
        // Any other statement (the migration's own SQL body) is recorded as executed and assumed to succeed.
        return true;
    }

    public function resultQuery($sql)
    {
        if (preg_match('/^SELECT migration_id, applied_at FROM `DB_PREFIXschema_migrations`/', $sql)) {
            $rows = [];
            foreach ((array)($this->tables['schema_migrations_rows'] ?? []) as $id => $at) {
                $rows[] = ['migration_id' => $id, 'applied_at' => $at];
            }
            return $rows;
        }
        return [];
    }
}

function migtest_make_dir(array $files)
{
    $dir = sys_get_temp_dir() . '/migtest_' . getmypid() . '_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    foreach ($files as $name => $contents) {
        file_put_contents($dir . '/' . $name, $contents);
    }
    return $dir;
}

function migtest_rmdir($dir)
{
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

// --- discover: natural, numeric order regardless of filesystem glob order ---
$dir = migtest_make_dir([
    '0002_second.sql' => "CREATE TABLE b (id INT);",
    '0001_first.sql' => "CREATE TABLE a (id INT);",
    '0010_tenth.sql' => "CREATE TABLE j (id INT);",
    'not_a_migration.txt' => "ignore me",
]);
$discovered = migrations_discover($dir);
migtest_assert(count($discovered) === 3, 'discover ignores non-.sql files');
migtest_assert($discovered[0]['id'] === '0001_first', 'discover orders 0001 before 0002');
migtest_assert($discovered[1]['id'] === '0002_second', 'discover orders 0002 before 0010');
migtest_assert($discovered[2]['id'] === '0010_tenth', 'discover orders 0010 last (natural, not lexical)');
migtest_rmdir($dir);

// --- apply order, skip already-applied, record each as applied ---
$dir = migtest_make_dir([
    '0001_first.sql' => "CREATE TABLE a (id INT);",
    '0002_second.sql' => "CREATE TABLE b (id INT);\nCREATE TABLE c (id INT);",
]);
$db = new MigrationTestDb();
$log = [];
$logger = function ($msg) use (&$log) { $log[] = $msg; };
$run = migrations_run_pending($db, $dir, $logger);
migtest_assert($run['success'] === true, 'first run succeeds');
migtest_assert($run['applied'] === ['0001_first', '0002_second'], 'first run applies both migrations in order');
migtest_assert(isset($db->tables['schema_migrations_rows']['0001_first']), '0001_first recorded as applied');
migtest_assert(isset($db->tables['schema_migrations_rows']['0002_second']), '0002_second recorded as applied');

$run2 = migrations_run_pending($db, $dir, $logger);
migtest_assert($run2['success'] === true, 'second run succeeds');
migtest_assert($run2['applied'] === [], 'second run applies nothing new');
migtest_assert($run2['skipped'] === ['0001_first', '0002_second'], 'second run skips both already-applied migrations');
migtest_rmdir($dir);

// --- a later migration is added: only the new one runs ---
$dir = migtest_make_dir([
    '0001_first.sql' => "CREATE TABLE a (id INT);",
    '0002_second.sql' => "CREATE TABLE b (id INT);\nCREATE TABLE c (id INT);",
    '0003_third.sql' => "CREATE TABLE d (id INT);",
]);
$run3 = migrations_run_pending($db, $dir, $logger);
migtest_assert($run3['applied'] === ['0003_third'], 'adding a migration only applies the new one');
migtest_assert($run3['skipped'] === ['0001_first', '0002_second'], 'previously applied migrations stay skipped');
migtest_rmdir($dir);

// --- failure stops at the failing migration and does not record or continue past it ---
$dir = migtest_make_dir([
    '0001_ok.sql' => "CREATE TABLE ok (id INT);",
    '0002_bad.sql' => "CREATE TABLE bad_table (id INT);",
    '0003_never.sql' => "CREATE TABLE never_runs (id INT);",
]);
$db2 = new MigrationTestDb();
$db2->fail_on_substring = 'bad_table';
$run4 = migrations_run_pending($db2, $dir, $logger);
migtest_assert($run4['success'] === false, 'run with a failing migration reports failure');
migtest_assert($run4['failed_migration'] === '0002_bad', 'failure identifies the failing migration');
migtest_assert($run4['applied'] === ['0001_ok'], 'migrations before the failure are applied');
migtest_assert(!isset($db2->tables['schema_migrations_rows']['0002_bad']), 'the failing migration itself is not recorded as applied');
migtest_assert(!isset($db2->tables['schema_migrations_rows']['0003_never']), 'migrations after a failure never run');
migtest_rmdir($dir);

// --- statement splitting: semicolons inside quoted strings do not split the statement ---
$statements = sql_split_statements("INSERT INTO t (a) VALUES ('x;y');\n-- comment;\nSELECT 1;");
migtest_assert(count($statements) === 2, 'quoted semicolon does not create an extra statement, comment line is dropped');
migtest_assert(strpos($statements[0], "'x;y'") !== false, 'quoted semicolon preserved inside the statement');

// --- crash recovery for ALTER TABLE ... ADD COLUMN ---
$p = migrations_parse_add_columns("ALTER TABLE `DB_PREFIXgame_hour`\n  ADD COLUMN `a` DECIMAL(6,3) NULL,\n  ADD COLUMN `b` DECIMAL(10,2) NOT NULL");
migtest_assert($p['table'] === 'DB_PREFIXgame_hour' && count($p['columns']) === 2, 'parser reads table and both columns, commas inside DECIMAL(6,3) do not split');
migtest_assert($p['columns'][0]['type'] === 'decimal(6,3)' && $p['columns'][0]['nullable'] === true, 'parser reads type and NULL');
migtest_assert($p['columns'][1]['nullable'] === false, 'parser reads NOT NULL');
migtest_assert(migrations_parse_add_columns("ALTER TABLE t ADD COLUMN a INT NOT NULL DEFAULT 0") === null, 'a column with a DEFAULT is not trusted for recovery');
migtest_assert(migrations_parse_add_columns("ALTER TABLE t ADD COLUMN a INT COMMENT 'x'") === null, 'a column with a comment is not trusted for recovery');
migtest_assert(migrations_parse_add_columns("ALTER TABLE t DROP COLUMN a") === null, 'other ALTER statements are not recovered');
migtest_assert(migrations_parse_add_columns("CREATE TABLE t (id INT)") === null, 'non-ALTER statements are not recovered');

/** Answers the information_schema lookup from a fixed list of live columns and counts ALTER runs. */
class ColumnStateDb extends MigrationTestDb
{
    public $live = [];
    public function resultQuery($sql)
    {
        if (strpos($sql, 'information_schema.COLUMNS') !== false) {
            return $this->live;
        }
        return parent::resultQuery($sql);
    }
}
function migtest_live($name, $type, $nullable = 'YES', $default = null, $extra = '')
{
    return ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => $default, 'EXTRA' => $extra];
}
function migtest_alter_count($db)
{
    return count(array_filter($db->executed, function ($s) { return strpos($s, 'ALTER TABLE') === 0; }));
}
$alter = "ALTER TABLE `DB_PREFIXt` ADD COLUMN `a` DECIMAL(6,3) NULL, ADD COLUMN `b` LONGTEXT NULL;";
$dir = migtest_make_dir(['0001_alter.sql' => $alter]);

$cdb = new ColumnStateDb();
$run = migrations_run_pending($cdb, $dir, $logger);
migtest_assert($run['success'] && migtest_alter_count($cdb) === 1, 'missing columns: the ALTER runs');
migtest_rmdir($dir);

$dir = migtest_make_dir(['0001_alter.sql' => $alter]);
$cdb = new ColumnStateDb();
$cdb->live = [migtest_live('a', 'decimal(6,3)'), migtest_live('b', 'longtext')];
$run = migrations_run_pending($cdb, $dir, $logger);
migtest_assert($run['success'] && migtest_alter_count($cdb) === 0 && $run['applied'] === ['0001_alter'],
    'identical columns already exist: the ALTER is skipped and the migration is recorded');
migtest_rmdir($dir);

$dir = migtest_make_dir(['0001_alter.sql' => $alter]);
$cdb = new ColumnStateDb();
$cdb->live = [migtest_live('a', 'decimal(6,3)'), migtest_live('b', 'text')];
$run = migrations_run_pending($cdb, $dir, $logger);
migtest_assert(!$run['success'] && migtest_alter_count($cdb) === 0 && !isset($cdb->tables['schema_migrations_rows']['0001_alter']),
    'a column with a different type fails the migration and records nothing');
migtest_assert(strpos($run['error'], 'different definition') !== false, 'the failure explains the conflict');
migtest_rmdir($dir);

$dir = migtest_make_dir(['0001_alter.sql' => $alter]);
$cdb = new ColumnStateDb();
$cdb->live = [migtest_live('a', 'decimal(6,3)')];
$run = migrations_run_pending($cdb, $dir, $logger);
migtest_assert(!$run['success'] && migtest_alter_count($cdb) === 0, 'only some of the columns exist: fail closed, do not guess');
migtest_rmdir($dir);

foreach ([
    'wrong nullability' => migtest_live('a', 'decimal(6,3)', 'NO'),
    'has a default' => migtest_live('a', 'decimal(6,3)', 'YES', '0.000'),
    'generated or auto column' => migtest_live('a', 'decimal(6,3)', 'YES', null, 'VIRTUAL GENERATED'),
] as $label => $aRow) {
    $dir = migtest_make_dir(['0001_alter.sql' => $alter]);
    $cdb = new ColumnStateDb();
    $cdb->live = [$aRow, migtest_live('b', 'longtext')];
    $run = migrations_run_pending($cdb, $dir, $logger);
    migtest_assert(!$run['success'], "existing column differs ($label): the migration fails");
    migtest_rmdir($dir);
}

echo "All migration runner tests passed.\n";
