<?php
// The real RemoteLibrary::agent_hardware_inventory() with a fake transport (no network).
// Checks what the panel does with each kind of agent answer, including an older agent that
// does not know the call (an XML-RPC fault), which must be "unsupported" and must not be logged.
if (!function_exists('xmlrpc_encode_request')) {
    function xmlrpc_encode_request($method, $params) { return $method; }
}
if (!function_exists('xmlrpc_decode')) {
    function xmlrpc_decode($xml) { return $xml; }
}
if (!function_exists('xmlrpc_is_fault')) {
    function xmlrpc_is_fault($response) { return is_array($response) && isset($response['faultString']); }
}
require_once dirname(__DIR__) . '/lib_remote.php';
require_once dirname(__DIR__) . '/agent_inventory.php';

class InventoryFakeRemote extends RemoteLibrary
{
    private $reply;
    public function __construct($reply)
    {
        parent::__construct('127.0.0.1', 12679, 'test-key', 1);
        $this->reply = $reply;
    }
    protected function postRequest($request)
    {
        return $this->reply;
    }
}

$failures = 0;
function inv_remote_check($ok, $name)
{
    global $failures;
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

// Send errors to a file so "not logged" can be checked.
$logFile = tempnam(sys_get_temp_dir(), 'invlog');
ini_set('error_log', $logFile);

function state_for($reply)
{
    $remote = new InventoryFakeRemote($reply);
    return agent_inventory_from_reply($remote->agent_hardware_inventory());
}
function agent_value($v) { return base64_encode((string)$v); }   // the agent base64-encodes every value

$inventory = json_encode(array('schema_version' => 1, 'platform' => 'windows', 'cpu' => array('logical_cpus_allowed' => 8)));

// 1. An older agent answers an unknown method with a fault: unsupported, and nothing is logged.
$r = state_for(array('faultCode' => 1, 'faultString' => 'Unknown method: agent_hardware_inventory'));
inv_remote_check($r['state'] === 'unsupported', 'older agent (XML-RPC fault) is unsupported, not an error');
clearstatcache(true, $logFile);
inv_remote_check(filesize($logFile) === 0, 'an unsupported agent writes nothing to the error log');

// 2. A current agent answers with base64 values: parsed into an inventory.
$r = state_for(array('success' => agent_value('1'), 'status' => agent_value('ok'), 'inventory_schema' => agent_value('1'), 'inventory_json' => agent_value($inventory)));
inv_remote_check($r['state'] === 'ok' && $r['inventory']['platform'] === 'windows' && $r['inventory']['cpu']['logical_cpus_allowed'] === 8, 'current agent reply decodes to an inventory');

// 3. Unreachable agent (no reply): an error, so it is retried soon.
inv_remote_check(state_for(null)['state'] === 'error', 'no reply is an error');

// 4. The agent refused (wrong key) or failed: an error carrying the agent's message.
$r = state_for(array('success' => agent_value('0'), 'status' => agent_value('bad_encryption'), 'message' => agent_value('Bad Encryption Key')));
inv_remote_check($r['state'] === 'error' && $r['message'] === 'Bad Encryption Key', 'agent refusal is an error with its message');
$r = state_for(array('success' => agent_value('0'), 'status' => agent_value('error'), 'message' => agent_value('Hardware inventory failed: powershell failed (exit 1)')));
inv_remote_check($r['state'] === 'error' && strpos($r['message'], 'powershell failed') !== false, 'agent failure keeps the reason');

// 5. A reply from the future (unknown schema) is an error, not a silent success.
$r = state_for(array('success' => agent_value('1'), 'status' => agent_value('ok'), 'inventory_json' => agent_value('{"schema_version":2,"platform":"linux"}')));
inv_remote_check($r['state'] === 'error', 'unknown inventory schema is an error');

@unlink($logFile);
echo $failures ? "\n$failures FAILED\n" : "\nall agent inventory remote tests passed\n";
exit($failures ? 1 : 0);
