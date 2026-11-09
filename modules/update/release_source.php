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

if (!defined('RELEASE_USER_AGENT')) {
	// GitHub rejects API requests that send no User-Agent (HTTP 403).
	define('RELEASE_USER_AGENT', 'GSE-Panel-Update');
}

function release_components()
{
	return ['panel', 'agent_linux', 'agent_windows'];
}

function release_component_label($component)
{
	$labels = [
		'panel' => 'Panel',
		'agent_linux' => 'Agent (Linux)',
		'agent_windows' => 'Agent (Windows)',
	];
	return isset($labels[$component]) ? $labels[$component] : $component;
}

function is_valid_release_source_channel($channel)
{
	return in_array((string)$channel, ['stable', 'testing'], true);
}

/**
 * Settings are stored under release_<component>_repo_url,
 * release_<component>_channel, release_<component>_token_file.
 * Tokens are never stored in the settings table directly: only a path to a
 * secret file the panel reads at request time.
 */
if (!function_exists('default_repo_url')) {
function default_repo_url($component)
{
	$defaults = array(
		'panel' => 'https://github.com/GameServerEngine/gse-panel.git',
		'agent_windows' => 'https://github.com/GameServerEngine/gse-agent-windows.git',
		'agent_linux' => 'https://github.com/GameServerEngine/gse-agent-linux.git',
	);
	return isset($defaults[$component]) ? $defaults[$component] : '';
}
}

function release_source_settings($component)
{
	global $settings;
	$prefix = 'release_' . $component . '_';
	return [
		'component' => $component,
		'repo_url' => !empty($settings[$prefix . 'repo_url']) ? trim((string)$settings[$prefix . 'repo_url']) : default_repo_url($component),
		'channel' => is_valid_release_source_channel($settings[$prefix . 'channel'] ?? '')
			? (string)$settings[$prefix . 'channel']
			: 'stable',
		'token_file' => !empty($settings[$prefix . 'token_file']) ? trim((string)$settings[$prefix . 'token_file']) : '',
	];
}

function validate_release_source_settings(array $cfg)
{
	$errors = [];
	if ($cfg['repo_url'] !== '' && !preg_match('#^https?://#i', $cfg['repo_url'])) {
		$errors[] = release_component_label($cfg['component']) . ' repo URL must be http(s).';
	}
	if (!is_valid_release_source_channel($cfg['channel'])) {
		$errors[] = release_component_label($cfg['component']) . ' release channel must be stable or testing.';
	}
	if ($cfg['token_file'] !== '' && (strpos($cfg['token_file'], "\0") !== false || strpos($cfg['token_file'], '..') !== false || strpos($cfg['token_file'], '/') !== 0)) {
		$errors[] = release_component_label($cfg['component']) . ' token file must be a safe absolute path.';
	}
	return $errors;
}

function read_release_token(array $cfg)
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
function release_provider_from_url($repo_url)
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
function release_pick_stable(array $releases)
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
function release_pick_testing(array $releases)
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
 *   wrapper passes a git-tags-based fetcher instead (see release_fetch_tags_live). Tests inject
 *   whatever shape they need directly, independent of provider.
 * $testing_branch_head_fetcher: callable($repo_url, $token, $provider) -> ['tag' => 'testing', 'commit' => sha] or null.
 */
