<?php
/*
 * Merges each game's Windows and Linux server XML files into one file with an <os> block,
 * and renames single-OS files without their OS suffix. Every result is checked against the
 * schema and against its original files before anything is changed.
 *
 * Usage: php merge_game_os_configs.php SRC_DIR STAGE_DIR MAP_CSV SCHEMA [apply]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$src = rtrim($argv[1], '/');
$stage = rtrim($argv[2], '/');
$mapCsv = $argv[3];
$schemaPath = $argv[4];
$apply = (isset($argv[5]) && $argv[5] === 'apply');
if (!is_dir($stage)) {
    mkdir($stage, 0775, true);
}
libxml_use_internal_errors(true);

$sdoc = new DOMDocument();
$sdoc->load($schemaPath);
$sx = new DOMXPath($sdoc);
$sx->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
$order = array();
foreach ($sx->query("//xs:complexType[@name='server_config_type']/xs:sequence/xs:element") as $el) {
    $order[] = $el->getAttribute('name');
}

$identity = array('game_key', 'game_name', 'server_exec_name', 'os');

function norm_xml($s)
{
    return trim(preg_replace('/>[[:space:]]+</', '><', $s));
}

function cmp_strings(DOMNode $node, DOMDocument $doc)
{
    if ($node->nodeName === 'server_exec_name' || $node->nodeName === 'exe') {
        return 'EXE:' . trim($node->textContent);
    }
    return norm_xml($doc->saveXML($node));
}

function nodes_by_name(DOMNode $parent)
{
    $map = array();
    foreach ($parent->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE) {
            $map[$c->nodeName][] = $c;
        }
    }
    return $map;
}

/* Settings as a game sees them for one OS: top-level values plus that OS's <os> block. */
function effective_strings(DOMDocument $doc, $os)
{
    $out = array();
    $top = nodes_by_name($doc->documentElement);
    foreach ($top as $name => $nodes) {
        if (in_array($name, array('game_key', 'game_name', 'os'), true)) {
            continue;
        }
        foreach ($nodes as $n) {
            $out[$name][] = cmp_strings($n, $doc);
        }
    }
    if ($os !== null && isset($top['os'][0])) {
        $plats = nodes_by_name($top['os'][0]);
        if (isset($plats[$os][0])) {
            foreach (nodes_by_name($plats[$os][0]) as $name => $nodes) {
                $key = ($name === 'exe') ? 'server_exec_name' : $name;
                foreach ($nodes as $n) {
                    $out[$key][] = cmp_strings($n, $doc);
                }
            }
        }
    }
    return $out;
}

$files = glob($src . '/*.xml');
sort($files);
$groups = array();
foreach ($files as $f) {
    $name = basename($f, '.xml');
    if (preg_match('/^(.*)_(win|win32|win64|linux|linux32|linux64)$/', $name, $m)) {
        $base = $m[1];
        $os = (strpos($m[2], 'win') === 0) ? 'windows' : 'linux';
        $suffix = $m[2];
    } else {
        $base = $name;
        $os = 'linux';
        $suffix = '';
    }
    $groups[$base][$os][] = array('file' => $f, 'suffix' => $suffix);
}
ksort($groups);

$rows = array(array('old_files', 'new_file', 'platforms', 'kind', 'arch', 'notes'));
$problems = array();
$processed = array();
$merged = 0;
$renamed = 0;
$skipped = 0;

