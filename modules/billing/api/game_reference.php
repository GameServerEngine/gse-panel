<?php
/**
 * Read-only JSON API for the parsed game reference data (reference/games.json).
 * GET /api/game_reference.php            -> full list of games
 * GET /api/game_reference.php?game_key=X -> single game by game_key
 * No writes; this endpoint only reads reference/games.json.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

$gamesPath = __DIR__ . '/../../../reference/games.json';

if (!file_exists($gamesPath)) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error_code' => 'reference_unavailable',
        'message' => 'Game reference data has not been generated.',
    ]);
    exit;
}

$games = json_decode(file_get_contents($gamesPath), true);
if (!is_array($games)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error_code' => 'reference_invalid',
        'message' => 'Game reference data is not valid JSON.',
    ]);
    exit;
}

$gameKey = $_GET['game_key'] ?? null;

if ($gameKey !== null) {
    $match = null;
    foreach ($games as $entry) {
        if (($entry['game_key'] ?? null) === $gameKey) {
            $match = $entry;
            break;
        }
    }

    if ($match === null) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error_code' => 'not_found',
            'message' => 'No game with that game_key.',
        ]);
        exit;
    }

    echo json_encode(['success' => true, 'game' => $match], JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['success' => true, 'count' => count($games), 'games' => $games], JSON_UNESCAPED_SLASHES);