function release_resolve_channel(array $cfg, callable $releases_fetcher, callable $testing_branch_head_fetcher)
{
	$token = read_release_token($cfg);
	$provider = release_provider_from_url($cfg['repo_url']);
	$releases = $releases_fetcher($cfg['repo_url'], $token, $provider);

	if ($cfg['channel'] === 'stable') {
		$pick = is_array($releases) ? release_pick_stable($releases) : null;
		if ($pick === null) {
			return ['success' => false, 'error' => 'No stable (non-prerelease) release tag found for ' . release_component_label($cfg['component']) . '.'];
		}
		return ['success' => true, 'source' => 'release', 'tag' => $pick['tag'], 'release' => $pick];
	}

	// testing: newest prerelease, else the head of the `testing` branch.
	$pick = is_array($releases) ? release_pick_testing($releases) : null;
	if ($pick !== null) {
		return ['success' => true, 'source' => 'release', 'tag' => $pick['tag'], 'release' => $pick];
	}
	$branch_head = $testing_branch_head_fetcher($cfg['repo_url'], $token, $provider);
	if ($branch_head === null) {
		return ['success' => false, 'error' => 'No prerelease tag and no `testing` branch found for ' . release_component_label($cfg['component']) . '.'];
	}
	return ['success' => true, 'source' => 'branch', 'tag' => $branch_head['tag'], 'commit' => $branch_head['commit']];
}

/**
 * The checksum asset is always named after the archive with a .sha256
 * suffix, published alongside it in the same release.
 */
function release_checksum_asset_name($archive_filename)
{
	return basename((string)$archive_filename) . '.sha256';
}

/**
 * True if $file_path's SHA-256 digest matches $expected_hex (case-insensitive,
 * may be a bare hex digest or a "<hex>  <filename>" sha256sum-style line).
 */
function verify_sha256_file($file_path, $expected_hex)
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
function release_fetch_releases_live($repo_url, $token, $provider)
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
	curl_setopt($ch, CURLOPT_USERAGENT, RELEASE_USER_AGENT);
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
		if (!empty($entry['draft'])) {
			continue;
		}
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
function release_fetch_tags_live($repo_url, $token, $provider)
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
 * Release archive URL for a tag, in GitLab's archive format:
 *   <repo>/-/archive/<tag>/<name>-<tag>.zip
 */
/**
 * On GitHub, a release can attach a .zip asset with only the files an update
 * needs. Prefer that over the tag archive, which contains the whole repo.
 * Returns '' when the release has no zip asset or the lookup fails.
 */
function release_asset_url($repo_url, $tag)
{
	$base = rtrim(preg_replace('#\.git$#', '', trim((string)$repo_url)), '/');
	if ((string)$tag === '' || !function_exists('curl_init')) {
		return '';
	}
	if (!preg_match('#github\.com/([^/]+)/([^/]+)$#', $base, $m)) {
		return '';
	}
	$ch = curl_init('https://api.github.com/repos/' . $m[1] . '/' . $m[2] . '/releases/tags/' . rawurlencode((string)$tag));
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 15);
	curl_setopt($ch, CURLOPT_USERAGENT, RELEASE_USER_AGENT);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
	$body = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	if ($body === false || $code !== 200) {
		return '';
	}
	$data = json_decode($body, true);
	if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
		return '';
	}
	foreach ($data['assets'] as $asset) {
		$name = isset($asset['name']) ? (string)$asset['name'] : '';
		if (preg_match('/\.zip$/i', $name) && !empty($asset['browser_download_url'])) {
			return (string)$asset['browser_download_url'];
		}
	}
	return '';
}

function release_archive_url($repo_url, $tag)
{
	$base = rtrim(preg_replace('#\.git$#', '', trim((string)$repo_url)), '/');
	if (!preg_match('#/([^/]+)$#', $base, $m) || (string)$tag === '') {
		return '';
	}
	if (release_provider_from_url($base) === 'github') {
		$asset = release_asset_url($base, $tag);
		if ($asset !== '') {
			return $asset;
		}
		return $base . '/archive/refs/tags/' . rawurlencode((string)$tag) . '.zip';
	}
	return $base . '/-/archive/' . rawurlencode((string)$tag) . '/' . $m[1] . '-' . (string)$tag . '.zip';
}

/**
 * Downloads a release archive and returns its SHA-256 (lowercase hex), or
 * null if the download fails. The archive is removed afterwards.
 */
