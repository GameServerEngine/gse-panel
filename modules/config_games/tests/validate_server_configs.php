<?php
/*
 * Validates all game server XML configuration files against the bundled
 * schema. This is a developer smoke test; it does not load the full Panel.
 */

$baseDir = dirname(__DIR__);
$schema = $baseDir . '/schema_server_config.xml';
$configDir = $baseDir . '/server_configs';

if (!is_file($schema)) {
	fwrite(STDERR, "Schema not found: {$schema}\n");
	exit(1);
}

$files = glob($configDir . '/*.xml');
sort($files);

if (empty($files)) {
	fwrite(STDERR, "No XML files found under: {$configDir}\n");
	exit(1);
}

libxml_use_internal_errors(true);
$failed = 0;

foreach ($files as $file) {
	$doc = new DOMDocument();
	$doc->preserveWhiteSpace = false;
	$doc->formatOutput = false;

	if (!$doc->load($file)) {
		$failed++;
		echo "FAIL " . basename($file) . "\n";
		foreach (libxml_get_errors() as $error) {
			echo "  XML parse: " . trim($error->message) . "\n";
		}
		libxml_clear_errors();
		continue;
	}

	if (!$doc->schemaValidate($schema)) {
		$failed++;
		echo "FAIL " . basename($file) . "\n";
		foreach (libxml_get_errors() as $error) {
			echo "  Schema: " . trim($error->message) . "\n";
		}
		libxml_clear_errors();
		continue;
	}

	echo "PASS " . basename($file) . "\n";
}

if ($failed > 0) {
	echo "XML validation failed for {$failed} file(s).\n";
	exit(1);
}

echo "All server config XML files validated successfully.\n";
