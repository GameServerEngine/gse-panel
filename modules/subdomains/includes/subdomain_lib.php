<?php
/*
 * Free per-server subdomains (name.iaregamer.com) managed through the cPanel DNS API.
 * Works with both database interfaces used by the panel: the panel wrapper
 * (resultQuery/query/realEscapeSingle) and the storefront raw mysqli handle.
 */

class SdDb
{
    public $h;
    public $prefix;

    public function __construct($handle, $prefix)
    {
        $this->h = $handle;
        $this->prefix = $prefix;
    }

    public function t($table)
    {
        return '`' . $this->prefix . $table . '`';
    }

    public function esc($value)
    {
        if ($this->h instanceof mysqli) {
            return $this->h->real_escape_string((string)$value);
        }
        return $this->h->realEscapeSingle((string)$value);
    }

    public function rows($sql)
    {
        if ($this->h instanceof mysqli) {
            try {
                $res = $this->h->query($sql);
            } catch (mysqli_sql_exception $e) {
                return array();
            }
            if (!$res || $res === true) {
                return array();
            }
            $out = array();
            while ($row = $res->fetch_assoc()) {
                $out[] = $row;
            }
            return $out;
        }
        $res = $this->h->resultQuery($sql);
        return is_array($res) ? $res : array();
    }

    public function exec($sql)
    {
        try {
            return (bool)$this->h->query($sql);
        } catch (mysqli_sql_exception $e) {
            return false;
        }
    }

    public function insertId()
    {
        if ($this->h instanceof mysqli) {
            return (int)$this->h->insert_id;
        }
        $r = $this->rows('SELECT LAST_INSERT_ID() AS id');
        return $r ? (int)$r[0]['id'] : 0;
    }
}

function sd_setting_defaults()
{
    return array(
        'enabled' => '0',
        'cpanel_host' => '',
        'cpanel_port' => '2083',
        'cpanel_user' => '',
        'cpanel_token' => '',
        'domains' => 'iaregamer.com',
        'ttl' => '300',
        'verify_tls' => '1',
        'srv_games' => 'minecraft,spigotmc',
        'cooldown' => '300',
    );
}

function sd_settings($sdb)
{
    $out = sd_setting_defaults();
    foreach ($sdb->rows('SELECT skey, svalue FROM ' . $sdb->t('subdomain_settings')) as $r) {
        $out[$r['skey']] = $r['svalue'];
    }
    return $out;
}

function sd_save_setting($sdb, $key, $value)
{
    $k = $sdb->esc($key);
    $v = $sdb->esc($value);
    return $sdb->exec('REPLACE INTO ' . $sdb->t('subdomain_settings') . " (skey, svalue) VALUES ('$k', '$v')");
}

function sd_domains($settings)
{
    $out = array();
    foreach (explode(',', strtolower($settings['domains'])) as $d) {
        $d = trim($d);
        if ($d !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d)) {
            $out[] = $d;
        }
    }
    return $out;
}

function sd_is_ready($settings)
{
    return $settings['enabled'] === '1' && $settings['cpanel_host'] !== '' && $settings['cpanel_user'] !== ''
        && $settings['cpanel_token'] !== '' && count(sd_domains($settings)) > 0;
}

function sd_reserved_names()
{
    return array(
        'www', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'sftp', 'ssh', 'mysql', 'sql', 'db',
        'panel', 'admin', 'administrator', 'root', 'api', 'cpanel', 'whm', 'ns', 'ns1', 'ns2', 'ns3', 'dns',
        'mx', 'mx1', 'mx2', 'autodiscover', 'autoconfig', 'billing', 'store', 'shop', 'support', 'help',
        'status', 'monitor', 'node', 'core', 'devops', 'gitlab', 'dr', 'vpn', 'wg', 'test', 'dev', 'staging',
        'localhost', 'gameservers', 'isaserver', 'iaregamer', 'abuse', 'postmaster', 'hostmaster', 'webmaster',
        'security', 'ntfy', 'cdn', 'static', 'assets', 'blog', 'docs', 'wiki', 'forum', 'login', 'account',
    );
}

