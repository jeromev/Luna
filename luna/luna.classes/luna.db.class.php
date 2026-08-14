<?php

/**
 * luna DB class
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 * For more details, see <http://www.gnu.org/copyleft/gpl.html>
 *
 * @author		Jérôme Vogel
 * @license		http://www.gnu.org/copyleft/gpl.html  GPL
 * @link		https://github.com/jeromev/Luna
 * @package		Luna
 *
 * PDO/pdo_mysql rewrite (0.5.0-alpha): the original used the unmaintained PEAR
 * MDB2 + ext/mysql (removed in PHP 7). This keeps the exact public surface the
 * app depends on — lunaDB::query/quote/get/nextID/optimise + a result object
 * answering ->fetchRow() (a stdClass row, via PDO::FETCH_OBJ) and ->free().
 * quote() reproduces MDB2's auto-typed quoting (verified against the live MDB2
 * stack): null/'' -> NULL, bool -> 0/1, int -> bare, float/string -> quoted.
 */
// {{{
class lunaDB {
	/**
	 * pdo — the singleton PDO connection
	 * @var		PDO
	 */
	private static $pdo = null;
	private static $user = '';
	private static $pass = '';
	// {{{ prepare()
	/**
	 * Resolve the DB credentials and build the PDO DSN. Defines DSN so the boot-order
	 * guards (`if (!defined('DSN'))`) elsewhere keep their contract.
	 *
	 * Precedence: an explicit `db.ini` (manual installs, or a per-domain override) wins;
	 * otherwise fall back to the environment (`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`), so
	 * the Docker stack — and any clone-and-run — connects with no `db.ini` to provision,
	 * mirroring the `SPARQL_*` env defaults in luna.php. Only fail if neither is present.
	 * @return bool
	 */
	public static function prepare(): bool {
		if (file_exists(INI_PATH.'db.ini')) {
			$c = parse_ini_file(INI_PATH.'db.ini');
		} else {
			$c = [
				'host'     => getenv('DB_HOST') ?: '',
				'database' => getenv('DB_NAME') ?: '',
				'username' => getenv('DB_USER') ?: '',
				'password' => getenv('DB_PASS') ?: '',
			];
			if ($c['database'] === '' && $c['username'] === '') { throw new lunaException(_('Error: no database configuration — create db.ini or set DB_HOST/DB_NAME/DB_USER/DB_PASS.'), PEAR_LOG_CRIT); }
		}
		$host = (isset($c['host']) && $c['host'] !== '') ? $c['host'] : 'localhost';
		$name = isset($c['database']) ? $c['database'] : '';
		self::$user = isset($c['username']) ? $c['username'] : '';
		self::$pass = isset($c['password']) ? $c['password'] : '';
		if (!defined('DSN')) { define('DSN', 'mysql:host='.$host.';dbname='.$name.';charset=utf8mb4'); }
		return true;
	}
	// }}}
	// {{{ connect()
	/**
	 * Open the PDO connection (idempotent — get() builds it on demand).
	 * @return bool
	 */
	public static function connect(): bool {
		if (!defined('DSN')) { return false; }
		self::get();
		return true;
	}
	// }}}
	// {{{ get()
	/**
	 * The singleton PDO handle. Throws lunaException on connect failure, matching
	 * the original throw-on-PEAR::isError contract.
	 * @return mixed PDO|false
	 */
	public static function get() {
		if (!defined('DSN')) { return false; }
		if (self::$pdo instanceof PDO) { return self::$pdo; }
		try {
			self::$pdo = new PDO(DSN, self::$user, self::$pass, [
				PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
				PDO::ATTR_EMULATE_PREPARES   => true,
				PDO::ATTR_STRINGIFY_FETCHES  => true,
			]);
		} catch (PDOException $e) {
			throw new lunaException($e->getMessage(), PEAR_LOG_CRIT);
		}
		return self::$pdo;
	}
	// }}}
	// {{{ query()
	/**
	 * Run a SQL statement. Returns a lunaResult (->fetchRow()/->free()) on success,
	 * false on no-DSN/empty-sql, and throws lunaException on a DB error.
	 * @param string|false $sql
	 * @return mixed lunaResult|false
	 */
	public static function query($sql = false) {
		if (!defined('DSN')) { return false; }
		if (!empty($sql) && is_string($sql)) {
			try {
				$stmt = self::get()->query($sql);
			} catch (PDOException $e) {
				throw new lunaException($e->getMessage(), PEAR_LOG_ERR);
			}
			return new lunaResult($stmt);
		}
		return false;
	}
	// }}}
	// {{{ optimise()
	/**
	 * @param array|false $tables
	 * @return bool
	 */
	public static function optimise($tables = false): bool {
		if (!defined('DSN')) { return false; }
		try {
			if (!is_array($tables) || empty($tables)) { $tables = luna::$ini['DBtables']; }
			$sql = '';
			foreach ($tables as $t) { $sql .= $t.','; }
			$sql = substr($sql, 0, -1);
			lunaDB::query('OPTIMIZE TABLE '.$sql);
		} catch (lunaException $e) {
			lunaLog::log($e);
			die();
		}
		return true;
	}
	// }}}
	// {{{ nextID()
	/**
	 * Allocate the next id from a sequence table, reproducing MDB2's on-demand
	 * sequence emulation exactly: INSERT a row into `<name>_seq`, read its
	 * AUTO_INCREMENT via lastInsertId(), and prune older rows. Returns an int.
	 * @param string|false $seq the base table name (e.g. luna_nodes -> luna_nodes_seq)
	 * @return mixed int|false
	 */
	public static function nextID($seq = false) {
		if (!defined('DSN') || empty($seq) || !is_string($seq)) { return false; }
		$pdo = self::get();
		$table = $seq.'_seq';
		try {
			$pdo->exec('INSERT INTO `'.$table.'` (`sequence`) VALUES (NULL)');
			$id = (int) $pdo->lastInsertId();
			if ($id > 0) { $pdo->exec('DELETE FROM `'.$table.'` WHERE `sequence` < '.$id); }
		} catch (PDOException $e) {
			throw new lunaException($e->getMessage(), PEAR_LOG_ERR);
		}
		return $id;
	}
	// }}}
	// {{{ quote()
	/**
	 * Quote a value for SQL, reproducing MDB2's auto-typed quoting (verified
	 * against the live MDB2 stack): null/'' -> NULL (unquoted), bool -> 0/1,
	 * int -> bare integer, float/string -> driver-escaped quoted string. A raw
	 * PDO::quote() would mis-handle ints/bools/null, so the type dispatch matters.
	 * @param mixed $str
	 * @return mixed string|false
	 */
	public static function quote($str = '') {
		if (!defined('DSN')) { return false; }
		if ($str === null || $str === '') { return 'NULL'; }
		if (is_bool($str)) { return $str ? '1' : '0'; }
		if (is_int($str)) { return (string) $str; }
		return self::get()->quote((string) $str);
	}
	// }}}
}
// }}}
// {{{
/**
 * Thin result wrapper so call sites keep using $res->fetchRow() (returns a
 * stdClass row, or false at end of set) and $res->free().
 */
class lunaResult {
	private $stmt;
	// {{{ constructor
	public function __construct($stmt) { $this->stmt = $stmt; }
	// }}}
	// {{{ fetchRow()
	/**
	 * @return mixed stdClass|false
	 */
	public function fetchRow() {
		if (!($this->stmt instanceof PDOStatement)) { return false; }
		return $this->stmt->fetch(PDO::FETCH_OBJ);
	}
	// }}}
	// {{{ rowCount()
	/**
	 * Rows affected by the last INSERT/UPDATE/DELETE on this statement.
	 * @return int
	 */
	public function rowCount(): int {
		if (!($this->stmt instanceof PDOStatement)) { return 0; }
		return $this->stmt->rowCount();
	}
	// }}}
	// {{{ free()
	/**
	 * @return bool
	 */
	public function free(): bool {
		if ($this->stmt instanceof PDOStatement) { $this->stmt->closeCursor(); }
		return true;
	}
	// }}}
}
// }}}
