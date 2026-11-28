<?php
// Shared fixture for the placement tests that run on a real MySQL server.
//
// Connection comes from the environment (the password is never printed):
//   GSE_PLACEMENT_TEST_SOCKET   unix socket path, or
//   GSE_PLACEMENT_TEST_HOST / GSE_PLACEMENT_TEST_PORT   TCP address
//   GSE_PLACEMENT_TEST_USER     default root
//   GSE_PLACEMENT_TEST_PASSWORD default empty
//   GSE_PLACEMENT_TEST_DB       must start with placement_test or gse_resource_validation
//
// Every table is a normal InnoDB table named <unique prefix><name>. MySQL cannot
// reopen a TEMPORARY table twice in one query, and the placement SQL joins
// billing_locations to itself, so temporary tables cannot be used here.
// Cleanup drops only the tables this run created, found by its own prefix.

require_once __DIR__ . '/../../../tests/resource_model_test.php';

function placement_test_wanted(): bool
{
    return getenv('GSE_PLACEMENT_TEST_SOCKET') !== false || getenv('GSE_PLACEMENT_TEST_HOST') !== false;
}

function placement_test_connect(): mysqli
{
    $dbName = getenv('GSE_PLACEMENT_TEST_DB') ?: 'placement_test';
    if (!preg_match('/^(placement_test|gse_resource_validation)[A-Za-z0-9_]*$/', $dbName)) {
        fwrite(STDERR, "Refusing database '$dbName': the name must start with placement_test or gse_resource_validation\n");
        exit(2);
    }
    $socket = getenv('GSE_PLACEMENT_TEST_SOCKET') ?: null;
    $host = $socket ? 'localhost' : (getenv('GSE_PLACEMENT_TEST_HOST') ?: '127.0.0.1');
    $port = (int)(getenv('GSE_PLACEMENT_TEST_PORT') ?: 3306);
    $db = new mysqli($host, getenv('GSE_PLACEMENT_TEST_USER') ?: 'root',
        getenv('GSE_PLACEMENT_TEST_PASSWORD') ?: '', $dbName, $port, $socket);
    $db->set_charset('utf8mb4');
    return $db;
}

function placement_test_definitions(): array
{
    return [
        'remote_servers'=>'remote_server_id INT PRIMARY KEY, remote_server_name VARCHAR(64), location_id INT, server_os VARCHAR(16), enabled INT, install_enabled INT',
        'billing_locations'=>'location_id INT PRIMARY KEY, name VARCHAR(64), kind VARCHAR(10), parent_id INT, enabled INT',
        'catalog_offer'=>'offer_id INT PRIMARY KEY, remote_server_id INT, home_cfg_id INT, enabled INT, slot_min_qty INT, slot_max_qty INT, pricing_mode VARCHAR(16), per_slot_price_monthly INT',
        'catalog_tier'=>'offer_id INT, tier_key VARCHAR(16), slot_count INT, price_monthly INT',
        'config_homes'=>'home_cfg_id INT PRIMARY KEY, game_key VARCHAR(64)',
        'placement_host'=>'remote_server_id INT PRIMARY KEY, purpose VARCHAR(16) NOT NULL DEFAULT \'profiling\', calibration_json LONGTEXT NOT NULL',
        'status_sample'=>'remote_server_id INT, sampled_at INT, resource_json LONGTEXT',
        'placement_profile'=>'offer_id INT PRIMARY KEY, profile_json LONGTEXT, release_build VARCHAR(128), release_checked_at BIGINT',
        'placement_hold'=>'invoice_id INT PRIMARY KEY, remote_server_id INT, offer_id INT, slots INT, expires_at BIGINT, demand_json LONGTEXT, KEY host_expiry (remote_server_id, expires_at)',
        'billing_config'=>'config_id INT, headroom_cpu_pct INT, headroom_ram_pct INT, headroom_disk_pct INT',
        'billing_services'=>'service_id INT, home_cfg_id INT',
    ];
}

// Returns a prefix no other run uses. SQLite has no shared schema, so it keeps a fixed one.
function placement_test_prefix(bool $realMysql): string
{
    return $realMysql ? 'pf' . bin2hex(random_bytes(4)) . '_' : 'pf_';
}

// Drops only this run's tables. The prefix is checked first so a bad value cannot match other tables.
function placement_test_drop(mysqli $db, string $prefix): void
{
    if (!preg_match('/^pf[0-9a-f]{8}_$/', $prefix)) return;
    foreach (array_keys(placement_test_definitions()) as $name) {
        $db->query("DROP TABLE IF EXISTS `{$prefix}{$name}`");
    }
}

function placement_test_create(mysqli $db, string $prefix, bool $realMysql): void
{
    foreach (placement_test_definitions() as $name => $columns) {
        if ($realMysql) {
            $db->query("CREATE TABLE `{$prefix}{$name}` ($columns) ENGINE=InnoDB");
        } else {
            // The SQLite stand-in only needs plain tables in memory.
            $db->query("CREATE TABLE {$prefix}{$name} (" . preg_replace(
                ['/ DEFAULT \'profiling\'/', '/ NOT NULL/', '/, KEY [^)]*\)/', '/LONGTEXT/'], ['', '', '', 'TEXT'], $columns) . ')');
        }
    }
}

function placement_test_json(mysqli $db, array $value): string
{
    $json = json_encode($value);
    return method_exists($db, 'real_escape_string') ? $db->real_escape_string($json) : str_replace("'", "''", $json);
}

// Three machines in two cities. Offers 107 (game3), 108 (game5), 109 (game6) all sell game 10 (cs2).
function placement_test_seed(mysqli $db, string $p, int $now): void
{
    $db->query("INSERT INTO {$p}billing_locations VALUES (1,'Central USA','region',NULL,1),(2,'East USA','region',NULL,1),
        (6,'Kansas City','city',1,1),(4,'NYC','city',2,1),(12,'Empty','city',2,1)");
    $db->query("INSERT INTO {$p}remote_servers VALUES (7,'game3',6,'windows',1,1),(8,'game5',6,'linux',1,1),(9,'game6',4,'linux',1,1)");
    $db->query("INSERT INTO {$p}config_homes VALUES (10,'cs2')");
    $db->query("INSERT INTO {$p}billing_services VALUES (20,10)");
    $db->query("INSERT INTO {$p}billing_config VALUES (1,70,70,85)");
    [$h, $c, $prof] = placement_fixture($now);
    foreach ([7, 8, 9] as $sid) {
        $id = 100 + $sid;
        $db->query("INSERT INTO {$p}catalog_offer VALUES ($id,$sid,10,1,8,64,'per_slot',2)");
        $host = $h;
        if ($sid === 8) $host['disk_available'] = 43 * 1073741824;
        if ($sid === 7) $host['platform'] = 'windows';
        $cal = $c;
        if ($sid === 7) $cal['approved'] = false;
        $db->query("INSERT INTO {$p}placement_host VALUES ($sid,'public','" . placement_test_json($db, $cal) . "')");
        $db->query("INSERT INTO {$p}status_sample VALUES ($sid,$now,'" . placement_test_json($db, $host) . "')");
        $db->query("INSERT INTO {$p}placement_profile VALUES ($id,'" . placement_test_json($db, $prof) . "','730:fixture',$now)");
    }
}

function placement_test_invoice(int $invoiceId, int $slots = 16): array
{
    return ['invoice_id' => $invoiceId, 'service_id' => 20, 'ip' => 9, 'max_players' => $slots,
        'catalog_snapshot' => '{"offer_id":109}'];
}