function sd_blocked_words()
{
    return array('fuck', 'shit', 'cunt', 'nigg', 'fag', 'rape', 'nazi', 'hitler', 'porn', 'sex', 'paypal', 'google', 'microsoft');
}

// Returns an error message, or '' when the name is acceptable.
function sd_validate_name($name)
{
    if ($name !== strtolower($name)) {
        return 'Use lowercase letters only.';
    }
    $len = strlen($name);
    if ($len < 3 || $len > 32) {
        return 'The name must be 3 to 32 characters.';
    }
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        return 'Use only letters, numbers and hyphens.';
    }
    if ($name[0] === '-' || $name[$len - 1] === '-') {
        return 'The name cannot start or end with a hyphen.';
    }
    if (strpos($name, '--') !== false) {
        return 'The name cannot contain two hyphens in a row.';
    }
    if (in_array($name, sd_reserved_names(), true)) {
        return 'That name is reserved.';
    }
    foreach (sd_blocked_words() as $w) {
        if (strpos($name, $w) !== false) {
            return 'That name is not allowed.';
        }
    }
    return '';
}

function sd_clean_name($name)
{
    return strtolower(trim((string)$name));
}

function sd_expire_reservations($sdb, $now = null)
{
    $now = $now === null ? time() : (int)$now;
    $cut = $now - 86400;
    $sdb->exec('DELETE FROM ' . $sdb->t('subdomains') . " WHERE status='reserved' AND invoice_id>0 AND created<$cut");
}

function sd_row_for_home($sdb, $home_id)
{
    $r = $sdb->rows('SELECT * FROM ' . $sdb->t('subdomains') . ' WHERE home_id=' . (int)$home_id . " AND status<>'reserved' LIMIT 1");
    return $r ? $r[0] : null;
}

function sd_row_for_invoice($sdb, $invoice_id)
{
    $r = $sdb->rows('SELECT * FROM ' . $sdb->t('subdomains') . ' WHERE invoice_id=' . (int)$invoice_id . " AND status='reserved' LIMIT 1");
    return $r ? $r[0] : null;
}

// Availability: format, domain allowed, not used (DB), not present in the DNS zone.
// $own_home / $own_invoice let a server or invoice keep its own current name.
// Returns array(bool ok, string message).
function sd_check_available($sdb, $provider, $settings, $name, $domain, $own_home = 0, $own_invoice = 0)
{
    $name = sd_clean_name($name);
    $domain = strtolower(trim((string)$domain));
    if (!in_array($domain, sd_domains($settings), true)) {
        return array(false, 'Choose one of the listed domains.');
    }
    $err = sd_validate_name($name);
    if ($err !== '') {
        return array(false, $err);
    }
    sd_expire_reservations($sdb);
    $n = $sdb->esc($name);
    $d = $sdb->esc($domain);
    $rows = $sdb->rows('SELECT home_id, invoice_id FROM ' . $sdb->t('subdomains') . " WHERE name='$n' AND domain='$d' LIMIT 1");
    if ($rows) {
        $mine = ($own_home > 0 && (int)$rows[0]['home_id'] === (int)$own_home)
            || ($own_invoice > 0 && (int)$rows[0]['invoice_id'] === (int)$own_invoice);
        if ($mine) {
            return array(true, 'This is already your name.');
        }
        return array(false, 'That name is already taken.');
    }
    try {
        if ($provider->name_exists($domain, $name)) {
            return array(false, 'That name is already taken.');
        }
    } catch (Exception $e) {
        return array(false, 'Could not check the name right now. Try again in a moment.');
    }
    return array(true, 'Available!');
}

