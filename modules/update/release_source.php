<?php
/*
 *
 * GSP - GameServer Panel
 *
 * Git release source settings: per-component (panel, agent Linux, agent Windows)
 * repo URL and release channel (stable or testing), resolved to a concrete
 * release archive via the GitLab/GitHub releases API when the URL points at
 * one of those hosts, falling back to git tags otherwise. The panel never
 * pulls a working tree: it always downloads a release archive and verifies
 * its SHA-256 checksum before staging it.
 *
 */

function gsp_release_components()
{
	return ['panel', 'agent_linux', 'agent_windows'];
}

function gsp_release_component_label($component)
{
	$labels = [
		'panel' => 'Panel',
		'agent_linux' => 'Agent (Linux)',
		'agent_windows' => 'Agent (Windows)',
	];
	return isset($labels[$component]) ? $labels[$component] : $component;
}

function gsp_is_valid_release_source_channel($channel)
{
	return in_array((string)$channel, ['stable', 'testing'], true);
}

/**
 * Settings are stored under gsp_release_<component>_repo_url,
 * gsp_release_<component>_channel, gsp_release_<component>_token_file.
 * Tokens are never stored in the settings table directly: only a path to a
 * secret file the panel reads at request time.
 */
function gsp_release_source_settings($component)
{
	global $settings;
	$prefix = 'gsp_release_' . $component . '_';
	return [
		'component' => $component,
		'repo_url' => !empty($settings[$prefix . 'repo_url']) ? trim((string)$settings[$prefix . 'repo_url']) : '',
		'channel' => gsp_is_valid_release_source_channel($settings[$prefix . 'channel'] ?? '')
			? (string)$settings[$prefix . 'channel']
			: 'stable',
		'token_file' => !empty($settings[$prefix . 'token_file']) ? trim((string)$settings[$prefix . 'token_file']) : '',
	];
}

function gsp_validate_release_source_settings(array $cfg)
{
	$errors = [];
	if ($cfg['repo_url'] !== '' && !preg_match('#^https?://#i', $cfg['repo_url'])) {
		$errors[] = gsp_release_component_label($cfg['component']) . ' repo URL must be http(s).';
	}
	if (!gsp_is_valid_release_source_channel($cfg['channel'])) {
		$errors[] = gsp_release_component_label($cfg['component']) . ' release channel must be stable or testing.';
	}
	if ($cfg['token_file'] !== '' && (strpos($cfg['token_file'], "\0") !== false || strpos($cfg['token_file'], '..') !== false || strpos($cfg['token_file'], '/') !== 0)) {
		$errors[] = gsp_release_component_label($cfg['component']) . ' token file must be a safe absolute path.';
	}
	return $errors;
}

function gsp_read_release_token(array $cfg)
{
	if ($cfg['token_file'] === '' || !is_readable($cfg['token_file'])) {
		return '';
	}
	return trim((string)@file_get_contents($cfg['token_file']));
}

/**
 * 'gitlab' or 'github' for the hosted SaaS instances (api.github.com,
 * gitlab.com) which both expose a releases API; 'generic' for anything else
 * (self-hosted forges, plain git remotes), which falls back to git tags.
 */
function gsp_release_provider_from_url($repo_url)
{
	$host = strtolower((string)parse_url($repo_url, PHP_URL_HOST));
	if ($host === 'github.com' || $host === 'www.github.com') {
		return 'github';
	}
	if ($host === 'gitlab.com' || $host === 'www.gitlab.com') {
		return 'gitlab';
	}
	return 'generic';
}

/**
 * Picks the newest non-prerelease from a list of releases, each
 * ['tag' => string, 'prerelease' => bool, 'created_at' => ISO-8601 string].
 * Releases are assumed to already be sorted newest-first (as GitLab/GitHub
 * APIs return them); this just filters. Returns null if none qualify.
 */
function gsp_release_pick_stable(array $releases)
{
	foreach ($releases as $release) {
		if (empty($release['prerelease'])) {
			return $release;
		}
	}
	return null;
}

/**
 * Picks the newest prerelease. Returns null if there is none, in which case
 * the caller should fall back to the head of the `testing` branch.
 */
function gsp_release_pick_testing(array $releases)
{
	foreach ($releases as $release) {
		if (!empty($release['prerelease'])) {
			return $release;
		}
	}
	return null;
}

/**
 * Resolves a component's configured channel to a concrete release using
 * injectable fetchers, so this can be unit tested without network access.
 * $releases_fetcher: callable($repo_url, $token, $provider) -> array of release-shaped rows
 *   (newest first: ['tag', 'prerelease', 'created_at']), or null if there's nothing to resolve from.
 *   For github/gitlab this is the releases API; for a generic (self-hosted) remote, the production
 *   wrapper passes a git-tags-based fetcher instead (see gsp_release_fetch_tags_live). Tests inject
 *   whatever shape they need directly, independent of provider.
 * $testing_branch_head_fetcher: callable($repo_url, $token, $provider) -> ['tag' => 'testing', 'commit' => sha] or null.
 */
