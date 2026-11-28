<?php
// Offline only. Read JSONL game_sample exports on stdin. No database connection.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/resource_model.php';

function observed_rows(): Generator
{
    while (($line = fgets(STDIN)) !== false) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($row)) throw new UnexpectedValueException('Expected one game_sample object per line');
        yield $row;
    }
}
echo json_encode(resource_profiles(observed_rows(), time()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
