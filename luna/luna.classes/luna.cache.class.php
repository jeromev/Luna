<?php

/**
 * lunaCache — a minimal native file cache.
 *
 * Drop-in replacement for the slice of PEAR Cache_Lite the app actually used (Cache_Lite
 * removed 0.8.29-alpha): a keyed file store under a cache directory, with a TTL. The app
 * wraps every value in its own serialize() / unserialize(..., ['allowed_classes'=>false]),
 * so this is a dumb *string* store — no automatic (un)serialization, hence none of
 * Cache_Lite's deserialization sink. It implements exactly the API the call sites use:
 *
 *   new lunaCache(['cacheDir' => …, 'lifetime' => …])
 *   $c->get($id)                 // cached string, or false (miss / expired)
 *   $c->save($data[, $id])       // $id defaults to the last get()'s id, as Cache_Lite did
 *   $c->clean()                  // drop every entry in the cache dir
 *
 * @author   Jérôme Vogel
 * @license  http://www.gnu.org/copyleft/gpl.html  GPL
 * @package  lunarSystem
 */
class lunaCache {
	/** @var string cache directory, with a trailing slash */
	private $dir;
	/** @var int|null TTL in seconds (null = never expire) */
	private $lifetime;
	/** @var string|null id of the last get(), reused by save() when no id is passed */
	private $last_id = null;
	/** @var string filename prefix (matches Cache_Lite's default, so clean() also sweeps any legacy files) */
	private $prefix = 'cache_';

	// {{{ __construct()
	public function __construct($options = []) {
		$dir = (isset($options['cacheDir']) && $options['cacheDir'] !== '') ? $options['cacheDir'] : sys_get_temp_dir();
		$this->dir = rtrim($dir, '/').'/';
		$this->lifetime = array_key_exists('lifetime', $options) ? (is_null($options['lifetime']) ? null : (int) $options['lifetime']) : 3600;
	}
	// }}}

	// {{{ get()
	/**
	 * @param string $id
	 * @param string $group
	 * @return string|false
	 */
	public function get(string $id, string $group = 'default'): string|false {
		$this->last_id = $id;
		$file = $this->file($id, $group);
		if (!is_file($file)) { return false; }
		if ($this->lifetime !== null && (time() - (int) @filemtime($file)) > $this->lifetime) { return false; }
		$data = @file_get_contents($file);
		return ($data === false) ? false : $data;
	}
	// }}}

	// {{{ save()
	/**
	 * @param string $data
	 * @param string|null $id  defaults to the id of the most recent get()
	 * @param string $group
	 * @return bool
	 */
	public function save(string $data, ?string $id = null, string $group = 'default'): bool {
		if ($id === null) { $id = $this->last_id; }
		if ($id === null) { return false; }
		$file = $this->file($id, $group);
		// Write to a unique temp file then atomically rename, so a concurrent get()
		// can never read a half-written entry (Cache_Lite's optional fileLocking,
		// which the app never enabled, addressed the same race more clumsily).
		$tmp = $file.'.'.getmypid().'.tmp';
		if (@file_put_contents($tmp, (string) $data) === false) { @unlink($tmp); return false; }
		if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
		return true;
	}
	// }}}

	// {{{ clean()
	/**
	 * Drop every cache entry in the directory.
	 * @return bool
	 */
	public function clean(): bool {
		foreach ((array) @glob($this->dir.$this->prefix.'*') as $f) {
			if (is_file($f)) { @unlink($f); }
		}
		return true;
	}
	// }}}

	// {{{ file()
	/** Hash the id (+ group) into a safe, fixed-length filename. */
	private function file($id, $group) {
		return $this->dir.$this->prefix.md5($group.'_'.(string) $id);
	}
	// }}}
}