foreach ($groups as $base => $byos) {
    $allFiles = array();
    foreach ($byos as $list) {
        foreach ($list as $e) {
            $allFiles[] = basename($e['file']);
        }
    }
    $allPaths = array();
    foreach ($byos as $list) {
        foreach ($list as $e) {
            $allPaths[] = $e['file'];
        }
    }
    $dropped = '';
    foreach (array('windows', 'linux') as $osx) {
        if (isset($byos[$osx]) && count($byos[$osx]) > 1) {
            foreach ($byos[$osx] as $k => $e) {
                if ($e['suffix'] === '') {
                    $dropped = basename($e['file']);
                    unset($byos[$osx][$k]);
                }
            }
            $byos[$osx] = array_values($byos[$osx]);
        }
    }
    $oss = array();
    foreach (array('windows', 'linux') as $os) {
        if (isset($byos[$os])) {
            $oss[] = $os;
        }
    }
    $dup = false;
    foreach ($oss as $os) {
        if (count($byos[$os]) > 1) {
            $dup = true;
        }
    }
    if ($dup) {
        $rows[] = array(implode(';', $allFiles), '', implode('+', $oss), 'SKIPPED', '', 'several files for one OS');
        $skipped++;
        $problems[] = $base . ': several files for one OS';
        continue;
    }

    $docs = array();
    $maps = array();
    $suffix = array();
    $fullPaths = array();
    foreach ($oss as $os) {
        $e = $byos[$os][0];
        $d = new DOMDocument();
        $d->preserveWhiteSpace = false;
        $d->load($e['file']);
        $docs[$os] = $d;
        $maps[$os] = nodes_by_name($d->documentElement);
        $suffix[$os] = $e['suffix'];
        $fullPaths[] = $e['file'];
    }
    $single = (count($oss) === 1);
    $notes = array();
    if ($dropped !== '') {
        $notes[] = 'info: unsuffixed duplicate ' . $dropped . ' dropped';
    }
    /* Game name is shared. If the platforms disagree, the Windows value is used and the difference is noted. */
    $gn = array();
    foreach ($oss as $os) {
        if (isset($maps[$os]['game_name'])) {
            $gn[$os] = cmp_strings($maps[$os]['game_name'][0], $docs[$os]);
        }
    }
    if (count($gn) === 2 && $gn['windows'] !== $gn['linux']) {
        $notes[] = 'game_name differs; windows value used';
    }
    $gnOs = isset($gn['windows']) ? 'windows' : (isset($gn['linux']) ? 'linux' : null);

    $exe = array();
    foreach ($oss as $os) {
        if (!isset($maps[$os]['server_exec_name'][0])) {
            $notes[] = $os . ': no server_exec_name';
            continue;
        }
        $exe[$os] = trim($maps[$os]['server_exec_name'][0]->textContent);
    }

    /* Each setting is either shared (same on every platform, or only one platform) or per-OS. */
    $tags = array();
    foreach ($oss as $os) {
        foreach (array_keys($maps[$os]) as $t) {
            if (!in_array($t, $identity, true)) {
                $tags[$t] = true;
            }
        }
    }
    $shared = array();
    $perOs = array('windows' => array(), 'linux' => array());
    foreach (array_keys($tags) as $t) {
        $present = array();
        foreach ($oss as $os) {
            if (isset($maps[$os][$t])) {
                $present[] = $os;
            }
        }
        $same = false;
        if (count($present) === 2) {
            $sa = array();
            foreach ($maps['windows'][$t] as $n) {
                $sa[] = cmp_strings($n, $docs['windows']);
            }
            $sb = array();
            foreach ($maps['linux'][$t] as $n) {
                $sb[] = cmp_strings($n, $docs['linux']);
            }
            $same = ($sa === $sb);
        }
        if ($single || $same) {
            $shared[$t] = $present[0];
        } else {
            foreach ($present as $os) {
                $perOs[$os][$t] = true;
            }
        }
    }

    /* Build the output file in schema order. */
    $out = new DOMDocument('1.0', 'utf-8');
    $out->formatOutput = true;
    $root = $out->createElement($docs[$oss[0]]->documentElement->nodeName);
    $out->appendChild($root);
    $addText = function (DOMElement $parent, $name, $text) use ($out) {
        $el = $out->createElement($name);
        $el->appendChild($out->createTextNode($text));
        $parent->appendChild($el);
    };
    $sequence = array_values(array_unique(array_merge($order, array_keys($shared))));
    foreach ($sequence as $t) {
        if ($t === 'game_key') {
            $addText($root, 'game_key', $base);
            continue;
        }
        if ($t === 'game_name') {
            if ($gnOs !== null) {
                foreach ($maps[$gnOs]['game_name'] as $n) {
                    $root->appendChild($out->importNode($n, true));
                }
            }
            continue;
        }
        if ($t === 'server_exec_name') {
            continue;
        }
        if ($t === 'os') {
            $osEl = $out->createElement('os');
            foreach ($oss as $os) {
                if (!isset($exe[$os])) {
                    continue;
                }
                $plat = $out->createElement($os);
                if (strpos($suffix[$os], '32') !== false) {
                    $plat->setAttribute('arch', 'x86');
                } elseif (strpos($suffix[$os], '64') !== false) {
                    $plat->setAttribute('arch', 'x64');
                }
                $addText($plat, 'exe', $exe[$os]);
                foreach (array_keys($perOs[$os]) as $pt) {
                    foreach ($maps[$os][$pt] as $n) {
                        $plat->appendChild($out->importNode($n, true));
                    }
                }
                $osEl->appendChild($plat);
            }
            if ($osEl->hasChildNodes()) {
                $root->appendChild($osEl);
            }
            continue;
        }
        if (isset($shared[$t])) {
            $os = $shared[$t];
            foreach ($maps[$os][$t] as $n) {
                $root->appendChild($out->importNode($n, true));
            }
        }
    }
    $target = $stage . '/' . $base . '.xml';
    $out->save($target);

    /* Check the new file against the schema and each original against it. */
    $chk = new DOMDocument();
    $chk->load($target);
    if (!$chk->schemaValidate($schemaPath)) {
        $errs = libxml_get_errors();
        $notes[] = 'schema: ' . (isset($errs[0]) ? trim($errs[0]->message) : 'invalid');
        libxml_clear_errors();
    }
    foreach ($oss as $os) {
        $orig = effective_strings($docs[$os], null);
        $eff = effective_strings($chk, $os);
        foreach (array_unique(array_merge(array_keys($orig), array_keys($eff))) as $k) {
            if (($orig[$k] ?? array()) !== ($eff[$k] ?? array())) {
                $notes[] = $os . ': ' . $k . ' does not match the original';
            }
        }
    }

    $kind = $single ? 'renamed' : 'merged';
    $arches = array();
    foreach ($oss as $os) {
        if (strpos($suffix[$os], '32') !== false) {
            $arches[] = $os . '=x86';
        } elseif (strpos($suffix[$os], '64') !== false) {
            $arches[] = $os . '=x64';
        }
    }
    $rows[] = array(implode(';', $allFiles), $base . '.xml', implode('+', $oss), $kind, implode(';', $arches), implode('; ', $notes));
    foreach ($notes as $n) {
        if (strpos($n, 'game_name differs') !== 0 && strpos($n, 'info:') !== 0) {
            $problems[] = $base . ': ' . $n;
        }
    }
    $processed[$base] = $allPaths;
    if ($single) {
        $renamed++;
    } else {
        $merged++;
    }
}

$fh = fopen($mapCsv, 'w');
foreach ($rows as $r) {
    fputcsv($fh, $r);
}
fclose($fh);

echo 'games=' . count($groups) . ' merged=' . $merged . ' renamed=' . $renamed . ' skipped=' . $skipped . ' problems=' . count($problems) . PHP_EOL;
foreach ($problems as $p) {
    echo 'PROBLEM ' . $p . PHP_EOL;
}

if ($apply) {
    if (count($problems) > 0 || $skipped > 0) {
        echo 'APPLY REFUSED' . PHP_EOL;
        exit(1);
    }
    foreach ($processed as $base => $paths) {
        foreach ($paths as $p) {
            unlink($p);
        }
        copy($stage . '/' . $base . '.xml', $src . '/' . $base . '.xml');
    }
    echo 'APPLIED' . PHP_EOL;
}