function sd_reserve($sdb, $name, $domain, $invoice_id, $user_id)
{
    $n = $sdb->esc(sd_clean_name($name));
    $d = $sdb->esc(strtolower($domain));
    $now = time();
    $ok = $sdb->exec('INSERT INTO ' . $sdb->t('subdomains')
        . " (home_id, invoice_id, user_id, name, domain, status, ip, port, created, updated, changed_at) VALUES (0, "
        . (int)$invoice_id . ', ' . (int)$user_id . ", '$n', '$d', 'reserved', '', 0, $now, $now, 0)");
    return $ok;
}

function sd_release_invoice($sdb, $invoice_id)
{
    $sdb->exec('DELETE FROM ' . $sdb->t('subdomains') . ' WHERE invoice_id=' . (int)$invoice_id . " AND status='reserved'");
}

function sd_wants_srv($settings, $game_key)
{
    $keys = array();
    foreach (explode(',', strtolower($settings['srv_games'])) as $k) {
        $k = trim($k);
        if ($k !== '') {
            $keys[] = $k;
        }
    }
    return in_array(strtolower((string)$game_key), $keys, true);
}

// Write DNS for a row and update status. Returns array(bool ok, string message).
function sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $game_key)
{
    $srvPort = sd_wants_srv($settings, $game_key) ? (int)$port : 0;
    $now = time();
    $id = (int)$row['id'];
    try {
        $provider->replace_name($row['domain'], $row['name'], $ip, $srvPort, (int)$settings['ttl']);
    } catch (Exception $e) {
        $sdb->exec('UPDATE ' . $sdb->t('subdomains') . " SET status='pending_dns', updated=$now WHERE id=$id");
        return array(false, 'DNS update failed: ' . $e->getMessage());
    }
    $ipEsc = $sdb->esc($ip);
    $sdb->exec('UPDATE ' . $sdb->t('subdomains') . " SET status='active', ip='$ipEsc', port=" . (int)$port . ", updated=$now WHERE id=$id");
    return array(true, 'OK');
}

// Called after a paid order has been provisioned.
function sd_activate_invoice($sdb, $provider, $settings, $invoice_id, $home_id, $ip, $port, $game_key)
{
    $row = sd_row_for_invoice($sdb, $invoice_id);
    if (!$row) {
        return array(true, 'No subdomain on this order.');
    }
    $sdb->exec('UPDATE ' . $sdb->t('subdomains') . ' SET home_id=' . (int)$home_id . ", status='pending_dns', updated=" . time() . ' WHERE id=' . (int)$row['id']);
    $row['home_id'] = (int)$home_id;
    return sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $game_key);
}

// Create or change the name for a server. $is_admin skips the cooldown.
function sd_set_for_home($sdb, $provider, $settings, $home_id, $user_id, $name, $domain, $ip, $port, $game_key, $is_admin)
{
    $name = sd_clean_name($name);
    $domain = strtolower(trim((string)$domain));
    $current = sd_row_for_home($sdb, $home_id);
    if ($current && $current['name'] === $name && $current['domain'] === $domain) {
        return sd_sync_row($sdb, $provider, $settings, $current, $ip, $port, $game_key);
    }
    if ($current && !$is_admin) {
        $wait = (int)$settings['cooldown'] - (time() - (int)$current['changed_at']);
        if ((int)$current['changed_at'] > 0 && $wait > 0) {
            return array(false, 'You can change the name again in ' . ceil($wait / 60) . ' minute(s).');
        }
    }
    list($ok, $msg) = sd_check_available($sdb, $provider, $settings, $name, $domain, $home_id);
    if (!$ok) {
        return array(false, $msg);
    }
    $now = time();
    if ($current) {
        try {
            $provider->remove_name($current['domain'], $current['name']);
        } catch (Exception $e) {
            return array(false, 'Could not remove the old DNS name: ' . $e->getMessage());
        }
        $n = $sdb->esc($name);
        $d = $sdb->esc($domain);
        $sdb->exec('UPDATE ' . $sdb->t('subdomains') . " SET name='$n', domain='$d', status='pending_dns', changed_at=$now, updated=$now WHERE id=" . (int)$current['id']);
        $current['name'] = $name;
        $current['domain'] = $domain;
        return sd_sync_row($sdb, $provider, $settings, $current, $ip, $port, $game_key);
    }
    $n = $sdb->esc($name);
    $d = $sdb->esc($domain);
    $okIns = $sdb->exec('INSERT INTO ' . $sdb->t('subdomains')
        . " (home_id, invoice_id, user_id, name, domain, status, ip, port, created, updated, changed_at) VALUES ("
        . (int)$home_id . ', 0, ' . (int)$user_id . ", '$n', '$d', 'pending_dns', '', 0, $now, $now, $now)");
    if (!$okIns) {
        return array(false, 'That name is already taken.');
    }
    $row = sd_row_for_home($sdb, $home_id);
    return sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $game_key);
}

