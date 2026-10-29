<?php
/**
 * Pure parsing/grouping functions for game_config XML definitions
 * (modules/config_games/server_configs/*.xml). No file-system scanning or
 * writes here - see scripts/build_game_reference.php for the I/O driver,
 * and tests/GameReferenceParserTest.php for the fixtures that exercise these.
 */

// Platform suffixes recognized in <game_key>, longest/most specific first.
const GAME_REFERENCE_PLATFORM_SUFFIXES = ['_win32', '_win64', '_linux32', '_linux64', '_win', '_linux'];

function game_reference_add_problem(array &$problems, string $file, string $issue): void {
    $problems[] = ['source_file' => $file, 'issue' => $issue];
}

function game_reference_split_platform(string $gameKey): array {
    foreach (GAME_REFERENCE_PLATFORM_SUFFIXES as $suffix) {
        if (str_ends_with($gameKey, $suffix)) {
            $platform = ltrim($suffix, '_');
            return [substr($gameKey, 0, -strlen($suffix)), $platform];
        }
    }
    return [$gameKey, 'generic'];
}

function game_reference_text_or_null(?SimpleXMLElement $node): ?string {
    if ($node === null) {
        return null;
    }
    $value = trim((string)$node);
    return $value === '' ? null : $value;
}

function game_reference_attrs_to_array(SimpleXMLElement $node): array {
    $out = [];
    foreach ($node->attributes() as $k => $v) {
        $out[$k] = (string)$v;
    }
    return $out;
}

function game_reference_is_portish(string $key): bool {
    return stripos($key, 'port') !== false;
}

/**
 * Parses one game_config XML file into a flat associative array describing that platform variant.
 * Returns null (and records a problem) if the file fails to parse or has no game_key.
 */
