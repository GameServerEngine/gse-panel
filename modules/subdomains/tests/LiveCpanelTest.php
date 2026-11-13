<?php
// Optional live test against a real cPanel zone. Creates and removes ONE temporary test name.
// CPANEL_HOST, CPANEL_PORT, CPANEL_USER, CPANEL_API_TOKEN and SD_TEST_ZONE must be set.
require_once __DIR__ . '/../includes/subdomain_lib.php';

$zone = getenv('SD_TEST_ZONE');
if (!$zone || !getenv('CPANEL_HOST')) {
    echo "SKIP live test (env not set)\n";
    exit(0);
}
$settings = sd_setting_defaults();
$settings['cpanel_host'] = getenv('CPANEL_HOST');
$settings['cpanel_port'] = getenv('CPANEL_PORT') ?: '2083';
$settings['cpanel_user'] = getenv('CPANEL_USER');
$settings['cpanel_token'] = getenv('CPANEL_API_TOKEN');
$p = new SdCpanel($settings);
$name = 'zz-sdtest-' . substr(md5(uniqid('', true)), 0, 6);

function find($p, $zone, $rel, $type)
{
    $z = $p->zone($zone, true);
    foreach ($z['records'] as $r) {
        $n = rtrim($r['name'], '.');
        if (($n === $rel || $n === $rel . '.' . $zone) && $r['type'] === $type) {
            return $r;
        }
    }
    return null;
}

try {
    if ($p->name_exists($zone, $name)) { throw new RuntimeException('unexpected: test name exists'); }
    $p->replace_name($zone, $name, '192.0.2.10', 25565, 300);
    $a = find($p, $zone, $name, 'A');
    $s = find($p, $zone, '_minecraft._tcp.' . $name, 'SRV');
    if (!$a || $a['data'][0] !== '192.0.2.10') { throw new RuntimeException('A record missing'); }
    if (!$s || $s['data'][2] !== '25565' || rtrim($s['data'][3], '.') !== $name . '.' . $zone) { throw new RuntimeException('SRV wrong: ' . json_encode($s)); }
    if (!$p->name_exists($zone, $name)) { throw new RuntimeException('name_exists false after create'); }
    echo "created A + SRV ok\n";
    $p->replace_name($zone, $name, '192.0.2.11', 0, 300);
    $a = find($p, $zone, $name, 'A');
    if (!$a || $a['data'][0] !== '192.0.2.11') { throw new RuntimeException('A not updated'); }
    if (find($p, $zone, '_minecraft._tcp.' . $name, 'SRV')) { throw new RuntimeException('SRV not removed when port 0'); }
    echo "update ok (SRV dropped)\n";
    $p->remove_name($zone, $name);
    if ($p->name_exists($zone, $name)) { throw new RuntimeException('name still exists after remove'); }
    echo "removed ok\nOK\n";
} catch (Exception $e) {
    echo 'FAIL: ' . $e->getMessage() . "\n";
    try { $p->remove_name($zone, $name); echo "cleanup done\n"; } catch (Exception $e2) { echo 'cleanup failed: ' . $e2->getMessage() . "\n"; }
    exit(1);
}