// Remove the name when the server goes away.
function sd_release_home($sdb, $provider, $home_id)
{
    $row = sd_row_for_home($sdb, $home_id);
    if (!$row) {
        return true;
    }
    try {
        $provider->remove_name($row['domain'], $row['name']);
    } catch (Exception $e) {
        return false;
    }
    $sdb->exec('DELETE FROM ' . $sdb->t('subdomains') . ' WHERE id=' . (int)$row['id']);
    return true;
}

// Keep DNS in step with the server's current ip:port. Returns true when nothing was wrong.
function sd_resync_if_changed($sdb, $provider, $settings, $home_id, $ip, $port, $game_key)
{
    $row = sd_row_for_home($sdb, $home_id);
    if (!$row) {
        return true;
    }
    if ($row['status'] === 'active' && $row['ip'] === $ip && (int)$row['port'] === (int)$port) {
        return true;
    }
    list($ok, ) = sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $game_key);
    return $ok;
}

function sd_fqdn($row)
{
    return $row['name'] . '.' . $row['domain'];
}

// ---------------------------------------------------------------------------
// cPanel UAPI provider
// ---------------------------------------------------------------------------
class SdCpanel
{
    private $host;
    private $port;
    private $user;
    private $token;
    private $verify;
    private $cache = array();

    public function __construct($settings)
    {
        $this->host = $settings['cpanel_host'];
        $this->port = (int)$settings['cpanel_port'];
        $this->user = $settings['cpanel_user'];
        $this->token = $settings['cpanel_token'];
        $this->verify = $settings['verify_tls'] === '1';
    }

