<?php
// Shared bits for the diagnostics tests. Run any test with: php includes/tests/<name>_test.php
// (exit code 0 = all passed). Nothing here touches a network or a database.

function check($name, $ok)
{
	if (!$ok) {
		fwrite(STDERR, "FAIL: $name\n");
		exit(1);
	}
	echo "PASS: $name\n";
}

// In-memory stand-in for DiagAuditDb with the same methods.
class DiagAuditMemory
{
	public $rows = array();

	public function insert($row)
	{
		$this->rows[] = $row;
		return count($this->rows) - 1;
	}

	public function update($id, $fields)
	{
		$this->rows[$id] = array_merge($this->rows[$id], $fields);
		return true;
	}

	public function count_rows($filter)
	{
		$n = 0;
		foreach ($this->rows as $row) {
			$n += $this->matches($row, $filter) ? 1 : 0;
		}
		return $n;
	}

	private function matches($row, $filter)
	{
		if (isset($filter['user_id']) && $row['user_id'] != $filter['user_id']) {
			return false;
		}
		if (isset($filter['remote_server_id']) && $row['remote_server_id'] != $filter['remote_server_id']) {
			return false;
		}
		if (isset($filter['since']) && $row['created_at'] < $filter['since']) {
			return false;
		}
		return empty($filter['statuses']) || in_array($row['status'], $filter['statuses'], true);
	}
}

// Builds Source A2S_INFO reply bytes for tests.
function test_a2s_info_reply($name = 'My Server', $map = 'de_dust2', $players = 5, $max = 16, $password = 0)
{
	return "\xff\xff\xff\xffI" . chr(17) . $name . "\0" . $map . "\0cstrike\0Counter-Strike\0" . pack('v', 10)
		. chr($players) . chr($max) . chr(0) . 'd' . 'l' . chr($password) . chr(1) . "1.0.0.34\0";
}

function test_a2s_challenge_reply($challenge = "\x01\x02\x03\x04")
{
	return "\xff\xff\xff\xffA" . $challenge;
}
