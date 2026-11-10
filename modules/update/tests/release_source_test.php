<?php
require_once(dirname(__DIR__) . '/release_source.php');

function relsrc_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

// --- provider detection ---
relsrc_assert(release_provider_from_url('https://github.com/acme/widget.git') === 'github', 'github.com repo URL detected as github');
relsrc_assert(release_provider_from_url('https://gitlab.com/acme/widget.git') === 'gitlab', 'gitlab.com repo URL detected as gitlab');
relsrc_assert(release_provider_from_url('https://git.runlevelsystems.com/GSE/Panel.git') === 'generic', 'self-hosted forge detected as generic');

// --- stable / testing picking from a pre-sorted (newest-first) release list ---
$releases = [
    ['tag' => 'v2.1.0-rc1', 'prerelease' => true, 'created_at' => '2026-10-10'],
    ['tag' => 'v2.0.0', 'prerelease' => false, 'created_at' => '2026-09-01'],
    ['tag' => 'v1.9.0', 'prerelease' => false, 'created_at' => '2026-08-01'],
];
relsrc_assert(release_pick_stable($releases)['tag'] === 'v2.0.0', 'stable picks the newest non-prerelease, skipping the prerelease ahead of it');
relsrc_assert(release_pick_testing($releases)['tag'] === 'v2.1.0-rc1', 'testing picks the newest prerelease');

$no_prerelease = [
    ['tag' => 'v2.0.0', 'prerelease' => false, 'created_at' => '2026-09-01'],
];
relsrc_assert(release_pick_testing($no_prerelease) === null, 'testing returns null when there is no prerelease, so caller falls back to branch head');

$none = [];
relsrc_assert(release_pick_stable($none) === null, 'stable returns null for an empty release list');

// --- release_resolve_channel: stable channel uses the releases API result ---
$cfg = ['component' => 'panel', 'repo_url' => 'https://github.com/acme/widget.git', 'channel' => 'stable', 'token_file' => ''];
$fetch_releases = function ($url, $token, $provider) use ($releases) { return $releases; };
$fetch_branch = function ($url, $token, $provider) { return ['tag' => 'testing', 'commit' => 'deadbeef']; };
$resolved = release_resolve_channel($cfg, $fetch_releases, $fetch_branch);
relsrc_assert($resolved['success'] === true && $resolved['tag'] === 'v2.0.0' && $resolved['source'] === 'release', 'stable channel resolves to the newest non-prerelease via the releases API');

// --- testing channel with a prerelease available ---
$cfg['channel'] = 'testing';
$resolved = release_resolve_channel($cfg, $fetch_releases, $fetch_branch);
relsrc_assert($resolved['success'] === true && $resolved['tag'] === 'v2.1.0-rc1' && $resolved['source'] === 'release', 'testing channel resolves to the newest prerelease when one exists');

// --- testing channel with no prerelease falls back to the testing branch head ---
$fetch_releases_no_pre = function ($url, $token, $provider) use ($no_prerelease) { return $no_prerelease; };
$resolved = release_resolve_channel($cfg, $fetch_releases_no_pre, $fetch_branch);
relsrc_assert($resolved['success'] === true && $resolved['source'] === 'branch' && $resolved['commit'] === 'deadbeef', 'testing channel falls back to the testing branch head when there is no prerelease tag');

// --- generic (self-hosted) provider: resolved via a git-tags-shaped fetcher (same row shape, different source) ---
$cfg_generic = ['component' => 'agent_linux', 'repo_url' => 'https://git.runlevelsystems.com/GSE/Agent-Linux.git', 'channel' => 'testing', 'token_file' => ''];
$fetch_tags_no_prerelease = function ($url, $token, $provider) use ($no_prerelease) {
    relsrc_assert($provider === 'generic', 'the fetcher sees the resolved provider (generic) so it can pick its own source');
    return $no_prerelease;
};
$resolved = release_resolve_channel($cfg_generic, $fetch_tags_no_prerelease, $fetch_branch);
relsrc_assert($resolved['success'] === true && $resolved['source'] === 'branch', 'generic provider on testing channel falls back to the testing branch head when its tag source has no prerelease');

$cfg_generic['channel'] = 'stable';
$fetch_tags_empty = function ($url, $token, $provider) { return []; };
$resolved = release_resolve_channel($cfg_generic, $fetch_tags_empty, $fetch_branch);
relsrc_assert($resolved['success'] === false, 'generic provider on stable channel with no qualifying tag reports failure rather than guessing');

// --- validation ---
relsrc_assert(is_valid_release_source_channel('stable'), 'stable is a valid channel');
relsrc_assert(is_valid_release_source_channel('testing'), 'testing is a valid channel');
relsrc_assert(!is_valid_release_source_channel('unstable'), 'unstable is not a valid channel for this settings surface');
$bad = validate_release_source_settings(['component' => 'panel', 'repo_url' => 'not-a-url', 'channel' => 'stable', 'token_file' => '']);
relsrc_assert(!empty($bad), 'non-http(s) repo URL is rejected');
$bad_token = validate_release_source_settings(['component' => 'panel', 'repo_url' => 'https://github.com/acme/widget.git', 'channel' => 'stable', 'token_file' => '../etc/passwd']);
relsrc_assert(!empty($bad_token), 'relative/traversal token file path is rejected');
$ok = validate_release_source_settings(['component' => 'panel', 'repo_url' => 'https://github.com/acme/widget.git', 'channel' => 'stable', 'token_file' => '/etc/gsp/secrets/panel_token']);
relsrc_assert(empty($ok), 'valid settings with an absolute secret path validate');

