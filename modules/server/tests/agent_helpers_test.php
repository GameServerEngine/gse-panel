<?php

require_once dirname(__DIR__) . '/agent_helpers.php';

function agent_helpers_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

// --- agent_sanitize_ids: used to turn the Remote Servers page's
// checkbox selection into the ordered list the batch updater walks ---
agent_helpers_assert(
    agent_sanitize_ids(array('3', '1', '2')) === array(3, 1, 2),
    'sanitize_ids preserves submission order, not numeric order'
);
agent_helpers_assert(
    agent_sanitize_ids(array('5', '5', '5')) === array(5),
    'sanitize_ids dedupes repeated ids (e.g. a double form submit)'
);
agent_helpers_assert(
    agent_sanitize_ids(array('0', '-1', 'abc', '', '7')) === array(7),
    'sanitize_ids drops zero, negative, and non-numeric values'
);
agent_helpers_assert(
    agent_sanitize_ids(null) === array(),
    'sanitize_ids returns an empty array when nothing was posted (select-all unchecked, none selected)'
);
agent_helpers_assert(
    agent_sanitize_ids('not-an-array') === array(),
    'sanitize_ids returns an empty array for a non-array value rather than erroring'
);

// --- agent_component_from_os: used to route the update payload to the right agent build ---
agent_helpers_assert(agent_component_from_os('Windows Server 2022') === 'windows_agent', 'Windows OS string routes to windows_agent');
agent_helpers_assert(agent_component_from_os('CYGWIN_NT-10.0') === 'windows_agent', 'Cygwin OS string routes to windows_agent');
agent_helpers_assert(agent_component_from_os('Ubuntu 24.04') === 'linux_agent', 'Linux OS string routes to linux_agent');
agent_helpers_assert(agent_component_from_os('') === 'linux_agent', 'unknown/empty OS defaults to linux_agent');

echo "All agent helpers tests passed.\n";

// --- agent_row_server_id / agent_refresh_snapshot: a row without remote_server_id must not warn ---
agent_helpers_assert(agent_row_server_id(array('remote_server_id' => '12')) === 12, 'row_server_id reads the id when the key exists');
agent_helpers_assert(agent_row_server_id(array('agent_ip' => '1.2.3.4')) === 0, 'row_server_id returns 0 when the key is missing');
agent_helpers_assert(agent_row_server_id(null) === 0, 'row_server_id returns 0 for a non-array row');
$missing_id = agent_refresh_snapshot(new stdClass(), array('agent_ip' => '1.2.3.4', 'agent_port' => 12679));
agent_helpers_assert(empty($missing_id['success']) && !empty($missing_id['error']), 'refresh_snapshot returns an error (no warning) when remote_server_id is missing');
