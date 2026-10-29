#!/usr/bin/env php
<?php
/*
 * Best-effort static schema drift guard. Does NOT require a live database.
 *
 * Statically parses every module's module.php for CREATE TABLE IF NOT EXISTS
 * statements inside its install_queries (both `gsp_foo` literal-prefix and
 * `".GSP_DB_PREFIX."foo` / `GSP_DB_PREFIXfoo` placeholder-prefix styles are
 * recognized, matching includes/install_engine.php's own
 * gsp_install_extract_panel_table_suffixes() convention) and asserts every
 * such table name also appears in install/gse_panel.sql, the canonical
 * schema dump.
 *
 * This is a best-effort static drift guard, NOT a full schema diff: it only
 * checks that a table *name* declared by a module is present somewhere in
 * the canonical dump. It does not compare columns, indexes, engines, or
 * catch tables that were removed from a module but left stale in the dump.
 * Regenerate install/gse_panel.sql with scripts/build-schema.sh whenever
 * panel.sql or any module's module.php install_queries change.
 *
 * Usage: php tests/check_schema_drift.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$base_dir = dirname(__DIR__);
require_once($base_dir . '/includes/install_engine.php');

$schema_path = $base_dir . '/install/gse_panel.sql';
if (!is_readable($schema_path)) {
    fwrite(STDERR, "ERROR: $schema_path not found or unreadable.\n");
    exit(1);
}
$schema_sql = file_get_contents($schema_path);

$modules_dir = $base_dir . '/modules';
$missing = array();
$checked = 0;

foreach (scandir($modules_dir) as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    if (preg_match('/\.bak(\.|$)/i', $entry)) {
        continue;
    }
    $module_php = $modules_dir . '/' . $entry . '/module.php';
    if (!is_file($module_php)) {
        continue;
    }

    $content = file_get_contents($module_php);

    // Find each "CREATE TABLE IF NOT EXISTS ..." (or "CREATE TABLE ...")
    // occurrence and extract the table name from a short snippet following
    // it, reusing the same prefix-extraction logic as the installer.
    if (!preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s*.{0,120}/is', $content, $matches)) {
        continue;
    }

    foreach ($matches[0] as $snippet) {
        $suffixes = gsp_install_extract_panel_table_suffixes($snippet);
        if (empty($suffixes)) {
            continue;
        }
        // The first suffix found immediately after CREATE TABLE is the
        // table being declared.
        $table_suffix = $suffixes[0];
        if ($table_suffix === '' || $table_suffix === 'table_name') {
            continue;
        }
        $checked++;
        $table_name = 'gsp_' . $table_suffix;
        if (strpos($schema_sql, '`' . $table_name . '`') === false) {
            $missing[] = array('module' => $entry, 'table' => $table_name);
        }
    }
}

echo "Checked $checked module-declared table(s) against install/gse_panel.sql.\n";

if (!empty($missing)) {
    foreach ($missing as $item) {
        fwrite(STDERR, "MISSING: table `{$item['table']}` declared in modules/{$item['module']}/module.php but not found in install/gse_panel.sql\n");
    }
    fwrite(STDERR, "FAIL: " . count($missing) . " table(s) declared by modules are missing from install/gse_panel.sql.\n");
    fwrite(STDERR, "Regenerate with scripts/build-schema.sh.\n");
    exit(1);
}

echo "PASS: no missing tables detected (best-effort static check only).\n";