// --- checksum verification ---
$tmp = tempnam(sys_get_temp_dir(), 'relsrc_');
file_put_contents($tmp, "release archive contents\n");
$real_hash = hash_file('sha256', $tmp);
$ok = verify_sha256_file($tmp, $real_hash);
relsrc_assert($ok['success'] === true, 'matching sha256 digest verifies');
$ok_sha256sum_line = verify_sha256_file($tmp, $real_hash . '  panel-release.zip');
relsrc_assert($ok_sha256sum_line['success'] === true, 'a sha256sum-style "<hex>  <filename>" line verifies too');
$ok_upper = verify_sha256_file($tmp, strtoupper($real_hash));
relsrc_assert($ok_upper['success'] === true, 'uppercase hex digest verifies (case-insensitive)');
$bad_hash = verify_sha256_file($tmp, str_repeat('0', 64));
relsrc_assert($bad_hash['success'] === false, 'mismatched sha256 digest is rejected');
$bad_format = verify_sha256_file($tmp, 'not-a-hash');
relsrc_assert($bad_format['success'] === false, 'malformed expected checksum is rejected rather than silently passing');
$missing_file = verify_sha256_file($tmp . '.does-not-exist', $real_hash);
relsrc_assert($missing_file['success'] === false, 'missing file to verify is rejected');
@unlink($tmp);

echo "All release source tests passed.\n";

// --- release_installed_label: label comes from the installed release, not a channel setting ---
$gh = 'https://github.com/GameServerEngine/gse-panel';
relsrc_assert(release_installed_label('2.5.7', $gh . '/releases/download/testing-latest/gse-panel.zip') === 'Testing 2.5.7', 'testing-* release download shows testing');
relsrc_assert(release_installed_label('2.5.8', $gh . '/archive/refs/tags/testing-2.5.8.zip') === 'Testing 2.5.8', 'testing-* tag archive shows testing');
relsrc_assert(release_installed_label('2.5.8', $gh . '/archive/refs/tags/working-2.5.8.zip') === 'Testing Latest 2.5.8', 'working-* tag archive shows working');
relsrc_assert(release_installed_label('2.5.8', $gh . '/releases/download/working-latest/p.zip') === 'Testing Latest 2.5.8', 'working-latest download shows working');
relsrc_assert(release_installed_label('2.5.0', $gh . '/archive/refs/tags/stable-2.5.0.zip') === 'Stable 2.5.0', 'stable-* release shows stable');
relsrc_assert(release_installed_label('2.4.0', $gh . '/archive/refs/heads/stable.zip') === 'Stable 2.4.0', 'legacy stable.zip source stays readable as stable');
relsrc_assert(release_installed_label('2.4.0', $gh . '/archive/refs/heads/testing.zip') === 'Testing 2.4.0', 'legacy testing.zip source stays readable as testing');
relsrc_assert(release_installed_label('2.5.0', 'https://github.com/acme/testing/archive/refs/tags/stable-2.5.0.zip') === 'Stable 2.5.0', 'a repo named testing is not read as a testing release');
relsrc_assert(release_installed_label('2.5.0', '') === 'Unknown tag 2.5.0', 'no tag in the record shows Unknown tag, not stable');
relsrc_assert(release_installed_label('2.5.0', $gh . '/archive/refs/tags/v2.5.0.zip') === 'Unknown tag 2.5.0', 'an unrecognised tag shows Unknown tag');
relsrc_assert(release_installed_label('2.7.1', $gh . '/releases/download/working-latest/p.zip') === 'Testing Latest 2.7.1', 'current version line example: Testing Latest 2.7.1');
relsrc_assert(release_rollback_button_label('2.6.1', 'Stable') === 'Roll back to 2.6.1 (Stable)', 'rollback button text');
relsrc_assert(release_rollback_button_label('2.6.1', '') === 'Roll back to 2.6.1 (Unknown tag)', 'rollback button text with no tag');
relsrc_assert(release_installed_label('', '') === 'Unknown tag unknown', 'no version and no tag');
relsrc_assert(release_option_label(array('tag' => 'stable-2.5.0', 'channel' => 'stable', 'version' => '2.5.0', 'created' => '2026-11-01T10:00:00Z')) === 'Stable 2.5.0 (2026-11-01)', 'stable option text');
relsrc_assert(release_option_label(array('tag' => 'testing-2.5.9', 'channel' => 'testing', 'version' => '2.5.9', 'created' => '2026-11-10')) === 'Testing 2.5.9 (2026-11-10)', 'testing option text');
relsrc_assert(release_option_label(array('tag' => 'working-latest', 'channel' => 'working', 'version' => '2.5.9', 'created' => '2026-11-10')) === 'Testing Latest 2.5.9 (2026-11-10)', 'working option text');
relsrc_assert(release_option_label(array('tag' => 'working-2.5.9', 'version' => '2.5.9', 'created' => '2026-11-10')) === 'Testing Latest 2.5.9 (2026-11-10)', 'option text falls back to the tag prefix');
