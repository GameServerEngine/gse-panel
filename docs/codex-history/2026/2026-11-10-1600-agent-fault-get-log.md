# Task

Version 2.7.3: starting a server fataled with "explode(): Argument #2 ($string) must be of type string, array given" in lib_remote.php get_log().

## Cause

When the agent's Perl code dies, the XML-RPC reply is a fault. xmlrpc_decode() turns it into an array (faultCode, faultString) and sendRequest() returned it. get_log() and the other parsers then passed the array to explode().

## Change

- remote_reply_is_fault() and remote_reply_fault_text() in includes/lib_remote.php.
- sendRequest() logs a fault (PHP error log and panel logger) and returns NULL, the same as "agent unreachable". All callers already handle NULL, so no parser gets an array.
- get_log() now returns "Agent error: <faultString>" for a fault. Parsing moved to parse_log_reply().
- start_server.php and restart_server.php only treat a numeric return above 0 as a log, so an error string is shown as an error.
- Test: includes/tests/lib_remote_fault_test.php.
