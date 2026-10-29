<?php

require_once dirname(__DIR__) . '/agent_helpers.php';

function gsp_agent_helpers_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

// --- gsp_agent_sanitize_ids: used to turn the Remote Servers page's
// checkbox selection into the ordered list the batch updater walks ---
gsp_agent_helpers_assert(
    gsp_agent_sanitize_ids(array('3', '1', '2')) === array(3, 1, 2),
    'sanitize_ids preserves submission order, not numeric order'
);
gsp_agent_helpers_assert(
    gsp_agent_sanitize_ids(array('5', '5', '5')) === array(5),
    'sanitize_ids dedupes repeated ids (e.g. a double form submit)'
);
gsp_agent_helpers_assert(
    gsp_agent_sanitize_ids(array('0', '-1', 'abc', '', '7')) === array(7),
    'sanitize_ids drops zero, negative, and non-numeric values'
);
gsp_agent_helpers_assert(
    gsp_agent_sanitize_ids(null) === array(),
    'sanitize_ids returns an empty array when nothing was posted (select-all unchecked, none selected)'
);
gsp_agent_helpers_assert(
    gsp_agent_sanitize_ids('not-an-array') === array(),
    'sanitize_ids returns an empty array for a non-array value rather than erroring'
);

// --- gsp_agent_component_from_os: used to route the update payload to the right agent build ---
gsp_agent_helpers_assert(gsp_agent_component_from_os('Windows Server 2022') === 'windows_agent', 'Windows OS string routes to windows_agent');
gsp_agent_helpers_assert(gsp_agent_component_from_os('CYGWIN_NT-10.0') === 'windows_agent', 'Cygwin OS string routes to windows_agent');
gsp_agent_helpers_assert(gsp_agent_component_from_os('Ubuntu 24.04') === 'linux_agent', 'Linux OS string routes to linux_agent');
gsp_agent_helpers_assert(gsp_agent_component_from_os('') === 'linux_agent', 'unknown/empty OS defaults to linux_agent');

echo "All agent helpers tests passed.\n";
