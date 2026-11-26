<?php
// Tests for cron_split_job(): normal jobs are split into six parts, every other kind of crontab
// line is skipped. Run with: php modules/cron/tests/cron_split_job_test.php

require_once(dirname(__DIR__) . '/shared_cron_functions.php');

function cron_split_test_assert($condition, $message)
{
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$job = cron_split_job('30 4 * * 1 wget -qO- "http://x/api.php?gamemanager/stop" > /dev/null 2>&1');
cron_split_test_assert(is_array($job) && count($job) === 6, 'a normal job gives six parts');
cron_split_test_assert($job[0] === '30' && $job[4] === '1', 'time fields are split in order');
cron_split_test_assert(strpos($job[5], 'wget -qO-') === 0 && substr($job[5], -16) === '> /dev/null 2>&1', 'the rest of the line stays together as the command');

$job = cron_split_job('*/5  *  * * *   echo hi');
cron_split_test_assert($job[0] === '*/5' && $job[5] === 'echo hi', 'extra spaces between fields are fine');

cron_split_test_assert(cron_split_job('') === false, 'blank line is skipped');
cron_split_test_assert(cron_split_job('# a comment with six or more words in it') === false, 'comment is skipped');
cron_split_test_assert(cron_split_job('MAILTO=root') === false, 'NAME=value line is skipped');
cron_split_test_assert(cron_split_job('@reboot /usr/bin/something') === false, '@reboot line is skipped');
cron_split_test_assert(cron_split_job('0 3 * * *') === false, 'a line with no command is skipped');
cron_split_test_assert(cron_split_job(null) === false, 'null is skipped');

echo "All cron_split_job tests passed.\n";