    private function call($func, $params)
    {
        $url = 'https://' . $this->host . ':' . $this->port . '/execute/DNS/' . $func . '?' . http_build_query($params);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: cpanel ' . $this->user . ':' . $this->token));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verify ? 2 : 0);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new Exception('cPanel unreachable: ' . $err);
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new Exception('cPanel returned an unreadable answer.');
        }
        if (empty($json['status'])) {
            $e = isset($json['errors'][0]) ? $json['errors'][0] : 'unknown error';
            throw new Exception('cPanel: ' . $e);
        }
        return $json;
    }

    public function zone($domain, $fresh = false)
    {
        if (!$fresh && isset($this->cache[$domain])) {
            return $this->cache[$domain];
        }
        $json = $this->call('parse_zone', array('zone' => $domain));
        $records = array();
        $serial = 0;
        foreach ($json['data'] as $r) {
            if (!isset($r['type']) || $r['type'] !== 'record') {
                continue;
            }
            $dname = base64_decode($r['dname_b64']);
            $data = array();
            foreach ($r['data_b64'] as $d) {
                $data[] = base64_decode($d);
            }
            if ($r['record_type'] === 'SOA') {
                $serial = isset($data[2]) ? $data[2] : 0;
            }
            $records[] = array('line' => (int)$r['line_index'], 'name' => $dname, 'type' => $r['record_type'], 'data' => $data);
        }
        $this->cache[$domain] = array('serial' => $serial, 'records' => $records);
        return $this->cache[$domain];
    }

    private function relative($domain, $dname)
    {
        $dname = rtrim(strtolower($dname), '.');
        if ($dname === $domain) {
            return '@';
        }
        $suffix = '.' . $domain;
        if (substr($dname, -strlen($suffix)) === $suffix) {
            return substr($dname, 0, -strlen($suffix));
        }
        return $dname;
    }

    private function our_names($name)
    {
        return array($name, '_minecraft._tcp.' . $name);
    }

    public function name_exists($domain, $name)
    {
        $z = $this->zone($domain, true);
        foreach ($z['records'] as $r) {
            $rel = $this->relative($domain, $r['name']);
            if ($rel === $name || $rel === '_minecraft._tcp.' . $name) {
                return true;
            }
            if (substr($rel, -strlen('.' . $name)) === '.' . $name) {
                return true; // something below the name exists
            }
        }
        return false;
    }

    private function edit($domain, $remove_lines, $adds)
    {
        $z = $this->zone($domain, true);
        $params = array('zone' => $domain, 'serial' => $z['serial']);
        $i = 1;
        foreach ($remove_lines as $line) {
            $params['remove-' . $i++] = $line;
        }
        $i = 1;
        foreach ($adds as $a) {
            $params['add-' . $i++] = json_encode($a);
        }
        $this->call('mass_edit_zone', $params);
        unset($this->cache[$domain]);
    }

    private function lines_for($domain, $name)
    {
        $z = $this->zone($domain, true);
        $lines = array();
        foreach ($z['records'] as $r) {
            $rel = $this->relative($domain, $r['name']);
            if (in_array($rel, $this->our_names($name), true) && in_array($r['type'], array('A', 'AAAA', 'CNAME', 'SRV'), true)) {
                $lines[] = $r['line'];
            }
        }
        return $lines;
    }

    public function replace_name($domain, $name, $ip, $srv_port, $ttl)
    {
        $adds = array(array('dname' => $name, 'ttl' => $ttl, 'record_type' => 'A', 'data' => array($ip)));
        if ($srv_port > 0) {
            $adds[] = array(
                'dname' => '_minecraft._tcp.' . $name, 'ttl' => $ttl, 'record_type' => 'SRV',
                'data' => array('0', '5', (string)$srv_port, $name . '.' . $domain . '.'),
            );
        }
        $this->edit($domain, $this->lines_for($domain, $name), $adds);
    }

    public function remove_name($domain, $name)
    {
        $lines = $this->lines_for($domain, $name);
        if ($lines) {
            $this->edit($domain, $lines, array());
        }
    }
}

// Resolve the first ip:port of a game home through the panel database.
function sd_home_address($sdb, $home_id)
{
    $r = $sdb->rows('SELECT i.ip AS ip, p.port AS port FROM ' . $sdb->t('home_ip_ports') . ' p JOIN '
        . $sdb->t('remote_server_ips') . ' i ON i.ip_id = p.ip_id WHERE p.home_id=' . (int)$home_id . ' ORDER BY p.port ASC LIMIT 1');
    return $r ? array($r[0]['ip'], (int)$r[0]['port']) : array('', 0);
}

function sd_home_game_key($sdb, $home_id)
{
    $r = $sdb->rows('SELECT c.game_key AS game_key FROM ' . $sdb->t('server_homes') . ' h JOIN '
        . $sdb->t('config_homes') . ' c ON c.home_cfg_id = h.home_cfg_id WHERE h.home_id=' . (int)$home_id . ' LIMIT 1');
    return $r ? $r[0]['game_key'] : '';
}

