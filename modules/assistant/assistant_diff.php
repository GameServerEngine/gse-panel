<?php
/*
 * Helpers with no database or network, so they can be tested on their own:
 * a unified diff, hiding of secrets, and wrapping of untrusted text for the model.
 */

// Text the model reads but must treat as data goes inside a tag. Anything in the text that
// looks like one of our tags is changed so it cannot close the tag early.
function assistant_wrap($tag, $text)
{
	$text = preg_replace('#<(/?)(log_data|file_data|db_data)#i', '<$1_$2', (string)$text);
	return '<' . $tag . '>' . $text . '</' . $tag . '>';
}

// Hides the value of lines that look like passwords, keys or tokens. Best effort only: the
// customer's own files are never sent anywhere except to the model for this one question.
function assistant_redact($text)
{
	// Any name that contains one of these words: adminPassword, mysql_pass, rcon_password, steamkey, key => ...
	$word = '\w*(?:pass|pwd|rcon|secret|token|key|license|salt|hash)\w*';
	$value = '("[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s,;]+)';
	$text = preg_replace('/(\b' . $word . '"?\s*(?:=>|=|:)\s*)' . $value . '/i', '$1[hidden]', (string)$text);
	// command line style: -password value, +rcon_password value
	$text = $text === null ? null : preg_replace('/([-+]' . $word . '\s+)(\S+)/i', '$1[hidden]', $text);
	// config style with no equals sign, at the start of a line: rcon_password "secret"  /  sv_password foo
	$text = $text === null ? null : preg_replace('/^(\s*' . $word . '\s+)(\S.*)$/im', '$1[hidden]', $text);
	// If the regular expression engine gave up, show nothing rather than the unblanked text.
	return $text === null ? '(unreadable)' : $text;
}

// Files the assistant never reads, whatever the folder.
function assistant_secret_filename($path)
{
	// Folders that hold secrets or repository internals, wherever they are in the path.
	if (preg_match('#(^|/)(secrets?|\.git|\.ssh)(/|$)#i', $path)) {
		return true;
	}
	return (bool)preg_match('#(^|/)([^/]*\.env|\.env[^/]*|\.htpasswd|\.git-credentials|id_(rsa|dsa|ecdsa|ed25519)[^/]*|[^/]*\.(pem|key|p12|pfx|crt|kdbx)|config\.inc\.php[^/]*|shadow-?|gshadow-?|sudoers|authorized_keys|known_hosts)$#i', $path);
}

// Extensions the assistant will not write (binary or archive types).
function assistant_binary_extension($path)
{
	$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
	return in_array($ext, array('exe','dll','so','dylib','bin','dat','pbo','ebo','bik','smx','amxx','mdl','vtf','vtx','vvd','phy','bsp','nav','zip','rar','7z','gz','tar','tgz','jar','class','png','jpg','jpeg','gif','ico','wav','mp3','ogg','db','sqlite','sqlite3','mdb','iso','img'), true);
}

function assistant_looks_binary($data)
{
	return strpos(substr((string)$data, 0, 4096), "\0") !== false;
}

