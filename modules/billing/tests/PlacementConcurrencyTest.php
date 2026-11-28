<?php
// Real-MySQL concurrency check for placement reservations. Needs the
// GSE_PLACEMENT_TEST_* variables (see placement_mysql.inc), the pcntl
// extension and an InnoDB server. Without them it prints SKIP and exits 0,
// because SQLite cannot show row-lock behaviour.
//
// Step 1: count how many 8-slot holds the fixture machine accepts when
//         reservations arrive one after another (N).
// Step 2: clear the holds, then start 2N+4 separate database connections that
//         all try to reserve at the same moment.
// Pass: exactly N reservations succeed, and a later sequential try is refused.
if (getenv('GSE_PLACEMENT_TEST_SOCKET') === false && getenv('GSE_PLACEMENT_TEST_HOST') === false) {
    echo "SKIP: set GSE_PLACEMENT_TEST_SOCKET or GSE_PLACEMENT_TEST_HOST to run on a real MySQL server\n";
    exit(0);
}
if (!function_exists('pcntl_fork')) {
    echo "SKIP: pcntl extension missing\n";
    exit(0);
}
require_once __DIR__ . '/placement_mysql.inc';
require_once __DIR__ . '/../includes/placement_store.php';

$parent = getmypid();
$db = placement_test_connect();
$p = placement_test_prefix(true);
// Children exit() too; only the parent may drop the tables.
register_shutdown_function(function () use ($p, $parent) {
    if (getmypid() === $parent) {
        $cleanup = placement_test_connect();
        placement_test_drop($cleanup, $p);
        $cleanup->close();
    }
});
placement_test_create($db, $p, true);
$now = time();
placement_test_seed($db, $p, $now);
$expires = $now + 900;
$slots = 8;

// Step 1
$accepted = 0;
for ($i = 1; $i <= 200; $i++) {
    if (!placement_reserve_invoices($db, $p, [placement_test_invoice(1000 + $i, $slots)], $expires)) break;
    $accepted++;
}
expect_resource($accepted >= 1 && $accepted < 200, "sequential capacity is $accepted holds of $slots slots");
$db->query("DELETE FROM {$p}placement_hold");

// Close the parent socket before fork: child destructors must not send COM_QUIT on an inherited connection.
$db->close();
// Step 2
$children = $accepted * 2 + 4;
$startAt = microtime(true) + 1.5;
$pids = [];
for ($i = 1; $i <= $children; $i++) {
    $pid = pcntl_fork();
    if ($pid < 0) { fwrite(STDERR, "fork failed\n"); exit(2); }
    if ($pid === 0) {
        // Own connection per child: a connection must never be shared across fork.
        $child = placement_test_connect();
        while (microtime(true) < $startAt) usleep(500);
        $ok = placement_reserve_invoices($child, $p, [placement_test_invoice(2000 + $i, $slots)], $expires);
        exit($ok ? 0 : 1);
    }
    $pids[$pid] = $i;
}
$won = 0;
$lost = 0;
$broken = 0;
foreach ($pids as $pid => $i) {
    pcntl_waitpid($pid, $status);
    $code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
    if ($code === 0) $won++; elseif ($code === 1) $lost++; else $broken++;
}
$db = placement_test_connect();
$rows = (int)$db->query("SELECT COUNT(*) AS n FROM {$p}placement_hold")->fetch_assoc()['n'];
expect_resource($broken === 0, 'every child finished normally');
expect_resource($won === $accepted, "concurrent winners ($won) equal sequential capacity ($accepted); losers $lost");
expect_resource($rows === $accepted, "hold table has $rows rows");
expect_resource(!placement_reserve_invoices($db, $p, [placement_test_invoice(3000, $slots)], $expires), 'full machine still refuses');
echo "Placement concurrency passed on real MySQL ($children connections)\n";
