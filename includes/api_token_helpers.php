<?php
// API token helpers: 16-character tokens and rate limiting of failed token checks.
// Failure counts are kept per client address in the system temp directory, so no schema change is needed.

if(!defined('API_TOKEN_LENGTH')) define('API_TOKEN_LENGTH', 16);
if(!defined('API_FAIL_LIMIT')) define('API_FAIL_LIMIT', 10);
if(!defined('API_FAIL_WINDOW')) define('API_FAIL_WINDOW', 600);
if(!defined('API_LOCK_SECONDS')) define('API_LOCK_SECONDS', 900);

// Random token of letters and digits only, so it is easy to paste into any command line.
function api_new_token($length = API_TOKEN_LENGTH)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($alphabet) - 1;
    $token = '';
    for($i = 0; $i < $length; $i++)
        $token .= $alphabet[random_int(0, $max)];
    return $token;
}

function api_limit_path()
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api_limit';
    if(!is_dir($dir)) @mkdir($dir, 0700, true);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return $dir . DIRECTORY_SEPARATOR . hash('sha256', $ip) . '.json';
}

function api_limit_decode($raw)
{
    $state = json_decode((string)$raw, true);
    if(!is_array($state)) $state = array('fails' => array(), 'locked_until' => 0);
    return $state;
}

// True while this client is locked out after too many failed token checks.
function api_token_is_locked()
{
    $raw = @file_get_contents(api_limit_path());
    $state = api_limit_decode($raw);
    return ((int)$state['locked_until']) > time();
}

// Records one failed token check. Read and write happen under an exclusive lock so parallel requests are counted.
function api_token_record_failure()
{
    $fh = @fopen(api_limit_path(), 'c+');
    if(!$fh) return;
    flock($fh, LOCK_EX);
    $now = time();
    $state = api_limit_decode(stream_get_contents($fh));
    $state['fails'] = array_values(array_filter((array)$state['fails'], function($t) use ($now) {
        return $t > $now - API_FAIL_WINDOW;
    }));
    $state['fails'][] = $now;
    if(count($state['fails']) >= API_FAIL_LIMIT)
    {
        $state['locked_until'] = $now + API_LOCK_SECONDS;
        $state['fails'] = array();
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    flock($fh, LOCK_UN);
    fclose($fh);
}