// A unified diff that `git apply` and `patch -p1` accept. $old or $new may be '' for a new or removed file.
// Common lines at the start and end are skipped; the middle is compared line by line (limited size).
function assistant_unified_diff($old, $new, $path, $context = 3)
{
	$a = $old === '' ? array() : preg_split('/\r\n|\n/', rtrim((string)$old, "\r\n"));
	$b = $new === '' ? array() : preg_split('/\r\n|\n/', rtrim((string)$new, "\r\n"));
	$na = count($a);
	$nb = count($b);
	$start = 0;
	while ($start < $na && $start < $nb && $a[$start] === $b[$start]) {
		$start++;
	}
	$end_a = $na;
	$end_b = $nb;
	while ($end_a > $start && $end_b > $start && $a[$end_a - 1] === $b[$end_b - 1]) {
		$end_a--;
		$end_b--;
	}
	if ($start === $na && $start === $nb) {
		return '';
	}
	$ma = array_slice($a, $start, $end_a - $start);
	$mb = array_slice($b, $start, $end_b - $start);

	// Edit script over the changed middle: array of array(op, line) with op ' ', '-', '+'.
	$ops = array();
	if (count($ma) * count($mb) > 1500000) {
		foreach ($ma as $line) {
			$ops[] = array('-', $line);
		}
		foreach ($mb as $line) {
			$ops[] = array('+', $line);
		}
	} else {
		$la = count($ma);
		$lb = count($mb);
		$table = array_fill(0, $la + 1, array_fill(0, $lb + 1, 0));
		for ($i = $la - 1; $i >= 0; $i--) {
			for ($j = $lb - 1; $j >= 0; $j--) {
				$table[$i][$j] = $ma[$i] === $mb[$j] ? $table[$i + 1][$j + 1] + 1 : max($table[$i + 1][$j], $table[$i][$j + 1]);
			}
		}
		$i = 0;
		$j = 0;
		while ($i < $la && $j < $lb) {
			if ($ma[$i] === $mb[$j]) {
				$ops[] = array(' ', $ma[$i]);
				$i++;
				$j++;
			} elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
				$ops[] = array('-', $ma[$i++]);
			} else {
				$ops[] = array('+', $mb[$j++]);
			}
		}
		while ($i < $la) {
			$ops[] = array('-', $ma[$i++]);
		}
		while ($j < $lb) {
			$ops[] = array('+', $mb[$j++]);
		}
	}

	// Put the skipped lines back around the middle so hunks can carry context.
	$all = array();
	for ($k = 0; $k < $start; $k++) {
		$all[] = array(' ', $a[$k]);
	}
	foreach ($ops as $op) {
		$all[] = $op;
	}
	for ($k = $end_a; $k < $na; $k++) {
		$all[] = array(' ', $a[$k]);
	}

	$out = '';
	$count = count($all);
	$line_a = 1;
	$line_b = 1;
	$pos = 0;
	$hunks = array();
	// Find runs of changes and group the ones that are close together.
	$i = 0;
	while ($i < $count) {
		if ($all[$i][0] === ' ') {
			$i++;
			continue;
		}
		$first = max(0, $i - $context);
		$last = $i;
		$j = $i;
		while ($j < $count) {
			if ($all[$j][0] !== ' ') {
				$last = $j;
				$j++;
				continue;
			}
			$gap = 0;
			$k = $j;
			while ($k < $count && $all[$k][0] === ' ') {
				$gap++;
				$k++;
			}
			if ($k < $count && $gap <= $context * 2) {
				$j = $k;
				continue;
			}
			break;
		}
		$hunks[] = array($first, min($count - 1, $last + $context));
		$i = $last + 1 + $context;
	}

	// Line numbers at the start of every position.
	$num_a = array();
	$num_b = array();
	for ($k = 0; $k < $count; $k++) {
		$num_a[$k] = $line_a;
		$num_b[$k] = $line_b;
		if ($all[$k][0] !== '+') {
			$line_a++;
		}
		if ($all[$k][0] !== '-') {
			$line_b++;
		}
	}
	$path = ltrim($path, '/');
	$out .= 'diff --git a/' . $path . ' b/' . $path . "\n";
	if ($old === '') {
		$out .= "new file mode 100644\n--- /dev/null\n";
	} else {
		$out .= '--- a/' . $path . "\n";
	}
	$out .= ($new === '' ? '+++ /dev/null' : '+++ b/' . $path) . "\n";
	foreach ($hunks as $h) {
		list($from, $to) = $h;
		$cnt_a = 0;
		$cnt_b = 0;
		$body = '';
		for ($k = $from; $k <= $to; $k++) {
			$body .= $all[$k][0] . $all[$k][1] . "\n";
			if ($all[$k][0] !== '+') {
				$cnt_a++;
			}
			if ($all[$k][0] !== '-') {
				$cnt_b++;
			}
		}
		$out .= '@@ -' . ($cnt_a === 0 ? $num_a[$from] - 1 : $num_a[$from]) . ',' . $cnt_a . ' +' . ($cnt_b === 0 ? $num_b[$from] - 1 : $num_b[$from]) . ',' . $cnt_b . " @@\n" . $body;
	}
	return $out;
}
?>
