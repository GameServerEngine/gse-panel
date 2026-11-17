#!/usr/bin/env bash
# Runs every test added for the Utilities modernization. Exit code 0 = all passed.
# Usage: tests/run_diagnostics_tests.sh   (from the panel root; needs php, the xmlrpc extension for the wire tests)
set -u
cd "$(dirname "$0")/.." || exit 1
failed=0
for test in \
	includes/tests/diag_a2s_test.php \
	includes/tests/diag_a2s_udp_integration_test.php \
	includes/tests/diag_policy_test.php \
	includes/tests/diag_runner_test.php \
	includes/tests/diag_contract_test.php \
	includes/tests/diag_log_analyzer_test.php \
	includes/tests/diag_agent_integration_test.php \
	modules/util/tests/util_pages_test.php \
	modules/litefm/tests/analyze_log_page_test.php \
	modules/administration/tests/resource_calc_test.php \
	includes/tests/lib_remote_fault_test.php
do
	if out=$(php "$test" 2>&1); then
		echo "ok    $test ($(grep -c '^PASS' <<<"$out") checks)"
	else
		echo "FAIL  $test"
		grep -m3 'FAIL' <<<"$out"
		failed=1
	fi
done
exit $failed
