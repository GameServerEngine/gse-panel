<?php
// modules/billing/bootstrap.php
// Central bootstrap for billing website pages. Loads config, provides safe DB helper
// and ensures $table_prefix is available.

// Ensure session sync with panel happens first
require_once __DIR__ . '/billing_bootstrap.php';
require_once __DIR__ . '/includes/session_bridge.php';
$billing_panel_runtime_vars = [];
if (!empty($_SESSION['user_id']) && ($_GET['m'] ?? '') === 'billing'
    && in_array(basename((string)($_SERVER['PHP_SELF'] ?? '')), ['index.php', 'home.php'], true)) {
    foreach (['db_host', 'db_user', 'db_pass', 'db_name', 'db_port', 'table_prefix', 'db_type'] as $billingDbVar) {
        if (array_key_exists($billingDbVar, $GLOBALS)) {
            $billing_panel_runtime_vars[$billingDbVar] = $GLOBALS[$billingDbVar];
        }
    }
}
require_once __DIR__ . '/includes/config_loader.php';
foreach ($billing_panel_runtime_vars as $billingDbVar => $billingDbValue) {
    $GLOBALS[$billingDbVar] = $billingDbValue;
    ${$billingDbVar} = $billingDbValue;
}
unset($billing_panel_runtime_vars, $billingDbVar, $billingDbValue);

// Ensure $table_prefix exists (fallback to empty string)
if (!isset($table_prefix)) {
    $table_prefix = '';
}

// Billing DB connection cached in $billing_db
if (!isset($billing_db)) {
    $billing_db = null;
}

// Track whether bootstrap opened the connection (so callers can safely close it)
$billing_db_opened_by_bootstrap = false;

/**
 * Get a mysqli connection for billing pages.
 * - Reuses global $db if already created by other code.
 * - Tries to open a new connection using config variables if needed.
 * - Returns null on failure.
 */
function billing_get_db()
{
    global $billing_db, $db, $db_host, $db_user, $db_pass, $db_name, $db_port, $billing_db_opened_by_bootstrap;
    if (!empty($billing_db) && ($billing_db instanceof mysqli)) {
        return $billing_db;
    }
    if (!empty($db) && ($db instanceof mysqli)) {
        $billing_db = $db;
        return $billing_db;
    }
    $port = isset($db_port) ? (int)$db_port : null;
    // Try to connect; callers handle null for degraded public rendering.
    if (function_exists('mysqli_report')) {
        @mysqli_report(MYSQLI_REPORT_OFF);
    }
    try {
        $conn = @mysqli_connect($db_host ?? null, $db_user ?? null, $db_pass ?? null, $db_name ?? null, $port);
    } catch (Throwable $e) {
        $conn = false;
    }
    if ($conn) {
        // Set charset when available
        if (function_exists('mysqli_set_charset')) {
            @mysqli_set_charset($conn, 'utf8mb4');
        }
        $billing_db = $conn;
        $billing_db_opened_by_bootstrap = true;
        return $billing_db;
    }
    // Leave $billing_db as null
    $billing_db = null;
    return null;
}

/**
 * Close DB connection only if it was opened by bootstrap. If the connection
 * is shared (created by other code) this function will not close it.
 */
function billing_maybe_close_db($conn)
{
    global $billing_db, $billing_db_opened_by_bootstrap;
    if (!($conn instanceof mysqli)) return;
    if (!empty($billing_db_opened_by_bootstrap) && $billing_db === $conn) {
        @mysqli_close($conn);
        $billing_db = null;
        $billing_db_opened_by_bootstrap = false;
    }
}

// Small helper wrappers commonly used across billing pages
if (!function_exists('esc_mysqli')) {
    function esc_mysqli($db, $v)
    {
        if ($db instanceof mysqli) {
            return $db->real_escape_string((string)$v);
        }
        return addslashes((string)$v);
    }
}

if (!function_exists('fetch_all_assoc')) {
    function fetch_all_assoc($db, $sql)
    {
        if (!($db instanceof mysqli)) return [];
        $res = $db->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('col_exists')) {
    function col_exists($db, $table, $col)
    {
        if (!($db instanceof mysqli)) return false;
        $t = $db->real_escape_string($table);
        $c = $db->real_escape_string($col);
        $res = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
        return ($res && $res->num_rows > 0);
    }
}

// expose a convenience variable for scripts that expect $db
// Do not overwrite an existing $db if present
if (!isset($db) || !($db instanceof mysqli)) {
    $maybe = billing_get_db();
    if ($maybe instanceof mysqli) {
        $db = $maybe;
    }
}

/**
 * Resolve a billing_services.img_url value to a browser-safe URL.
 *
 * Rules:
 *  - Empty string → '' (caller should skip the <img> tag).
 *  - Full URL (http:// or https://) → returned as-is.
 *  - Bare filename (e.g. "dayz.jpg") → "/images/games/{filename}".
 *  - Anything else treated as a bare filename for safety.
 *
 * Output is NOT htmlspecialchars'd here; callers must escape for HTML context.
 */
if (!function_exists('billing_image_url')) {
    function billing_image_url(string $imgUrl): string
    {
        $imgUrl = trim($imgUrl);
        if ($imgUrl === '') {
            return '';
        }
        // Keep full external URLs intact
        if (str_starts_with($imgUrl, 'http://') || str_starts_with($imgUrl, 'https://')) {
            return $imgUrl;
        }
        // Strip any leading path separators/directories so we always get a bare filename
        $filename = basename($imgUrl);
        if ($filename === '') {
            return '';
        }
        if (function_exists('panel_asset_url')) {
            return panel_asset_url('images/games/' . $filename);
        }
        return billing_url('images/games/' . $filename);
    }
}

// End bootstrap
