<?php
require_once __DIR__ . '/../includes/game_reference_parser.php';

function assertGameRefSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function assertGameRefTrue($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Expected true: ' . $message);
    }
}

$fixturesDir = __DIR__ . '/fixtures/game_xml';

// --- one fixture per platform type ---

$platformFiles = [
    'win32' => 'fixturegame_win32.xml',
    'win64' => 'fixturegame_win64.xml',
    'linux32' => 'fixturegame_linux32.xml',
    'linux64' => 'fixturegame_linux64.xml',
];

$entries = [];
foreach ($platformFiles as $platform => $file) {
    $problems = [];
    $parsed = game_reference_parse_xml($fixturesDir . '/' . $file, $problems);
    assertGameRefTrue($parsed !== null, "$file should parse without errors");
    assertGameRefSame([], $problems, "$file should not report problems");
    assertGameRefSame($platform, $parsed['platform'], "$file platform detected from game_key suffix");
    assertGameRefSame('fixturegame', $parsed['base_game_key'], "$file base_game_key strips the platform suffix");
    assertGameRefSame('Fixture Game', $parsed['game_name'], "$file game_name");
    assertGameRefSame('steamcmd', $parsed['installer'], "$file installer");
    assertGameRefSame('-port=', $parsed['cli_params'][1]['cli_string'], "$file second cli_param cli_string");
    assertGameRefSame('1', $parsed['ports']['reserve_ports'][0]['value'], "$file reserve_ports value");
    assertGameRefSame('CHEATS', $parsed['server_params'][0]['id'], "$file server_params id");
    assertGameRefSame('sv_rate', $parsed['custom_fields'][0]['key'], "$file custom_fields key");
    assertGameRefSame('server.cfg', $parsed['config_files'][0]['path'], "$file config_files path");
    assertGameRefSame('123456', $parsed['mods'][0]['installer_name'], "$file mods installer_name");
    $entries[] = $parsed;
}

// Grouping four platform files of the same game should produce one game_key with four platforms.
$problems = [];
$games = game_reference_group_entries($entries, $problems);
assertGameRefSame([], $problems, 'grouping four distinct platforms should not report problems');
assertGameRefSame(1, count($games), 'four platform files group into one game');
assertGameRefTrue(isset($games['fixturegame']), 'grouped game is keyed by the shared base game_key');
$platformList = $games['fixturegame']['platform_list'];
sort($platformList);
assertGameRefSame(
    ['linux32', 'linux64', 'win32', 'win64'],
    $platformList,
    'platform_list lists all four platforms'
);
// Values that differ per platform (here, just the platform id itself) are kept per platform.
assertGameRefSame('win32', $games['fixturegame']['platforms']['win32']['platform'], 'win32 platform block keeps its own platform id');
assertGameRefSame('linux64', $games['fixturegame']['platforms']['linux64']['platform'], 'linux64 platform block keeps its own platform id');

// --- single-platform game (a game with exactly one platform keeps one platform block) ---

$problems = [];
$single = game_reference_parse_xml($fixturesDir . '/singleplatformgame.xml', $problems);
assertGameRefTrue($single !== null, 'single-platform fixture should parse');
assertGameRefSame([], $problems, 'single-platform fixture should not report problems');
assertGameRefSame('generic', $single['platform'], 'a game_key with no recognized OS suffix is platform "generic"');
assertGameRefSame('singleplatformgame', $single['base_game_key'], 'base_game_key equals the full key when there is no suffix');

$problems = [];
$singleGames = game_reference_group_entries([$single], $problems);
assertGameRefSame(1, count($singleGames), 'single-platform game groups into exactly one game');
assertGameRefSame(['generic'], $singleGames['singleplatformgame']['platform_list'], 'single-platform game keeps a single platform block');

// --- parse problems: missing game_key ---

$problems = [];
$noKey = game_reference_parse_xml($fixturesDir . '/missing_game_key.xml', $problems);
assertGameRefSame(null, $noKey, 'a file with no game_key is skipped, not fabricated');
assertGameRefSame(1, count($problems), 'missing game_key is recorded as a problem');
assertGameRefSame('missing_game_key.xml', $problems[0]['source_file'], 'problem names the offending file');
assertGameRefTrue(str_contains($problems[0]['issue'], 'missing game_key'), 'problem explains the issue');

// --- parse problems: malformed XML ---

$problems = [];
$malformed = game_reference_parse_xml($fixturesDir . '/malformed.xml', $problems);
assertGameRefSame(null, $malformed, 'malformed XML is skipped, not fabricated');
assertGameRefSame(1, count($problems), 'malformed XML is recorded as a problem');
assertGameRefTrue(str_contains($problems[0]['issue'], 'xml_parse_error'), 'problem identifies it as an xml_parse_error');

// --- duplicate platform detection ---

$problems = [];
$dupA = game_reference_parse_xml($fixturesDir . '/fixturegame_win32.xml', $problems);
$dupB = game_reference_parse_xml($fixturesDir . '/fixturegame_win32.xml', $problems);
$dupGames = game_reference_group_entries([$dupA, $dupB], $problems);
assertGameRefSame(1, count($dupGames), 'two files resolving to the same game_key+platform still produce one game entry');
assertGameRefSame(1, count($problems), 'the second file is flagged as a duplicate_platform problem');
assertGameRefTrue(str_contains($problems[0]['issue'], 'duplicate_platform'), 'problem identifies it as duplicate_platform');

echo "GameReferenceParserTest passed\n";