function release_archive_sha256($url)
{
	if ((string)$url === '' || !function_exists('curl_init')) {
		return null;
	}
	$tmp = tempnam(sys_get_temp_dir(), 'rel_');
	if ($tmp === false) {
		return null;
	}
	$fh = fopen($tmp, 'wb');
	if ($fh === false) {
		@unlink($tmp);
		return null;
	}
	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_USERAGENT, RELEASE_USER_AGENT);
	curl_setopt($ch, CURLOPT_FILE, $fh);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_FAILONERROR, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 300);
	$ok = curl_exec($ch);
	curl_close($ch);
	fclose($fh);
	if ($ok === false || filesize($tmp) === 0) {
		@unlink($tmp);
		return null;
	}
	$hash = hash_file('sha256', $tmp);
	@unlink($tmp);
	return $hash === false ? null : strtolower($hash);
}

/**
 * Maps an agent build name (as the agent reports it) to its release component.
 */
function release_component_for_agent($agent_component)
{
	return $agent_component === 'windows_agent' ? 'agent_windows' : 'agent_linux';
}

/**
 * Release tags for a component, cached for 10 minutes so the Update page does
 * not call the git host on every load. Null when the lookup fails (not cached).
 */
function release_tag_options($component)
{
	$cfg = release_source_settings($component);
	if ($cfg['repo_url'] === '') {
		return null;
	}
	$cache = sys_get_temp_dir() . '/gse-releases-v2-' . md5($cfg['repo_url']) . '.json';
	if (is_file($cache) && (time() - filemtime($cache)) < 600) {
		$cached = json_decode((string)file_get_contents($cache), true);
		if (is_array($cached)) {
			return $cached;
		}
	}
	$tags = release_tag_options_uncached($component);
	if (is_array($tags)) {
		@file_put_contents($cache, json_encode($tags));
	}
	return $tags;
}

function release_tag_options_uncached($component)
{
	$cfg = release_source_settings($component);
	if ($cfg['repo_url'] === '') {
		return null;
	}
	// Releases come from GitHub only.
	if (release_provider_from_url($cfg['repo_url']) !== 'github') {
		return null;
	}
	$token = read_release_token($cfg);
	$rows = release_fetch_releases_live($cfg['repo_url'], $token, 'github');
	if (!is_array($rows)) {
		return null;
	}
	// The Update page lists only releases named <channel>-<version>.
	$channels = array('stable', 'testing', 'working');
	$out = array();
	foreach ($rows as $row) {
		$tag = (string)$row['tag'];
		foreach ($channels as $channel) {
			$prefix = $channel . '-';
			if (strpos($tag, $prefix) === 0 && strlen($tag) > strlen($prefix)) {
				$out[] = array(
					'tag' => $tag,
					'channel' => $channel,
					'version' => substr($tag, strlen($prefix)),
					'created' => (string)$row['created_at'],
					'prerelease' => !empty($row['prerelease']),
				);
				break;
			}
		}
	}
	usort($out, function ($a, $b) use ($channels) {
		$ca = array_search($a['channel'], $channels, true);
		$cb = array_search($b['channel'], $channels, true);
		if ($ca !== $cb) {
			return $ca - $cb;
		}
		return strcmp($b['created'], $a['created']);
	});
	return $out;
}

/**
 * Production fetcher dispatch: github/gitlab use their releases API, anything
 * else falls back to git tags over the network.
 */
function release_fetch_source_live($repo_url, $token, $provider)
{
	if ($provider === 'github' || $provider === 'gitlab') {
		return release_fetch_releases_live($repo_url, $token, $provider);
	}
	return release_fetch_tags_live($repo_url, $token, $provider);
}

/**
 * Head commit of the `testing` branch, for the testing-channel fallback when
 * there's no prerelease tag. Works for any git remote, including self-hosted.
 */
function release_fetch_testing_branch_head_live($repo_url, $token, $provider)
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
function release_resolve_channel_live(array $cfg)
{
	return release_resolve_channel($cfg, 'release_fetch_source_live', 'release_fetch_testing_branch_head_live');
}
