<?php
/**
 * Builds reference/games.json, reference/problems.json and reference/coverage.json
 * from modules/config_games/server_configs/*.xml and modules/billing/docs/* /metadata.json.
 *
 * Run: php modules/billing/scripts/build_game_reference.php
 * Read-only against the XML definitions; only writes under reference/.
 */

require_once __DIR__ . '/../includes/game_reference_parser.php';

$repoRoot     = dirname(__DIR__, 3);
$configDir    = $repoRoot . '/modules/config_games/server_configs';
$docsDir      = $repoRoot . '/modules/billing/docs';
$referenceDir = $repoRoot . '/reference';

if (!is_dir($referenceDir)) {
    mkdir($referenceDir, 0755, true);
}

$problems = [];

// --- Step 1: parse every game XML ---

$entries = [];
$files = glob($configDir . '/*.xml');
sort($files);

foreach ($files as $path) {
    $filename = basename($path);

    // Backups and editor save files are not real game definitions.
    if (str_contains($filename, '.bak.') || str_ends_with($filename, '.save')) {
        game_reference_add_problem($problems, $filename, 'skipped_backup_file');
        continue;
    }

    $parsed = game_reference_parse_xml($path, $problems);
    if ($parsed === null) {
        continue;
    }
    $entries[] = $parsed;
}

// --- Step 2: group platform variants under one game_key ---

$games = game_reference_group_entries($entries, $problems);
$gamesList = array_values($games);

file_put_contents(
    $referenceDir . '/games.json',
    json_encode($gamesList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

usort($problems, fn($a, $b) => $a['source_file'] <=> $b['source_file']);
file_put_contents(
    $referenceDir . '/problems.json',
    json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

// --- Step 3: enrich with docs folders, write coverage.json ---

$docFolders = [];
if (is_dir($docsDir)) {
    foreach (scandir($docsDir) as $folder) {
        if ($folder === '.' || $folder === '..') {
            continue;
        }
        $folderPath = $docsDir . '/' . $folder;
        $metadataPath = $folderPath . '/metadata.json';
        if (!is_dir($folderPath) || !file_exists($metadataPath)) {
            continue;
        }
        $metadata = json_decode(file_get_contents($metadataPath), true) ?: [];
        $docFolders[$folder] = [
            'folder' => $folder,
            'title' => $metadata['name'] ?? ucfirst($folder),
            'description' => $metadata['description'] ?? '',
        ];
    }
}

$gameKeys = array_keys($games);

// Attach doc metadata to games.json entries whose game_key matches a docs folder name.
foreach ($gamesList as &$game) {
    if (isset($docFolders[$game['game_key']])) {
        $game['docs'] = $docFolders[$game['game_key']];
    }
}
unset($game);

file_put_contents(
    $referenceDir . '/games.json',
    json_encode($gamesList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

$docsWithNoXml = [];
foreach ($docFolders as $folder => $info) {
    if (!in_array($folder, $gameKeys, true)) {
        $docsWithNoXml[] = $folder;
    }
}

$xmlWithNoDocs = [];
foreach ($gameKeys as $key) {
    if (!isset($docFolders[$key])) {
        $xmlWithNoDocs[] = $key;
    }
}

sort($docsWithNoXml);
sort($xmlWithNoDocs);

$coverage = [
    'docs_folder_count' => count($docFolders),
    'xml_game_count' => count($gameKeys),
    'docs_with_no_xml' => $docsWithNoXml,
    'xml_with_no_docs' => $xmlWithNoDocs,
];

file_put_contents(
    $referenceDir . '/coverage.json',
    json_encode($coverage, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

fwrite(STDERR, sprintf(
    "Parsed %d XML files into %d games (%d problems, %d docs folders, %d docs-no-xml, %d xml-no-docs)\n",
    count($entries),
    count($gamesList),
    count($problems),
    count($docFolders),
    count($docsWithNoXml),
    count($xmlWithNoDocs)
));