function gsp_release_resolve_channel(array $cfg, callable $releases_fetcher, callable $testing_branch_head_fetcher)
{
	$token = gsp_read_release_token($cfg);
	$provider = gsp_release_provider_from_url($cfg['repo_url']);
	$releases = $releases_fetcher($cfg['repo_url'], $token, $provider);

	if ($cfg['channel'] === 'stable') {
		$pick = is_array($releases) ? gsp_release_pick_stable($releases) : null;
		if ($pick === null) {
			return ['success' => false, 'error' => 'No stable (non-prerelease) release tag found for ' . gsp_release_component_label($cfg['component']) . '.'];
		}
		return ['success' => true, 'source' => 'release', 'tag' => $pick['tag'], 'release' => $pick];
	}

	// testing: newest prerelease, else the head of the `testing` branch.
	$pick = is_array($releases) ? gsp_release_pick_testing($releases) : null;
	if ($pick !== null) {
		return ['success' => true, 'source' => 'release', 'tag' => $pick['tag'], 'release' => $pick];
	}
	$branch_head = $testing_branch_head_fetcher($cfg['repo_url'], $token, $provider);
	if ($branch_head === null) {
		return ['success' => false, 'error' => 'No prerelease tag and no `testing` branch found for ' . gsp_release_component_label($cfg['component']) . '.'];
	}
	return ['success' => true, 'source' => 'branch', 'tag' => $branch_head['tag'], 'commit' => $branch_head['commit']];
}

/**
 * The checksum asset is always named after the archive with a .sha256
 * suffix, published alongside it in the same release.
 */
function gsp_release_checksum_asset_name($archive_filename)
{
	return basename((string)$archive_filename) . '.sha256';
}

/**
 * True if $file_path's SHA-256 digest matches $expected_hex (case-insensitive,
 * may be a bare hex digest or a "<hex>  <filename>" sha256sum-style line).
 */
function gsp_verify_sha256_file($file_path, $expected_hex)
{
	if (!is_file($file_path)) {
		return ['success' => false, 'error' => 'File to verify is missing: ' . $file_path];
	}
	$expected_hex = trim((string)$expected_hex);
	if (preg_match('/^([0-9a-fA-F]{64})\b/', $expected_hex, $m)) {
		$expected_hex = $m[1];
	}
	if (!preg_match('/^[0-9a-fA-F]{64}$/', $expected_hex)) {
		return ['success' => false, 'error' => 'Expected checksum is not a valid SHA-256 hex digest.'];
	}
	$actual = hash_file('sha256', $file_path);
	if ($actual === false) {
		return ['success' => false, 'error' => 'Could not compute checksum of: ' . $file_path];
	}
	if (!hash_equals(strtolower($expected_hex), strtolower($actual))) {
		return ['success' => false, 'error' => 'Checksum mismatch: expected ' . strtolower($expected_hex) . ', got ' . strtolower($actual)];
	}
	return ['success' => true, 'sha256' => strtolower($actual)];
}

/**
 * Live GitLab/GitHub releases fetcher (not used in tests; injected there).
 * Returns rows newest-first: ['tag' => .., 'prerelease' => bool, 'created_at' => .., 'assets' => [name => url]].
 */
