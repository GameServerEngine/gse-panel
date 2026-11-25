<?php
/*
 * The panel's own files, presented to LiteFM exactly like an agent machine. LiteFM pages call the same
 * methods on this object that they call on RemoteLibrary (list a folder, read, write, copy, move,
 * rename, delete, compress, upload, download), so an administrator gets the very same file manager
 * the customers see, on the panel's files: view, edit, download, upload and test LiteFM itself.
 * Every write to an existing file keeps a copy in logs/litefm-backups, and PHP files must pass
 * php -l first so one bad save cannot take the panel down.
 */

class LocalPanelRemote
{
	private $root;

	public function __construct($root)
	{
		$this->root = rtrim((string)$root, '/');
	}

	private function items($list)
	{
		$out = array();
		foreach (preg_split('/\\\\n|\n/', (string)$list) as $item) {
			$item = trim($item);
			if ($item !== '') {
				$out[] = $item;
			}
		}
		return $out;
	}

	private function backup($path)
	{
		if (!is_file($path)) {
			return;
		}
		$dir = $this->root . '/logs/litefm-backups';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		@copy($path, $dir . '/' . date('Ymd-His') . '_' . str_replace('/', '_', ltrim(substr($path, strlen($this->root)), '/')));
	}

	private function lint($text)
	{
		$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
		if (!function_exists('exec') || in_array('exec', $disabled, true)) {
			return true;
		}
		$tmp = tempnam(sys_get_temp_dir(), 'litefmlint');
		file_put_contents($tmp, $text);
		$bin = (defined('PHP_BINARY') && basename(PHP_BINARY) === 'php') ? PHP_BINARY : 'php';
		$out = array();
		$status = 1;
		exec(escapeshellarg($bin) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $status);
		unlink($tmp);
		return $status === 0;
	}

	private function remove_tree($path)
	{
		if (is_link($path) || is_file($path)) {
			return @unlink($path);
		}
		foreach ((array)@scandir($path) as $name) {
			if ($name !== '.' && $name !== '..') {
				$this->remove_tree($path . '/' . $name);
			}
		}
		return @rmdir($path);
	}

	private function copy_tree($from, $to)
	{
		if (is_dir($from)) {
			if (!is_dir($to) && !@mkdir($to, 0775, true)) {
				return false;
			}
			foreach ((array)scandir($from) as $name) {
				if ($name !== '.' && $name !== '..' && !$this->copy_tree($from . '/' . $name, $to . '/' . $name)) {
					return false;
				}
			}
			return true;
		}
		return @copy($from, $to);
	}

	public function what_os()
	{
		return PHP_OS;
	}

	public function status_chk()
	{
		return 1;
	}

	public function rfile_exists($file)
	{
		return file_exists($file) ? 1 : 0;
	}

