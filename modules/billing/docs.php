<?php
/**
 * Documentation Browser
 * Displays a list of documentation categories and allows viewing individual docs
 */

// Start session using the website session name to match the rest of the site
if (session_status() === PHP_SESSION_NONE) {
    session_name("gameserverpanel_web");
    session_start();
}

// Include bootstrap for portable billing URLs and degraded config handling.
require_once(__DIR__ . '/bootstrap.php');

// Set the docs directory
$docsDir = __DIR__ . '/docs';
$gameReferencePath = __DIR__ . '/../../reference/games.json';

// Get action and doc parameters
$action = $_GET['action'] ?? 'list';
$doc = $_GET['doc'] ?? '';
$game = $_GET['game'] ?? '';
$docsPagePath = billing_url('docs.php');

/**
 * Load the parsed game reference data (reference/games.json), keyed by game_key.
 * Returns [] if the file is missing or invalid - the page still renders the rest of the docs.
 */
function loadGameReference($path) {
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    $byKey = [];
    foreach ($data as $entry) {
        if (!empty($entry['game_key'])) {
            $byKey[$entry['game_key']] = $entry;
        }
    }
    return $byKey;
}

$gamesByKey = loadGameReference($gameReferencePath);

/**
 * Get all documentation folders with their metadata
 */
function getDocCategories($docsDir) {
    $categories = [];

    if (!is_dir($docsDir)) {
        return $categories;
    }

    $folders = array_diff(scandir($docsDir), ['.', '..']);

    foreach ((array)$folders as $folder) {
        $folderPath = $docsDir . '/' . $folder;

        // Skip if not a directory
        if (!is_dir($folderPath)) {
            continue;
        }

        // Check for required files
        $indexPath = $folderPath . '/index.php';
        $metadataPath = $folderPath . '/metadata.json';

        if (!file_exists($indexPath) || !file_exists($metadataPath)) {
            continue;
        }

        // Read metadata
        $metadataContent = file_get_contents($metadataPath);
        // Remove UTF-8 BOM if present
        $metadataContent = preg_replace('/^\xEF\xBB\xBF/', '', $metadataContent);
        $metadata = json_decode($metadataContent, true);
        if (!$metadata) {
            $metadata = [];
        }

        // Get display name (no TODO prefix - just display all docs)
        $displayName = $metadata['name'] ?? ucfirst($folder);

        // Find icon file
        $icon = '';
        if (file_exists($folderPath . '/icon.png')) {
            $icon = billing_url('docs/' . $folder . '/icon.png');
        } elseif (file_exists($folderPath . '/icon.jpg')) {
            $icon = billing_url('docs/' . $folder . '/icon.jpg');
        }

        $categories[] = [
            'folder' => $folder,
            'name' => $displayName,
            'description' => $metadata['description'] ?? '',
            'category' => trim($metadata['category'] ?? 'other'),
            'order' => $metadata['order'] ?? 999,
            'icon' => $icon
        ];
    }

    // Sort alphabetically by name within categories
    usort($categories, function($a, $b) {
        if ($a['category'] !== $b['category']) {
            // Keep category grouping (game, mods, other)
            return strcmp($a['category'], $b['category']);
        }
        // Sort alphabetically by name (case-insensitive)
        return strcasecmp($a['name'], $b['name']);
    });

    return $categories;
}

// Get all categories
$categories = getDocCategories($docsDir);

// Docs folders tagged category=game are used only to enrich game cards (icon/extra text) -
// the "Game Servers" section itself is built from reference/games.json below, per the
// game-reference handoff, so pull those entries out of the folder-driven list first.
$gameDocFoldersByKey = [];
$nonGameCategories = [];
foreach ((array)$categories as $cat) {
    if ($cat['category'] === 'game') {
        $gameDocFoldersByKey[$cat['folder']] = $cat;
    } else {
        $nonGameCategories[] = $cat;
    }
}
$categories = $nonGameCategories;

// Build game cards from the parsed game reference, enriched with any matching docs folder.
$gameCards = [];
foreach ($gamesByKey as $key => $entry) {
    $docFolder = $gameDocFoldersByKey[$key] ?? null;
    $gameCards[] = [
        'folder' => $key,
        'name' => $entry['game_name'] ?? ($docFolder['name'] ?? ucfirst($key)),
        'description' => $docFolder['description'] ?? implode(', ', array_map('ucfirst', $entry['platform_list'] ?? [])),
        'category' => 'game',
        'order' => $docFolder['order'] ?? 999,
        'icon' => $docFolder['icon'] ?? '',
    ];
}
usort($gameCards, fn($a, $b) => strcasecmp($a['name'], $b['name']));