function gsp_release_fetch_releases_live($repo_url, $token, $provider)
{
	$api_url = null;
	$headers = [];
	if ($provider === 'github') {
		if (!preg_match('#github\.com[/:]([^/]+)/([^/.]+?)(?:\.git)?/?$#i', $repo_url, $m)) {
			return null;
		}
		$api_url = 'https://api.github.com/repos/' . $m[1] . '/' . $m[2] . '/releases';
		$headers[] = 'Accept: application/vnd.github+json';
		if ($token !== '') {
			$headers[] = 'Authorization: Bearer ' . $token;
		}
	} elseif ($provider === 'gitlab') {
		if (!preg_match('#gitlab\.com[/:](.+?)(?:\.git)?/?$#i', $repo_url, $m)) {
			return null;
		}
		$project = rawurlencode($m[1]);
		$api_url = 'https://gitlab.com/api/v4/projects/' . $project . '/releases';
		if ($token !== '') {
			$headers[] = 'PRIVATE-TOKEN: ' . $token;
		}
	} else {
		return null;
	}

	if (!function_exists('curl_init')) {
		return null;
	}
	$ch = curl_init($api_url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	$body = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($body === false || $status < 200 || $status >= 300) {
		return null;
	}
	$data = json_decode($body, true);
	if (!is_array($data)) {
		return null;
	}

	$rows = [];
	foreach ($data as $entry) {
		if ($provider === 'github') {
			$assets = [];
			foreach ((array)($entry['assets'] ?? []) as $asset) {
				if (!empty($asset['name']) && !empty($asset['browser_download_url'])) {
					$assets[$asset['name']] = $asset['browser_download_url'];
				}
			}
			$rows[] = [
				'tag' => (string)($entry['tag_name'] ?? ''),
				'prerelease' => !empty($entry['prerelease']),
				'created_at' => (string)($entry['created_at'] ?? ''),
				'assets' => $assets,
			];
		} else {
			$assets = [];
			foreach ((array)($entry['assets']['links'] ?? []) as $link) {
				if (!empty($link['name']) && !empty($link['url'])) {
					$assets[$link['name']] = $link['url'];
				}
			}
			$rows[] = [
				'tag' => (string)($entry['tag_name'] ?? ''),
				'prerelease' => !empty($entry['upcoming_release']),
				'created_at' => (string)($entry['released_at'] ?? $entry['created_at'] ?? ''),
				'assets' => $assets,
			];
		}
	}
	return $rows;
}

/**
 * Tag-based fetcher for self-hosted/generic remotes with no releases API:
 * lists tags with `git ls-remote --tags`, treats a tag containing a hyphen
 * (v2.1.0-rc1, v2.1.0-beta, ...) as a prerelease, and sorts newest-first with
 * version_compare on the part before the hyphen, then the full tag string.
 * There is no created_at from ls-remote, so ordering is by tag name only.
 */
function gsp_release_fetch_tags_live($repo_url, $token, $provider)
{
	$url = $repo_url;
	if ($token !== '' && preg_match('#^https://(.+)$#', $repo_url, $m)) {
		$url = 'https://' . rawurlencode('oauth2') . ':' . rawurlencode($token) . '@' . $m[1];
	}
	$out = [];
	$ret = 0;
	exec('git ls-remote --tags ' . escapeshellarg($url) . ' 2>/dev/null', $out, $ret);
	if ($ret !== 0) {
		return null;
	}
	$rows = [];
	foreach ($out as $line) {
		if (!preg_match('#refs/tags/([^\s\^]+)$#', $line, $m)) {
			continue;
		}
		$tag = $m[1];
		if (substr($tag, -3) === '^{}') {
			continue;
		}
		$rows[$tag] = [
			'tag' => $tag,
			'prerelease' => strpos($tag, '-') !== false,
			'created_at' => '',
		];
	}
	$rows = array_values($rows);
	usort($rows, function ($a, $b) {
		$va = preg_replace('/-.*/', '', ltrim($a['tag'], 'vV'));
		$vb = preg_replace('/-.*/', '', ltrim($b['tag'], 'vV'));
		$cmp = version_compare($vb, $va);
		return $cmp !== 0 ? $cmp : strcmp($b['tag'], $a['tag']);
	});
	return $rows;
}

/**
 * Production fetcher dispatch: github/gitlab use their releases API, anything
 * else falls back to git tags over the network.
 */
function gsp_release_fetch_source_live($repo_url, $token, $provider)
{
	if ($provider === 'github' || $provider === 'gitlab') {
		return gsp_release_fetch_releases_live($repo_url, $token, $provider);
	}
	return gsp_release_fetch_tags_live($repo_url, $token, $provider);
}

/**
 * Head commit of the `testing` branch, for the testing-channel fallback when
 * there's no prerelease tag. Works for any git remote, including self-hosted.
 */
function gsp_release_fetch_testing_branch_head_live($repo_url, $token, $provider)
{
	$url = $repo_url;
	if ($token !== '' && preg_match('#^https://(.+)$#', $repo_url, $m)) {
		$url = 'https://' . rawurlencode('oauth2') . ':' . rawurlencode($token) . '@' . $m[1];
	}
	$out = [];
	$ret = 0;
	exec('git ls-remote ' . escapeshellarg($url) . ' refs/heads/testing 2>/dev/null', $out, $ret);
	if ($ret !== 0 || empty($out[0]) || !preg_match('/^([0-9a-f]{40})\s+refs\/heads\/testing$/', trim($out[0]), $m)) {
		return null;
	}
	return ['tag' => 'testing', 'commit' => $m[1]];
}

/**
 * Entry point used by the panel: resolves $cfg (repo_url + channel) against
 * live GitLab/GitHub releases or git tags/branches, as appropriate for the
 * repo host.
 */
function gsp_release_resolve_channel_live(array $cfg)
{
	return gsp_release_resolve_channel($cfg, 'gsp_release_fetch_source_live', 'gsp_release_fetch_testing_branch_head_live');
}