	public function remote_dirlistfm($path)
	{
		if (!is_dir($path)) {
			return -2;
		}
		$list = array('directorys' => array(), 'files' => array());
		foreach ((array)scandir($path) as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}
			$full = $path . '/' . $name;
			$owner = function_exists('posix_getpwuid') ? @posix_getpwuid(@fileowner($full)) : false;
			$group = function_exists('posix_getgrgid') ? @posix_getgrgid(@filegroup($full)) : false;
			$entry = array('filename' => $name, 'user' => $owner ? $owner['name'] : '', 'group' => $group ? $group['name'] : '');
			if (is_dir($full)) {
				$list['directorys'][] = $entry;
			} else {
				$entry['size'] = (int)@filesize($full);
				$entry['attr'] = '-------------';
				$list['files'][] = $entry;
			}
		}
		return $list;
	}

	// 1 and the text on success, 0 when the file is missing.
	public function remote_readfile($path, &$data)
	{
		$path = trim($path);
		if (!is_file($path)) {
			return 0;
		}
		$data = (string)file_get_contents($path);
		return 1;
	}

	public function remote_writefile($path, $content)
	{
		if (substr($path, -4) === '.php' && !$this->lint((string)$content)) {
			return 0;
		}
		$this->backup($path);
		return @file_put_contents($path, (string)$content, LOCK_EX) === strlen((string)$content) ? 1 : 0;
	}

	// The whole file in one part; the offset returned is the file size.
	public function remote_get_file_part($file, $offset, &$data)
	{
		if (!is_file($file)) {
			return -1;
		}
		$data = (string)file_get_contents($file);
		return strlen($data);
	}

	public function shell_action($action, $arguments)
	{
		switch ($action) {
			case 'create_dir':
				return @mkdir($arguments, 0775, true) ? 1 : 0;
			case 'remove_file':
				return @unlink($arguments) ? 1 : 0;
			case 'remove_recursive':
				$ok = 1;
				foreach ($this->items($arguments) as $item) {
					$ok = $this->remove_tree($item) ? $ok : 0;
				}
				return $ok;
			case 'rename':
			case 'move':
				$parts = explode(';', $arguments, 2);
				return count($parts) === 2 && @rename($parts[0], $parts[1]) ? 1 : 0;
			case 'copy':
				$parts = explode(';', $arguments, 2);
				return count($parts) === 2 && $this->copy_tree($parts[0], rtrim($parts[1], '/') . '/' . basename($parts[0])) ? 1 : 0;
			case 'touch':
				return @touch($arguments) ? 1 : 0;
			case 'size':
				return is_file($arguments) ? (int)filesize($arguments) : 0;
		}
		return 0;
	}

	public function secure_path($action, $path)
	{
		return 0;
	}

	public function exec($command)
	{
		return (string)@shell_exec($command . ' 2>&1');
	}

	// Uploads: LiteFM saves the file under uploads/ and tells the "agent" to fetch it. Here it is copied from there.
	public function start_file_download($url, $path, $filename)
	{
		$name = basename((string)parse_url($url, PHP_URL_PATH));
		foreach (glob($this->root . '/modules/litefm/uploads/home_id_panel/*') as $candidate) {
			if (basename($candidate) === $name) {
				@copy($candidate, rtrim($path, '/') . '/' . $filename);
				break;
			}
		}
		return 'local';
	}

	public function is_file_download_in_progress($pid)
	{
		return 0;
	}

	public function rsync_progress($file)
	{
		return is_file($file) ? (int)(filesize($file) / 1024) : 0;
	}

	public function compress_files($files, $destination, $archive_name, $archive_type)
	{
		$names = array();
		foreach ($this->items($files) as $item) {
			$names[] = escapeshellarg(basename($item));
		}
		if (!$names) {
			return -1;
		}
		$archive = escapeshellarg(rtrim($destination, '/') . '/' . $archive_name . '.' . $archive_type);
		$cd = 'cd ' . escapeshellarg($destination) . ' && ';
		switch ($archive_type) {
			case 'zip':
				$cmd = $cd . 'zip -qr ' . $archive . ' ' . implode(' ', $names);
				break;
			case 'tar':
				$cmd = $cd . 'tar -cf ' . $archive . ' ' . implode(' ', $names);
				break;
			case 'bz2':
			case 'tar.bz2':
				$archive = escapeshellarg(rtrim($destination, '/') . '/' . $archive_name . '.tar.bz2');
				$cmd = $cd . 'tar -cjf ' . $archive . ' ' . implode(' ', $names);
				break;
			default:
				$archive = escapeshellarg(rtrim($destination, '/') . '/' . $archive_name . '.tar.gz');
				$cmd = $cd . 'tar -czf ' . $archive . ' ' . implode(' ', $names);
		}
		@exec($cmd . ' 2>&1', $out, $status);
		return $status === 0 ? 1 : -1;
	}

	public function uncompress_file($file, $destination)
	{
		$cd = 'cd ' . escapeshellarg($destination) . ' && ';
		$cmd = preg_match('/\.zip$/i', $file) ? $cd . 'unzip -qo ' . escapeshellarg($file) : $cd . 'tar -xf ' . escapeshellarg($file);
		@exec($cmd . ' 2>&1', $out, $status);
		return $status === 0 ? 1 : -1;
	}
}
?>
