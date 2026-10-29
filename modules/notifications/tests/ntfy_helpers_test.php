<?php
// Pure-logic tests for ntfy_helpers.php: topic naming, priority mapping, and
// request building. No network calls (ntfy_send_request is not exercised
// here). Run with: php modules/notifications/tests/ntfy_helpers_test.php

require_once(dirname(__DIR__) . '/ntfy_helpers.php');

function ntfy_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
	echo "PASS: {$message}\n";
}

ntfy_test_assert(ntfy_topic_for_home(4) === 'gse-4', 'topic for home 4 is gse-4');
ntfy_test_assert(ntfy_topic_for_home('10') === 'gse-10', 'topic for home id given as a string still normalizes to gse-10');

ntfy_test_assert(ntfy_priority_for_event('crash') === 'high', 'crash is high priority');
ntfy_test_assert(ntfy_priority_for_event('give_up') === 'high', 'give_up is high priority');
ntfy_test_assert(ntfy_priority_for_event('task_failed') === 'high', 'task_failed is high priority');
ntfy_test_assert(ntfy_priority_for_event('start') === 'low', 'start is low priority');
ntfy_test_assert(ntfy_priority_for_event('task_success') === 'low', 'task_success is low priority');
ntfy_test_assert(ntfy_priority_for_event('something_unrecognized') === 'low', 'unknown events default to low, never high, so a typo cannot page someone');

$req = ntfy_build_request('http://127.0.0.1:8080/', 'gse-4', 'Title', 'Body', 'high', array('crash'));
ntfy_test_assert($req['url'] === 'http://127.0.0.1:8080/gse-4', 'trailing slash on base URL is stripped before joining the topic');
ntfy_test_assert($req['method'] === 'POST', 'request method is POST');
ntfy_test_assert(in_array('Priority: high', $req['headers'], true), 'priority header is set from the given priority');
ntfy_test_assert($req['body'] === 'Body', 'body is passed through unchanged');

$req = ntfy_build_request('http://127.0.0.1:8080', 'gse admin topic/weird', 'T', 'B');
ntfy_test_assert(strpos($req['url'], ' ') === false, 'topic names are URL-encoded (no raw spaces in the request URL)');

echo "All ntfy helper tests passed.\n";