function sd_game_key_for_cfg($sdb, $home_cfg_id)
{
    $r = $sdb->rows('SELECT game_key FROM ' . $sdb->t('config_homes') . ' WHERE home_cfg_id=' . (int)$home_cfg_id . ' LIMIT 1');
    return $r ? $r[0]['game_key'] : '';
}

// Small script that checks a name while the person types (debounced).
// $url is the check endpoint without name/domain, e.g. '?m=subdomains&p=check&type=cleared&home_id=5'.
function sd_live_check_js($url, $nameId, $domainId, $statusId)
{
    $u = json_encode($url);
    return "<script>(function(){var n=document.getElementById('$nameId'),d=document.getElementById('$domainId'),s=document.getElementById('$statusId');"
        . "if(!n||!d||!s)return;var t=null,seq=0;function show(ok,m){s.textContent=m;s.style.color=ok?'#2e9b3a':'#c0392b';n.dataset.sdOk=ok?'1':'0';}"
        . "function run(){var v=n.value.trim().toLowerCase();if(v===''){s.textContent='';n.dataset.sdOk='';return;}"
        . "var my=++seq;s.textContent='Checking...';s.style.color='#888';var x=new XMLHttpRequest();"
        . "x.open('GET',$u+'&name='+encodeURIComponent(v)+'&domain='+encodeURIComponent(d.value));"
        . "x.onload=function(){if(my!==seq)return;try{var j=JSON.parse(x.responseText);show(j.ok,j.message);}catch(e){show(false,'Could not check right now.');}};"
        . "x.onerror=function(){if(my===seq)show(false,'Could not check right now.');};x.send();}"
        . "function later(){clearTimeout(t);t=setTimeout(run,350);}n.addEventListener('input',later);d.addEventListener('change',later);})();</script>";
}

// Used when a game server is deleted. Never throws; does nothing if the module is not set up.
function sd_release_home_safe($dbHandle, $prefix, $home_id)
{
    try {
        $sdb = new SdDb($dbHandle, $prefix);
        if (!$sdb->rows("SHOW TABLES LIKE '" . $sdb->esc($prefix . 'subdomains') . "'")) {
            return;
        }
        $settings = sd_settings($sdb);
        $row = sd_row_for_home($sdb, (int)$home_id);
        if (!$row) {
            return;
        }
        if (sd_is_ready($settings)) {
            sd_release_home($sdb, new SdCpanel($settings), (int)$home_id);
        }
        // If DNS could not be reached the row stays so an admin can see and clean it up.
        // The server is gone, so detach it from the home id to keep it from matching a new server.
        $left = sd_row_for_home($sdb, (int)$home_id);
        if ($left) {
            $sdb->exec('UPDATE ' . $sdb->t('subdomains') . " SET home_id=0, status='orphaned' WHERE id=" . (int)$left['id']);
        }
    } catch (Exception $e) {
        error_log('subdomain release failed: ' . $e->getMessage());
    }
}

