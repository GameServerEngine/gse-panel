<?php
/*
 * Prove a game XML edit did not change what one OS sees.
 *
 * Usage (from the panel root):
 *   php tools/lgsm_xml/check_merged.php OLD.xml NEW.xml windows
 *
 * Both files are read with read_server_config() for that OS (schema check and
 * OS merge). The merged top-level settings are compared one by one; their
 * order does not matter to the panel, so it is ignored. Exit code 1 on a difference.
 */

chdir(dirname(__DIR__, 2));
require_once 'modules/config_games/server_config_parser.php';

list(, $old_file, $new_file, $os) = $argv;
$old = merged_settings($old_file, $os);
$new = merged_settings($new_file, $os);
if ($old === $new) {
	echo "same $os config: " . basename($new_file) . "\n";
	exit(0);
}
foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $tag) {
	if (($old[$tag] ?? null) !== ($new[$tag] ?? null)) {
		echo "different <$tag> for $os in " . basename($new_file) . "\n";
	}
}
exit(1);

// tag => normalized XML of each top-level setting after the panel's OS merge.
function merged_settings($file, $os)
{
	$GLOBALS['_xml_errors'] = array();
	$xml = read_server_config($file, $os);
	if ($xml === false) {
		fwrite(STDERR, "cannot read $file for $os\n");
		exit(1);
	}
	$settings = array();
	foreach ($xml->children() as $child) {
		$tag = $child->getName();
		if ($tag === 'os') {
			continue; // the os block itself is merged in; what matters is the result
		}
		$dom = dom_import_simplexml($child);
		$text = $dom->C14N();
		$settings[$tag] = trim(preg_replace('/\s+/', ' ', $text));
	}
	ksort($settings);
	return $settings;
}