function game_reference_parse_xml(string $path, array &$problems): ?array {
    $filename = basename($path);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($path);
    if ($xml === false) {
        $errors = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        game_reference_add_problem($problems, $filename, 'xml_parse_error: ' . implode('; ', $errors));
        return null;
    }
    libxml_clear_errors();

    $gameKey = game_reference_text_or_null($xml->game_key);
    if ($gameKey === null) {
        game_reference_add_problem($problems, $filename, 'missing game_key');
        return null;
    }

    $cliParams = [];
    if (isset($xml->cli_params)) {
        foreach ($xml->cli_params->cli_param as $p) {
            $cliParams[] = game_reference_attrs_to_array($p);
        }
    }

    $reservePorts = [];
    if (isset($xml->reserve_ports)) {
        foreach ($xml->reserve_ports->port as $p) {
            $entry = game_reference_attrs_to_array($p);
            $entry['value'] = trim((string)$p);
            $reservePorts[] = $entry;
        }
    }

    $queryPort = null;
    if (isset($xml->query_port)) {
        $queryPort = game_reference_attrs_to_array($xml->query_port);
        $queryPort['value'] = trim((string)$xml->query_port);
    }

    $serverParamPorts = [];
    $serverParamsAll = [];
    if (isset($xml->server_params)) {
        foreach ($xml->server_params->param as $p) {
            $attrs = game_reference_attrs_to_array($p);
            $options = [];
            foreach ($p->option as $opt) {
                $optAttrs = game_reference_attrs_to_array($opt);
                $optAttrs['label'] = trim((string)$opt);
                $options[] = $optAttrs;
            }
            $entry = $attrs;
            $entry['options'] = $options;
            $entry['desc'] = game_reference_text_or_null($p->desc);
            $entry['default'] = game_reference_text_or_null($p->default);
            $serverParamsAll[] = $entry;
            $key = $attrs['key'] ?? '';
            $id  = $attrs['id'] ?? '';
            if (game_reference_is_portish($key) || game_reference_is_portish($id)) {
                $serverParamPorts[] = $entry;
            }
        }
    }

    $configFiles = [];
    if (isset($xml->configuration_files)) {
        foreach ($xml->configuration_files->file as $f) {
            $configFiles[] = [
                'path' => trim((string)$f),
                'description' => (string)($f['description'] ?? ''),
            ];
        }
    }

    $customFields = [];
    if (isset($xml->custom_fields)) {
        foreach ($xml->custom_fields->field as $f) {
            $attrs = game_reference_attrs_to_array($f);
            $customFields[] = [
                'key' => $attrs['key'] ?? null,
                'type' => $attrs['type'] ?? null,
                'default' => game_reference_text_or_null($f->default),
                'default_value' => game_reference_text_or_null($f->default_value),
                'var' => game_reference_text_or_null($f->var),
                'filepath' => game_reference_text_or_null($f->filepath),
                'options' => game_reference_text_or_null($f->options),
                'desc' => game_reference_text_or_null($f->desc),
            ];
        }
    }

    $mods = [];
    if (isset($xml->mods)) {
        foreach ($xml->mods->mod as $m) {
            $mods[] = [
                'key' => (string)($m['key'] ?? ''),
                'name' => game_reference_text_or_null($m->name),
                'installer_name' => game_reference_text_or_null($m->installer_name),
                'installer_login' => game_reference_text_or_null($m->installer_login),
            ];
        }
    }

    [$baseKey, $platform] = game_reference_split_platform($gameKey);

    return [
        'source_file' => $filename,
        'game_key_raw' => $gameKey,
        'base_game_key' => $baseKey,
        'platform' => $platform,
        'game_name' => game_reference_text_or_null($xml->game_name),
        'installer' => game_reference_text_or_null($xml->installer),
        'protocol' => game_reference_text_or_null($xml->protocol),
        'server_exec_name' => game_reference_text_or_null($xml->server_exec_name),
        'exe_location' => game_reference_text_or_null($xml->exe_location),
        'cli_template' => game_reference_text_or_null($xml->cli_template),
        'cli_params' => $cliParams,
        'ports' => [
            'query_port' => $queryPort,
            'reserve_ports' => $reservePorts,
            'server_param_ports' => $serverParamPorts,
        ],
        'server_params' => $serverParamsAll,
        'config_files' => $configFiles,
        'custom_fields' => $customFields,
        'mods' => $mods,
        'map_list' => game_reference_text_or_null($xml->map_list),
        'maps_location' => game_reference_text_or_null($xml->maps_location),
        'max_user_amount' => game_reference_text_or_null($xml->max_user_amount),
        'control_protocol' => game_reference_text_or_null($xml->control_protocol),
    ];
}

/**
 * Groups already-parsed platform entries (as returned by game_reference_parse_xml) under one
 * game_key per base game. Records duplicate-platform and game_name-mismatch problems rather
 * than guessing which value is correct.
 */
function game_reference_group_entries(array $entries, array &$problems): array {
    $games = [];
    foreach ($entries as $entry) {
        $baseKey = $entry['base_game_key'];
        $platform = $entry['platform'];

        if (!isset($games[$baseKey])) {
            $games[$baseKey] = [
                'game_key' => $baseKey,
                'game_name' => $entry['game_name'],
                'platforms' => [],
            ];
        }

        if (isset($games[$baseKey]['platforms'][$platform])) {
            game_reference_add_problem(
                $problems,
                $entry['source_file'],
                "duplicate_platform: {$baseKey}/{$platform} already defined by " .
                    $games[$baseKey]['platforms'][$platform]['source_file']
            );
            continue;
        }

        if ($games[$baseKey]['game_name'] !== $entry['game_name']) {
            game_reference_add_problem(
                $problems,
                $entry['source_file'],
                "game_name mismatch across platforms for {$baseKey}: " .
                    "'{$games[$baseKey]['game_name']}' vs '{$entry['game_name']}'"
            );
        }

        unset($entry['base_game_key']);
        $games[$baseKey]['platforms'][$platform] = $entry;
    }

    foreach ($games as &$game) {
        $game['platform_list'] = array_keys($game['platforms']);
        ksort($game['platforms']);
    }
    unset($game);

    ksort($games);

    return $games;
}