// Server address rows for the game monitor's edit page (user_games/edit_home.php).
// Handles its own form post, then prints table rows. Does nothing when the module is not set up.
function sd_render_edit_rows($dbHandle, $prefix, $home_id, $isAdmin, $userId)
{
    try {
        $sdb = new SdDb($dbHandle, $prefix);
        if (!$sdb->rows("SHOW TABLES LIKE '" . $sdb->esc($prefix . 'subdomains') . "'")) {
            return;
        }
        $settings = sd_settings($sdb);
        if (!sd_is_ready($settings)) {
            return;
        }
        $provider = new SdCpanel($settings);
        $home_id = (int)$home_id;
        list($ip, $port) = sd_home_address($sdb, $home_id);
        $gameKey = sd_home_game_key($sdb, $home_id);
        $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
        $msg = '';
        $msgOk = true;
        if (isset($_POST['sd_action'])) {
            if ($ip === '') {
                $msg = 'This server has no IP and port yet.';
                $msgOk = false;
            } elseif ($_POST['sd_action'] === 'set') {
                list($msgOk, $msg) = sd_set_for_home($sdb, $provider, $settings, $home_id, (int)$userId,
                    isset($_POST['sd_name']) ? $_POST['sd_name'] : '', isset($_POST['sd_domain']) ? $_POST['sd_domain'] : '',
                    $ip, $port, $gameKey, $isAdmin);
                if ($msgOk) {
                    $msg = 'Saved. DNS changes can take a few minutes to show up everywhere.';
                }
            } elseif ($_POST['sd_action'] === 'refresh') {
                $r = sd_row_for_home($sdb, $home_id);
                if ($r) {
                    list($msgOk, $msg) = sd_sync_row($sdb, $provider, $settings, $r, $ip, $port, $gameKey);
                    if ($msgOk) {
                        $msg = 'DNS refreshed.';
                    }
                }
            } elseif ($_POST['sd_action'] === 'remove' && $isAdmin) {
                $msgOk = sd_release_home($sdb, $provider, $home_id);
                $msg = $msgOk ? 'Name removed.' : 'Could not remove the DNS records.';
            }
        }
        $row = sd_row_for_home($sdb, $home_id);
        if ($row && $ip !== '' && $msg === '' && ($row['ip'] !== $ip || (int)$row['port'] !== (int)$port || $row['status'] !== 'active')) {
            sd_sync_row($sdb, $provider, $settings, $row, $ip, $port, $gameKey); // server moved or DNS retry
            $row = sd_row_for_home($sdb, $home_id);
        }
        $action = '?m=user_games&p=edit&home_id=' . $home_id;
        echo "<tr><td class='right'>Server address:</td><td class='left'>";
        if ($row) {
            echo "<b>" . $h(sd_fqdn($row)) . "</b>";
            echo sd_wants_srv($settings, $gameKey) ? " (players can join with just this name)" : ($port > 0 ? " (use port " . (int)$port . ")" : '');
            if ($row['status'] !== 'active') {
                echo " <span style='color:#c0392b'>DNS not in place yet, press Refresh DNS.</span>";
            }
            echo "<br>";
        }
        echo "<form action='" . $h($action) . "' method='post'><input type='hidden' name='sd_action' value='set'>";
        echo "<input type='text' size='24' maxlength='32' id='sd_name' name='sd_name' value='" . $h($row ? $row['name'] : '') . "' autocomplete='off' placeholder='my-server'> ";
        echo "<select id='sd_domain' name='sd_domain'>";
        foreach (sd_domains($settings) as $d) {
            echo "<option value='" . $h($d) . "'" . ($row && $row['domain'] === $d ? ' selected' : '') . ">." . $h($d) . "</option>";
        }
        echo "</select> <input type='submit' value='Save address'> <span id='sd_status'></span></form>";
        if ($row) {
            echo "<form action='" . $h($action) . "' method='post' style='display:inline'><input type='hidden' name='sd_action' value='refresh'><input type='submit' value='Refresh DNS'></form> ";
            if ($isAdmin) {
                echo "<form action='" . $h($action) . "' method='post' style='display:inline' onsubmit=\"return confirm('Remove this address?')\"><input type='hidden' name='sd_action' value='remove'><input type='submit' value='Remove'></form>";
            }
        }
        if ($msg !== '') {
            echo "<div style='color:" . ($msgOk ? '#2e9b3a' : '#c0392b') . "'>" . $h($msg) . "</div>";
        }
        echo sd_live_check_js('?m=subdomains&p=check&type=cleared&home_id=' . $home_id, 'sd_name', 'sd_domain', 'sd_status');
        echo "</td></tr>";
        echo "<tr><td colspan='2' class='info'>A free name players can use instead of your IP address. You can change it every few minutes.</td></tr>";
    } catch (Exception $e) {
        error_log('subdomain edit rows failed: ' . $e->getMessage());
    }
}
