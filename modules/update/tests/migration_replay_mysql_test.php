<?php
// Replays the real migration runner against a real, EMPTY MySQL database.
//
//   GSE_PLACEMENT_TEST_SOCKET or _HOST/_PORT, _USER, _PASSWORD  (see placement_mysql.inc)
//   GSE_PLACEMENT_TEST_DB   must start with gse_resource_validation_replay
//   GSE_REPLAY_MIGRATIONS   optional comma list of migration numbers, e.g. 0009,0013,0017,0018
//
// The database must hold no tables when the test starts, so it can never
// touch a dev or production schema. Every table it created is dropped at the end.
// Without a connection setting it prints SKIP and exits 0.
if (getenv('GSE_PLACEMENT_TEST_SOCKET') === false && getenv('GSE_PLACEMENT_TEST_HOST') === false) {
    echo "SKIP: set GSE_PLACEMENT_TEST_SOCKET or GSE_PLACEMENT_TEST_HOST to run on a real MySQL server\n";
    exit(0);
}
$name = getenv('GSE_PLACEMENT_TEST_DB') ?: '';
if (strpos($name, 'gse_resource_validation_replay') !== 0) {
    fwrite(STDERR, "Refusing database '$name': use a name starting with gse_resource_validation_replay\n");
    exit(2);
}
require_once __DIR__ . '/../migration_runner.php';
require_once __DIR__ . '/../../billing/tests/placement_mysql.inc';

// Gives the runner the small surface it expects from the panel's database class.
class ReplayDb
{
    private mysqli $link;
    private string $error = '';
    public function __construct(mysqli $link) { $this->link = $link; }
    public function query($sql)
    {
        $this->error = '';
        $result = $this->link->query(str_replace('DB_PREFIX', '', $sql));
        if ($result === false) $this->error = $this->link->error;
        return $result;
    }
    public function resultQuery($sql)
    {
        $result = $this->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : false;
    }
    public function real_escape_string($s) { return $this->link->real_escape_string($s); }
    public function getError() { return $this->error; }
    public function link(): mysqli { return $this->link; }
}

function replay_tables(mysqli $link): array
{
    $names = [];
    foreach ($link->query('SHOW TABLES')->fetch_all() as $row) $names[] = $row[0];
    return $names;
}

function replay_has_column(mysqli $link, string $table, string $column): bool
{
    $t = $link->real_escape_string($table);
    $c = $link->real_escape_string($column);
    return $link->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->num_rows === 1;
}

mysqli_report(MYSQLI_REPORT_OFF);
$link = placement_test_connect();
$parent = getmypid();
expect_resource(replay_tables($link) === [], 'database is empty before the replay');
register_shutdown_function(function () use ($link) {
    $link->query('SET FOREIGN_KEY_CHECKS=0');
    foreach (replay_tables($link) as $table) $link->query('DROP TABLE IF EXISTS `' . $link->real_escape_string($table) . '`');
    $link->query('SET FOREIGN_KEY_CHECKS=1');
});
$db = new ReplayDb($link);
$root = dirname(__DIR__, 3);

// Baseline schema, then the migrations the way the updater runs them.
foreach (sql_split_statements(file_get_contents($root . '/install/gse_panel.sql')) as $statement) {
    $db->query($statement);
    expect_resource($db->getError() === '', 'install SQL statement ran: ' . substr($statement, 0, 60) . ' ' . $db->getError());
}
$dir = $root . '/sql/migrations';
$only = array_filter(array_map('trim', explode(',', getenv('GSE_REPLAY_MIGRATIONS') ?: '')));
if ($only) {
    // Copy only the chosen files into a scratch folder.
    $dir = sys_get_temp_dir() . '/gse-replay-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700);
    foreach (glob($root . '/sql/migrations/*.sql') as $file) {
        if (in_array(substr(basename($file), 0, 4), $only, true)) copy($file, "$dir/" . basename($file));
    }
    register_shutdown_function(function () use ($dir, $parent) {
        if (getmypid() !== $parent) return;
        array_map('unlink', glob("$dir/*.sql"));
        rmdir($dir);
    });
}
$log = [];
$logger = function ($line) use (&$log) { $log[] = $line; };

$first = migrations_run_pending($db, $dir, $logger);
expect_resource($first['success'], 'first run succeeds: ' . ($first['error'] ?? ''));
expect_resource(count($first['applied']) === $first['available'] && $first['available'] > 0,
    'first run applied all ' . $first['available'] . ' migrations');
expect_resource(replay_has_column($link, 'status_sample', 'resource_json'), 'status_sample.resource_json exists');
expect_resource(replay_has_column($link, 'game_sample', 'cpu_gru'), 'game_sample.cpu_gru exists');
expect_resource(replay_has_column($link, 'game_day', 'main_thread_gru_max'), 'game_day.main_thread_gru_max exists');
foreach (['placement_host', 'placement_profile', 'placement_hold', 'agent_cpu_score'] as $table) {
    expect_resource(in_array($table, replay_tables($link), true), "table $table exists");
}

$second = migrations_run_pending($db, $dir, $logger);
expect_resource($second['success'] && $second['applied'] === [] && count($second['skipped']) === $second['available'],
    'second run skips every migration and changes nothing');

// Crash recovery: forget the record of 0017 and 0018, as if the process died after the
// ALTERs but before the schema_migrations insert. Identical columns must be accepted.
$applied_ids = array_values(array_intersect(['0017_hardware_neutral_game_usage', '0018_resource_placement'], $first['applied']));
foreach ($applied_ids as $id) {
    $link->query("DELETE FROM schema_migrations WHERE migration_id='$id'");
}
$third = migrations_run_pending($db, $dir, $logger);
expect_resource($third['success'] && $third['applied'] === $applied_ids,
    'unrecorded 0017/0018 are finished when every column is identical: ' . ($third['error'] ?? ''));

// A column whose definition differs must stop the migration and record nothing.
if (in_array('0018_resource_placement', $applied_ids, true)) {
    $link->query("ALTER TABLE status_sample MODIFY resource_json TEXT NULL");
    $link->query("DELETE FROM schema_migrations WHERE migration_id='0018_resource_placement'");
    $fourth = migrations_run_pending($db, $dir, $logger);
    expect_resource(!$fourth['success'] && $fourth['failed_migration'] === '0018_resource_placement',
        'a different existing column definition fails closed');
    $recorded = $link->query("SELECT 1 FROM schema_migrations WHERE migration_id='0018_resource_placement'")->num_rows;
    expect_resource($recorded === 0, 'the conflicting migration is not recorded');
}
echo "Migration replay passed on real MySQL\n";