// Group by category
$grouped = [];
foreach ((array)$categories as $cat) {
    $category = $cat['category'];
    if (!isset($grouped[$category])) {
        $grouped[$category] = [];
    }
    $grouped[$category][] = $cat;
}
if (!empty($gameCards)) {
    $grouped['game'] = $gameCards;
}

// Category labels - can be extended via JSON
$categoryLabels = [
    'todo' => 'TODO',
    'game' => 'Game Servers',
    'mods' => 'Mods & Plugins',
    'panel' => 'Panel Documentation',
    'troubleshooting' => 'Troubleshooting',
    'other' => 'Other'
];

// Define category display order
 $categoryOrder = ['todo', 'panel', 'game', 'mods', 'troubleshooting', 'other'];

// Sort categories by defined order
uksort($grouped, function($a, $b) use ($categoryOrder) {
    $posA = array_search($a, $categoryOrder);
    $posB = array_search($b, $categoryOrder);

    // If not in order array, put at end
    if ($posA === false) $posA = 999;
    if ($posB === false) $posB = 999;

    return $posA - $posB;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars('Documentation - GameServers.World', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo htmlspecialchars(billing_url('images/brand/favicon-32x32.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(billing_url('css/header.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <style>
        /* Documentation-specific styles - consistent with site theme */

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        .header {
            margin-bottom: 40px;
        }

        .header h1 {
            font-size: 32px;
            margin: 0 0 12px;
            color: #fff;
        }

        .header p {
            color: rgba(255,255,255,0.7);
            margin: 0;
        }

        .back-button {
            display: inline-block;
            padding: 10px 20px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #7fb3ff;
            text-decoration: none;
            margin-bottom: 20px;
            transition: all 0.2s;
        }

        .back-button:hover {
            background: rgba(255,255,255,0.06);
            border-color: #667eea;
        }

        .category-section {
            margin-bottom: 40px;
        }

        .category-title {
            font-size: 24px;
            color: #4de1ff;
            margin: 0 0 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid rgba(255,255,255,0.1);
        }

        .docs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .game-search-input {
            width: 100%;
            max-width: 400px;
            padding: 10px 15px;
            margin-bottom: 20px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
        }

        .game-search-input:focus {
            outline: none;
            border-color: #4de1ff;
        }

        .doc-card {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.2s;
            text-decoration: none;
            display: flex;
            flex-direction: column;
        }

        .doc-card:hover {
            border-color: #4de1ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.1);
        }

        .doc-icon-wrapper {
            width: 60px;
            height: 60px;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }

        .doc-icon {
            max-width: 100%;
            max-height: 100%;
            border-radius: 6px;
        }

        .doc-icon-placeholder {
            font-size: 28px;
            color: rgba(255,255,255,0.6);
        }

        .doc-title {
            font-size: 18px;
            font-weight: 600;
            color: #fff;
            margin: 0 0 8px;
        }

        .doc-description {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            margin: 0;
            flex-grow: 1;
        }

        .doc-view-container {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 30px;
            min-height: 400px;
        }

        .doc-view-container h1,
        .doc-view-container h2,
        .doc-view-container h3,
        .doc-view-container h4 {
            color: #fff;
        }

        .doc-view-container a {
            color: #7fb3ff;
        }

        .doc-view-container code {
            background: rgba(0,0,0,0.3);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            color: #a5b4fc;
        }

        .doc-view-container pre {
            background: rgba(0,0,0,0.3);
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
        }

        .doc-view-container pre code {
            background: none;
            padding: 0;
        }

        .nav-links {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .nav-links h3 {
            margin: 0 0 15px;
            color: #4de1ff;
            font-size: 18px;
        }

        .nav-links a {
            display: inline-block;
            padding: 8px 15px;
            margin: 5px 10px 5px 0;
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 5px;
            color: #7fb3ff;
            text-decoration: none;
            transition: all 0.2s;
        }

        .nav-links a:hover {
            background: #2b7dff;
            color: #fff;
            border-color: #2b7dff;
        }

        .return-to-top {
            text-align: center;
            margin: 30px 0;
        }

        .return-to-top a {
            display: inline-block;
            padding: 10px 20px;
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 8px;
            color: #7fb3ff;
            text-decoration: none;
            transition: all 0.2s;
        }

        .return-to-top a:hover {
            background: rgba(255,255,255,0.06);
            border-color: #667eea;
        }
    </style>
<?php include(__DIR__ . '/includes/theme_head.php'); ?>
</head>
<body>
    <?php include(__DIR__ . '/includes/menu.php'); ?>

    <main class="container">
        <?php if ($action === 'view' && !empty($doc)): ?>
            <!-- View specific documentation -->
            <a href="<?php echo htmlspecialchars($docsPagePath, ENT_QUOTES, 'UTF-8'); ?>" class="back-button">← Back to Documentation List</a>

            <div class="doc-view-container">
                <?php
                // Sanitize doc parameter to prevent directory traversal
                $doc = basename($doc);
                $docPath = $docsDir . '/' . $doc . '/index.php';

                if (file_exists($docPath)) {
                    include($docPath);
                } else {
                    echo '<p style="color: #ef4444;">Documentation not found.</p>';
                }
                ?>
            </div>

        <?php elseif ($action === 'game' && !empty($game)): ?>
            <!-- View specific game's reference data -->
            <a href="<?php echo htmlspecialchars($docsPagePath, ENT_QUOTES, 'UTF-8'); ?>" class="back-button">← Back to Documentation List</a>

            <div class="doc-view-container">
                <?php
                $gameKey = basename($game);
                $gameEntry = $gamesByKey[$gameKey] ?? null;

                if ($gameEntry === null) {
                    echo '<p style="color: #ef4444;">Game not found.</p>';
                } else {
                    echo '<h1>' . htmlspecialchars($gameEntry['game_name'] ?? $gameKey) . '</h1>';
                    echo '<p><code>' . htmlspecialchars($gameKey) . '</code></p>';

                    foreach ((array)($gameEntry['platforms'] ?? []) as $platformId => $p) {
                        echo '<h2>' . htmlspecialchars(ucfirst($platformId)) . '</h2>';
                        echo '<table style="width:100%;border-collapse:collapse;margin-bottom:20px;">';

                        $row = function ($label, $value) {
                            if ($value === null || $value === '') {
                                return;
                            }
                            echo '<tr><td style="padding:6px 12px;color:rgba(255,255,255,0.6);vertical-align:top;white-space:nowrap;">'
                                . htmlspecialchars($label) . '</td><td style="padding:6px 12px;word-break:break-all;">'
                                . htmlspecialchars((string)$value) . '</td></tr>';
                        };

                        $row('Source file', $p['source_file'] ?? '');
                        $row('Installer', $p['installer'] ?? '');
                        $row('Server executable', $p['server_exec_name'] ?? '');
                        $row('Executable location', $p['exe_location'] ?? '');
                        $row('CLI template', $p['cli_template'] ?? '');
                        $row('Control protocol', $p['control_protocol'] ?? '');
                        $row('Max players', $p['max_user_amount'] ?? '');

                        if (!empty($p['ports']['query_port'])) {
                            $qp = $p['ports']['query_port'];
                            $row('Query port offset', ($qp['type'] ?? '') . ' ' . ($qp['value'] ?? ''));
                        }
                        foreach ((array)($p['ports']['reserve_ports'] ?? []) as $rp) {
                            $row('Reserved port: ' . ($rp['id'] ?? ''), ($rp['type'] ?? '') . ' ' . ($rp['value'] ?? '') . ' ' . ($rp['cli_string'] ?? ''));
                        }
                        foreach ((array)($p['config_files'] ?? []) as $cf) {
                            $row('Config file', trim(($cf['path'] ?? '') . ' - ' . ($cf['description'] ?? ''), ' -'));
                        }
                        foreach ((array)($p['mods'] ?? []) as $mod) {
                            $row('Mod: ' . ($mod['key'] ?? ''), trim(($mod['name'] ?? '') . ' (installer ' . ($mod['installer_name'] ?? '') . ')'));
                        }

                        echo '</table>';

                        if (!empty($p['custom_fields'])) {
                            echo '<h3>Custom fields</h3><ul>';
                            foreach ($p['custom_fields'] as $f) {
                                echo '<li><code>' . htmlspecialchars($f['key'] ?? '') . '</code>'
                                    . ($f['desc'] ? ' - ' . htmlspecialchars($f['desc']) : '')
                                    . '</li>';
                            }
                            echo '</ul>';
                        }
                    }

                    // Keep any hand-written docs content for this game as the detail view's guide section.
                    $docIndexPath = $docsDir . '/' . $gameKey . '/index.php';
                    if (file_exists($docIndexPath)) {
                        echo '<h2>Guide</h2>';
                        include($docIndexPath);
                    }
                }
                ?>
            </div>

        <?php else: ?>
            <!-- List all documentation categories -->
            <div class="header">
                <h1 id="top">Documentation</h1>
                <p>Browse our comprehensive documentation for game servers, panel features, and troubleshooting guides.</p>
            </div>

            <?php if (empty($grouped)): ?>
                <div class="doc-view-container">
                    <p>No documentation available yet. Documentation folders should contain:</p>
                    <ul>
                        <li><code>index.php</code> - The documentation content</li>
                        <li><code>metadata.json</code> - Category and ordering information</li>
                        <li><code>icon.png</code> or <code>icon.jpg</code> - Category icon</li>
                    </ul>
                </div>
            <?php else: ?>
                <!-- Navigation Links -->
                <div class="nav-links">
                    <h3>Jump to Section:</h3>
                    <?php foreach ((array)$grouped as $category => $docs): ?>
                        <a href="#<?php echo htmlspecialchars($category); ?>">
                            <?php echo htmlspecialchars($categoryLabels[$category] ?? ucfirst($category)); ?>
                            (<?php echo count((array)$docs); ?>)
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php foreach ((array)$grouped as $category => $docs): ?>
                    <div class="category-section" id="<?php echo htmlspecialchars($category); ?>">
                        <h2 class="category-title"><?php echo htmlspecialchars($categoryLabels[$category] ?? ucfirst($category)); ?></h2>

                        <?php if ($category === 'game'): ?>
                            <input
                                type="search"
                                id="gameSearchInput"
                                class="game-search-input"
                                placeholder="Search games by name..."
                                oninput="filterGameCards(this.value)"
                            >
                        <?php endif; ?>

                        <div class="docs-grid" id="<?php echo $category === 'game' ? 'gameDocsGrid' : ''; ?>">
                            <?php foreach ((array)$docs as $doc): ?>
                                <?php
                                $cardHref = $category === 'game'
                                    ? $docsPagePath . '?action=game&game=' . urlencode($doc['folder'])
                                    : $docsPagePath . '?action=view&doc=' . urlencode($doc['folder']);
                                ?>
                                <a href="<?php echo htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8'); ?>"
                                   class="doc-card"
                                   <?php if ($category === 'game'): ?>data-game-name="<?php echo htmlspecialchars(strtolower($doc['name']), ENT_QUOTES, 'UTF-8'); ?>"<?php endif; ?>>
                                    <div class="doc-icon-wrapper">
                                        <?php if (!empty($doc['icon'])): ?>
                                            <img src="<?php echo htmlspecialchars($doc['icon']); ?>" alt="" class="doc-icon">
                                        <?php else: ?>
                                            <span class="doc-icon-placeholder">DOC</span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="doc-title"><?php echo htmlspecialchars($doc['name']); ?></h3>
                                    <?php if (!empty($doc['description'])): ?>
                                        <p class="doc-description"><?php echo htmlspecialchars($doc['description']); ?></p>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="return-to-top">
                            <a href="#top">↑ Return to Top</a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($gameCards)): ?>
                <script>
                    function filterGameCards(query) {
                        var needle = query.trim().toLowerCase();
                        var grid = document.getElementById('gameDocsGrid');
                        if (!grid) { return; }
                        var cards = grid.getElementsByClassName('doc-card');
                        for (var i = 0; i < cards.length; i++) {
                            var name = cards[i].getAttribute('data-game-name') || '';
                            cards[i].style.display = (needle === '' || name.indexOf(needle) !== -1) ? '' : 'none';
                        }
                    }
                </script>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </main>
<?php include(__DIR__ . '/includes/footer.php'); ?>
</body>
</html>
