<?php
// Standalone test: php SubdomainLibTest.php
// Needs a scratch MySQL database: SD_TEST_HOST (default localhost), SD_TEST_USER, SD_TEST_PASS, SD_TEST_DB.
require_once __DIR__ . '/../includes/subdomain_lib.php';

function sdAssert($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

// --- name validation (no database needed) ---
sdAssert('', sd_validate_name('cool-server1'), 'good name');
sdAssert(true, sd_validate_name('ab') !== '', 'too short');
sdAssert(true, sd_validate_name(str_repeat('a', 33)) !== '', 'too long');
sdAssert(true, sd_validate_name('Cool') !== '', 'uppercase refused');
sdAssert(true, sd_validate_name('-cool') !== '', 'leading hyphen');
sdAssert(true, sd_validate_name('cool-') !== '', 'trailing hyphen');
sdAssert(true, sd_validate_name('co--ol') !== '', 'double hyphen');
sdAssert(true, sd_validate_name('co_ol') !== '', 'underscore');
sdAssert(true, sd_validate_name('cool.server') !== '', 'dot');
sdAssert(true, sd_validate_name('mail') !== '', 'reserved');
sdAssert(true, sd_validate_name('www') !== '', 'reserved www');
sdAssert(true, sd_validate_name('my-shit-box') !== '', 'blocked word');

$settings = sd_setting_defaults();
sdAssert(array('iaregamer.com'), sd_domains($settings), 'default domain');
$settings['domains'] = 'iaregamer.com, getthegame.info, bad domain';
sdAssert(array('iaregamer.com', 'getthegame.info'), sd_domains($settings), 'domain list is cleaned');
$settings['domains'] = 'iaregamer.com';
sdAssert(true, sd_wants_srv($settings, 'minecraft'), 'minecraft gets SRV');
sdAssert(false, sd_wants_srv($settings, 'cs2'), 'cs2 has no SRV');

$user = getenv('SD_TEST_USER');
if ($user === false || $user === '') {
    echo "SKIP database tests (SD_TEST_USER not set)\n";
    echo "OK\n";
    exit(0);
}

class FakeProvider
{
    public $existing = array();   // "name.domain" => true
    public $calls = array();
    public $fail = false;
    public function name_exists($domain, $name)
    {
        if ($this->fail) {
            throw new Exception('down');
        }
        return isset($this->existing[$name . '.' . $domain]);
    }
    public function replace_name($domain, $name, $ip, $srv_port, $ttl)
    {
        if ($this->fail) {
            throw new Exception('down');
        }
        $this->calls[] = array('replace', $domain, $name, $ip, $srv_port, $ttl);
    }
    public function remove_name($domain, $name)
    {
        if ($this->fail) {
            throw new Exception('down');
        }
        $this->calls[] = array('remove', $domain, $name);
    }
}

// Same method names as the panel database wrapper, over mysqli.
class PanelWrapperStub
{
    public $m;
    public function __construct($m) { $this->m = $m; }
    public function resultQuery($sql)
    {
        $r = $this->m->query($sql);
        if (!$r || $r === true) { return false; }
        $out = array();
        while ($row = $r->fetch_assoc()) { $out[] = $row; }
        return $out ? $out : false;
    }
    public function query($sql) { return $this->m->query($sql); }
    public function realEscapeSingle($s) { return $this->m->real_escape_string($s); }
}

function runDbSuite($sdb, $label)
{
    $settings = sd_setting_defaults();
    $settings['enabled'] = '1';
    $settings['cpanel_host'] = 'x';
    $settings['cpanel_user'] = 'x';
    $settings['cpanel_token'] = 'x';
    sd_save_setting($sdb, 'cooldown', '300');
    $settings = array_merge($settings, sd_settings($sdb), array('enabled' => '1', 'cpanel_host' => 'x', 'cpanel_user' => 'x', 'cpanel_token' => 'x'));
    sdAssert(true, sd_is_ready($settings), "$label ready");

    $p = new FakeProvider();
    $p->existing['taken.iaregamer.com'] = true;

    list($ok, ) = sd_check_available($sdb, $p, $settings, 'fresh-name', 'iaregamer.com');
    sdAssert(true, $ok, "$label free name is available");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'fresh-name', 'other.com');
    sdAssert(false, $ok, "$label unlisted domain refused");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'taken', 'iaregamer.com');
    sdAssert(false, $ok, "$label name already in DNS refused");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'mail', 'iaregamer.com');
    sdAssert(false, $ok, "$label reserved refused");
    $p->fail = true;
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'fresh-name', 'iaregamer.com');
    sdAssert(false, $ok, "$label DNS outage means not available");
    $p->fail = false;

    // reservation at checkout
    sdAssert(true, sd_reserve($sdb, 'mine-now', 'iaregamer.com', 501, 7), "$label reserve");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'mine-now', 'iaregamer.com');
    sdAssert(false, $ok, "$label reserved name is taken for others");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'mine-now', 'iaregamer.com', 0, 501);
    sdAssert(true, $ok, "$label reserved name is fine for its own invoice");
    sdAssert(false, sd_reserve($sdb, 'mine-now', 'iaregamer.com', 502, 8), "$label duplicate reserve refused");

    // activation after provisioning, Minecraft gets SRV
    list($ok, ) = sd_activate_invoice($sdb, $p, $settings, 501, 9001, '203.0.113.5', 25565, 'minecraft');
    sdAssert(true, $ok, "$label activate");
    $row = sd_row_for_home($sdb, 9001);
    sdAssert('active', $row['status'], "$label row active");
    sdAssert('203.0.113.5', $row['ip'], "$label row ip");
    sdAssert(array('replace', 'iaregamer.com', 'mine-now', '203.0.113.5', 25565, 300), $p->calls[0], "$label DNS call with SRV");

    // another game: no SRV
    sd_reserve($sdb, 'cs-box', 'iaregamer.com', 503, 7);
    $p->calls = array();
    sd_activate_invoice($sdb, $p, $settings, 503, 9002, '203.0.113.6', 27015, 'cs2');
    sdAssert(0, $p->calls[0][4], "$label no SRV for cs2");

    // DNS down during activation keeps the row for retry
    sd_reserve($sdb, 'late-dns', 'iaregamer.com', 504, 7);
    $p->fail = true;
    list($ok, ) = sd_activate_invoice($sdb, $p, $settings, 504, 9003, '203.0.113.7', 25565, 'minecraft');
    sdAssert(false, $ok, "$label failed DNS reported");
    sdAssert('pending_dns', sd_row_for_home($sdb, 9003)['status'], "$label row kept as pending_dns");
    $p->fail = false;
    sdAssert(true, sd_resync_if_changed($sdb, $p, $settings, 9003, '203.0.113.7', 25565, 'minecraft'), "$label retry works");
    sdAssert('active', sd_row_for_home($sdb, 9003)['status'], "$label retried row active");

    // server moved: resync follows the new ip and port
    $p->calls = array();
    sd_resync_if_changed($sdb, $p, $settings, 9001, '203.0.113.99', 25570, 'minecraft');
    sdAssert(array('replace', 'iaregamer.com', 'mine-now', '203.0.113.99', 25570, 300), $p->calls[0], "$label resync after move");
    $p->calls = array();
    sd_resync_if_changed($sdb, $p, $settings, 9001, '203.0.113.99', 25570, 'minecraft');
    sdAssert(array(), $p->calls, "$label no DNS call when nothing changed");

    // change name: cooldown, then allowed
    list($ok, $msg) = sd_set_for_home($sdb, $p, $settings, 9001, 7, 'new-name', 'iaregamer.com', '203.0.113.99', 25570, 'minecraft', false);
    sdAssert(true, $ok, "$label first change allowed (changed_at=0 after checkout)");
    list($ok, ) = sd_set_for_home($sdb, $p, $settings, 9001, 7, 'newer-name', 'iaregamer.com', '203.0.113.99', 25570, 'minecraft', false);
    sdAssert(false, $ok, "$label second change blocked by cooldown");
    list($ok, ) = sd_set_for_home($sdb, $p, $settings, 9001, 7, 'newer-name', 'iaregamer.com', '203.0.113.99', 25570, 'minecraft', true);
    sdAssert(true, $ok, "$label admin skips cooldown");
    sdAssert('newer-name', sd_row_for_home($sdb, 9001)['name'], "$label name changed");
    $removed = false;
    foreach ($p->calls as $c) { if ($c[0] === 'remove' && $c[2] === 'new-name') { $removed = true; } }
    sdAssert(true, $removed, "$label old DNS name removed on change");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'mine-now', 'iaregamer.com');
    sdAssert(true, $ok, "$label old name is free again");

    // someone else's name cannot be taken
    list($ok, ) = sd_set_for_home($sdb, $p, $settings, 9002, 7, 'newer-name', 'iaregamer.com', '203.0.113.6', 27015, 'cs2', true);
    sdAssert(false, $ok, "$label cannot take another server's name");

    // new name on a server that has none
    list($ok, ) = sd_set_for_home($sdb, $p, $settings, 9010, 7, 'brand-new', 'iaregamer.com', '203.0.113.20', 25565, 'minecraft', false);
    sdAssert(true, $ok, "$label first name on a server without one");

    // release on delete
    $p->calls = array();
    sdAssert(true, sd_release_home($sdb, $p, 9001), "$label release");
    sdAssert(null, sd_row_for_home($sdb, 9001), "$label row gone after release");
    sdAssert('remove', $p->calls[0][0], "$label DNS removed on release");

    // stale reservations expire
    sd_reserve($sdb, 'stale-one', 'iaregamer.com', 600, 7);
    $sdb->exec('UPDATE ' . $sdb->t('subdomains') . ' SET created=' . (time() - 90000) . " WHERE name='stale-one'");
    list($ok, ) = sd_check_available($sdb, $p, $settings, 'stale-one', 'iaregamer.com');
    sdAssert(true, $ok, "$label stale reservation freed");
    sd_release_invoice($sdb, 999);

    echo "PASS $label\n";
}

$host = getenv('SD_TEST_HOST') ?: 'localhost';
$m = new mysqli($host, $user, getenv('SD_TEST_PASS'), getenv('SD_TEST_DB'));
if ($m->connect_errno) {
    throw new RuntimeException('connect failed: ' . $m->connect_error);
}
define('DB_PREFIX', 'sdt_');
$install_queries = array();
require __DIR__ . '/../module.php';
foreach ($install_queries[0] as $q) {
    if (!$m->query($q)) {
        throw new RuntimeException('install query failed: ' . $m->error);
    }
}
runDbSuite(new SdDb($m, 'sdt_'), 'raw mysqli');
$m->query('TRUNCATE TABLE `sdt_subdomains`');
runDbSuite(new SdDb(new PanelWrapperStub($m), 'sdt_'), 'panel wrapper');
echo "OK\n";
