<?php
/**
 * Confirms reference/coverage.json accounts for every real game XML: each base game_key
 * parsed from modules/config_games/server_configs/*.xml is either a key the docs folders
 * cover, or is listed in xml_with_no_docs. None should silently fall through the cracks.
 */
require_once __DIR__ . '/../includes/game_reference_parser.php';

function assertGameRefCoverageTrue($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Expected true: ' . $message);
    }
}

$repoRoot = dirname(__DIR__, 3);
$configDir = $repoRoot . '/modules/config_games/server_configs';
$coveragePath = $repoRoot . '/reference/coverage.json';
$gamesPath = $repoRoot . '/reference/games.json';

if (!file_exists($coveragePath) || !file_exists($gamesPath)) {
    throw new RuntimeException('reference/coverage.json and reference/games.json must exist - run modules/billing/scripts/build_game_reference.php first');
}

// Re-derive the ground truth set of base game_keys directly from the XML, independent of
// whatever games.json currently contains, so this test still catches a stale games.json.
$problems = [];
$baseKeys = [];
foreach (glob($configDir . '/*.xml') as $path) {
    $filename = basename($path);
    if (str_contains($filename, '.bak.') || str_ends_with($filename, '.save')) {
        continue;
    }
    $parsed = game_reference_parse_xml($path, $problems);
    if ($parsed !== null) {
        $baseKeys[$parsed['base_game_key']] = true;
    }
}
$baseKeys = array_keys($baseKeys);

$coverage = json_decode(file_get_contents($coveragePath), true);
assertGameRefCoverageTrue(is_array($coverage), 'coverage.json must decode to an array');
assertGameRefCoverageTrue(isset($coverage['xml_with_no_docs']) && is_array($coverage['xml_with_no_docs']), 'coverage.json has xml_with_no_docs');
assertGameRefCoverageTrue(isset($coverage['docs_with_no_xml']) && is_array($coverage['docs_with_no_xml']), 'coverage.json has docs_with_no_xml');

$games = json_decode(file_get_contents($gamesPath), true);
assertGameRefCoverageTrue(is_array($games), 'games.json must decode to an array');

$coveredKeys = [];
foreach ($games as $game) {
    $coveredKeys[$game['game_key']] = isset($game['docs']);
}

$xmlWithNoDocs = array_flip($coverage['xml_with_no_docs']);

foreach ($baseKeys as $key) {
    assertGameRefCoverageTrue(
        array_key_exists($key, $coveredKeys),
        "every parsed game_key must appear in games.json: missing '$key'"
    );
    $hasDocs = $coveredKeys[$key];
    if ($hasDocs) {
        assertGameRefCoverageTrue(
            !isset($xmlWithNoDocs[$key]),
            "'$key' has docs attached in games.json, so it must not also be listed in xml_with_no_docs"
        );
    } else {
        assertGameRefCoverageTrue(
            isset($xmlWithNoDocs[$key]),
            "'$key' has no docs folder, so it must be listed in coverage.json's xml_with_no_docs"
        );
    }
}

echo "GameReferenceCoverageTest passed\n";
