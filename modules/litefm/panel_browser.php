<?php
/*
 * LiteFM for the panel itself: administrators browse, open and edit the panel's own files the
 * same way the file manager browses a game server. Saves go straight to the live files, so each
 * save keeps a backup next to the file (name.bak-YYYYmmdd-HHMMSS), PHP files must pass php -l
 * first, and the panel config, keys, secrets and the .git folder are never shown or written.
 * Use it for quick fixes; normal changes still go through git (working, testing, stable).
 */

function litefm_panel_root()
{
    return realpath(dirname(__DIR__, 2));
}

function litefm_panel_h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

// Names that are never shown or written here: the config, keys, secrets and git data.
function litefm_panel_hidden($name)
{
    if (preg_match('/^(\.git|\.ssh|secrets?)$/i', $name)) {
        return true;
    }
    return (bool)preg_match('/^(config\.inc\.php.*|\.env.*|.*\.env|.*\.(key|pem|p12|pfx|kdbx|crt)|id_(rsa|dsa|ecdsa|ed25519).*|authorized_keys|known_hosts|\.htpasswd)$/i', $name);
}

// A relative path from the request, cleaned. Returns the string, or false when it is not allowed.
function litefm_panel_rel($value)
{
    $value = str_replace('\\', '/', (string)$value);
    if (strpos($value, "\0") !== false) {
        return false;
    }
    $parts = array();
    foreach (explode('/', $value) as $part) {
        if ($part === '') {
            continue;
        }
        if ($part === '.' || $part === '..' || litefm_panel_hidden($part)) {
            return false;
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

// The real path of a file or folder inside the panel folder, or null (outside, a symlink, or missing).
function litefm_panel_full($rel)
{
    $root = litefm_panel_root();
    if ($root === false || $rel === false) {
        return null;
    }
    $full = $rel === '' ? $root : $root . '/' . $rel;
    if ($rel !== '' && is_link($full)) {
        return null;
    }
    $real = realpath($full);
    if ($real === false || ($real !== $root && strpos($real, $root . '/') !== 0)) {
        return null;
    }
    return $real;
}

// Runs php -l on the text. Returns '' when it passes, otherwise the reason.
function litefm_panel_php_lint($source)
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) {
        return 'PHP files cannot be syntax-checked on this server, so they cannot be saved here.';
    }
    $tmp = tempnam(sys_get_temp_dir(), 'litefmlint');
    if ($tmp === false) {
        return 'The syntax check could not start.';
    }
    file_put_contents($tmp, (string)$source);
    $bin = (defined('PHP_BINARY') && basename(PHP_BINARY) === 'php') ? PHP_BINARY : 'php';
    $out = array();
    $status = 1;
    exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
    unlink($tmp);
    if ($status !== 0) {
        return 'PHP syntax check failed: ' . substr(implode(' ', $out), 0, 300);
    }
    return '';
}

// Saves the text of one existing file. Returns array(ok, message).
function litefm_panel_save($rel, $content)
{
    $full = litefm_panel_full($rel);
    if ($full === null || !is_file($full)) {
        return array(false, 'That file was not found.');
    }
    if (!is_writable($full)) {
        return array(false, 'The web server cannot write that file.');
    }
    $content = str_replace("\r\n", "\n", (string)$content);
    if (strlen($content) > 1048576) {
        return array(false, 'The text is too big to save here.');
    }
    if (substr($rel, -4) === '.php') {
        $lint = litefm_panel_php_lint($content);
        if ($lint !== '') {
            return array(false, $lint . ' Nothing was saved.');
        }
    }
    $backup = $full . '.bak-' . date('Ymd-His');
    if (!copy($full, $backup)) {
        return array(false, 'The backup could not be made. Nothing was saved.');
    }
    if (file_put_contents($full, $content, LOCK_EX) !== strlen($content)) {
        copy($backup, $full);
        return array(false, 'The save failed. The file was put back from its backup.');
    }
    return array(true, 'Saved. The old version is kept as ' . basename($backup) . '.');
}

function litefm_panel_link($dir, $file = '')
{
    $query = 'm=litefm&amp;p=litefm_panel';
    if ($file !== '') {
        $query .= '&amp;file=' . rawurlencode($file);
    } elseif ($dir !== '') {
        $query .= '&amp;dir=' . rawurlencode($dir);
    }
    return '?' . $query;
}

function exec_module()
{
    echo "<h2>LiteFM: panel files</h2>";
    if (!isset($_SESSION['users_role']) || $_SESSION['users_role'] !== 'admin') {
        print_failure(get_lang('no_rights'));
        return;
    }
    $root = litefm_panel_root();
    if ($root === false) {
        print_failure('The panel folder could not be found.');
        return;
    }
    if (empty($_SESSION['litefm_panel_csrf'])) {
        $_SESSION['litefm_panel_csrf'] = bin2hex(random_bytes(16));
    }
    $csrf = $_SESSION['litefm_panel_csrf'];
    $file = false;

    if (isset($_POST['save_file'])) {
        $sent = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        $file = litefm_panel_rel(isset($_POST['file']) ? $_POST['file'] : '');
        if (!hash_equals($csrf, $sent)) {
            print_failure('The form expired. Reload the page and try again. Nothing was saved.');
        } elseif ($file === false || $file === '') {
            print_failure('That path is not allowed.');
        } else {
            list($ok, $message) = litefm_panel_save($file, isset($_POST['content']) ? $_POST['content'] : '');
            if ($ok) {
                print_success($message);
            } else {
                print_failure($message);
            }
        }
    } elseif (isset($_GET['file'])) {
        $file = litefm_panel_rel($_GET['file']);
    }

    if ($file !== false && $file !== '') {
        $full = litefm_panel_full($file);
        if ($full !== null && is_file($full) && filesize($full) <= 1048576) {
            $parent = dirname($file);
            $parent = $parent === '.' ? '' : $parent;
            echo "<p><a href='" . litefm_panel_link($parent) . "'>&larr; Back to " . litefm_panel_h($parent === '' ? 'the panel folder' : $parent) . "</a></p>";
            echo "<h3>" . litefm_panel_h($file) . "</h3>";
            echo "<form method='post' action='?m=litefm&amp;p=litefm_panel' autocomplete='off'>";
            echo "<input type='hidden' name='csrf' value='" . litefm_panel_h($csrf) . "'>";
            echo "<input type='hidden' name='file' value='" . litefm_panel_h($file) . "'>";
            echo "<textarea name='content' rows='32' spellcheck='false' style='width:100%;font-family:monospace;font-size:12px'>\n" . litefm_panel_h(file_get_contents($full)) . "</textarea>";
            echo "<p><button type='submit' name='save_file' value='1' class='btn btn-sm btn-success'>Save</button> ";
            echo "Each save keeps the old version as a backup next to the file. PHP files are checked first.</p>";
            echo "</form>";
            return;
        }
        print_failure('That file was not found or is too big to edit here.');
    }

    $dir = isset($_GET['dir']) ? litefm_panel_rel($_GET['dir']) : '';
    $full = $dir === false ? null : litefm_panel_full($dir);
    if ($full === null || !is_dir($full)) {
        print_failure('That folder was not found.');
        $dir = '';
        $full = $root;
    }

    $crumbs = "<a href='" . litefm_panel_link('') . "'>Panel folder</a>";
    $built = '';
    if ($dir !== '') {
        foreach (explode('/', $dir) as $part) {
            $built = $built === '' ? $part : $built . '/' . $part;
            $crumbs .= " / <a href='" . litefm_panel_link($built) . "'>" . litefm_panel_h($part) . "</a>";
        }
    }
    echo "<p>" . $crumbs . "</p>";
    echo "<p class='note'>Changes here go straight to the live panel. Use this for quick fixes. The panel config, keys, secrets and .git are not shown.</p>";

    $folders = array();
    $files = array();
    foreach ((array)scandir($full) as $name) {
        if ($name === '.' || $name === '..' || litefm_panel_hidden($name)) {
            continue;
        }
        $child = $dir === '' ? $name : $dir . '/' . $name;
        if (is_dir($full . '/' . $name)) {
            $folders[$name] = $child;
        } else {
            $files[$name] = $child;
        }
    }
    ksort($folders);
    ksort($files);

    echo "<table class='center'><tr class='first_row'><td>Name</td><td>Size</td><td>Modified</td><td></td></tr>";
    $i = 0;
    if ($dir !== '') {
        $up = strpos($dir, '/') === false ? '' : dirname($dir);
        echo "<tr class='tr" . ($i++ % 2) . "'><td colspan='4'><a href='" . litefm_panel_link($up) . "'>.. (up one level)</a></td></tr>";
    }
    foreach ($folders as $name => $child) {
        echo "<tr class='tr" . ($i++ % 2) . "'><td><a href='" . litefm_panel_link($child) . "'>" . litefm_panel_h($name) . "/</a></td><td></td><td></td><td></td></tr>";
    }
    foreach ($files as $name => $child) {
        $path = $full . '/' . $name;
        echo "<tr class='tr" . ($i++ % 2) . "'><td>" . litefm_panel_h($name) . "</td><td>" . (int)filesize($path) . " bytes</td><td>" . date('Y-m-d H:i', (int)filemtime($path)) . "</td>";
        echo "<td><a href='" . litefm_panel_link('', $child) . "'>Open / edit</a></td></tr>";
    }
    echo "</table>";
}
?>
