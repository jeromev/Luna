<?php

/**
 * lunar Session class
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 * For more details, see <http://www.gnu.org/copyleft/gpl.html>
 *
 * @author		Jérôme Vogel
 * @license		http://www.gnu.org/copyleft/gpl.html	GPL
 * @link		https://github.com/jeromev/Luna
 * @package		Luna
 */
// {{{
class lunaSession implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface {
	/**
	 * instance
	 * @var self|null
	 */
	private static $instance;
	/**
	 * Session lifetime in seconds.
	 * @var		int
	 */
	public static $time_out = 604800; // 604800 = 7 days
	/**
	 * Session lifetime in seconds when the SID arrived in the request.
	 * @var		int
	 */
	public static $min_time_out = 300; // 300 = 5 minutes
	/**
	 * The signed-in user, assembled by get_user_data(); null before a session is read.
	 * @var		\stdClass|null
	 */
	public $user = null;
	/**
	 * save_path
	 * @var		string
	 */
	public static $save_path = '';
	/**
	 * sess_name
	 * @var		string
	 */
	public static $sess_name = '';
	/**
	 * Whether this request created the session rather than resuming one. Assigned 0/1.
	 * @var bool|int
	 */
	public static $new = true;
	// {{{ constructor
	/**
	 * Constructor.
	 * @return void
	 */
	private function __construct() {
		if (isset($_GET[session_name()]) || isset($_POST[session_name()])) {
			define('SID_IN', false);
		} else {
			define('SID_IN', true);
		}
		// Register $this as the save handler (object form, not the legacy 6-callback
		// form) so use_strict_mode can call validateId(): a client-supplied session id
		// that does not already exist server-side is rejected and PHP mints a fresh one,
		// so an attacker cannot pin a victim's session id (defence-in-depth atop the
		// regenerate-on-login already done in mod_log::login()). 'true' auto-registers
		// session_write_close() on shutdown.
		session_set_save_handler($this, true);
		self::$time_out = intval(luna::get_ini('config', 'session_length'));
		session_set_cookie_params([
			'lifetime' => self::$time_out,
			'path'     => luna::$site_relative_url ?: '/',
			'httponly' => true,
			'samesite' => 'Lax',
			'secure'   => (function_exists('luna_is_https') && luna_is_https()),
		]);
		ini_set('session.gc_maxlifetime', SID_IN ? self::$time_out : self::$min_time_out);
		define('SESSION_READY_TO_START', true);
	}
	// }}}
	// {{{ singleton()
	/**
	 * @return self
	 */
	public static function singleton(): self {
		if (!isset(self::$instance)) {
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}
	// }}}
	// {{{ __clone()
	/**
	 * @return void
	 */
	public function __clone() { trigger_error('Clone is not allowed.', E_USER_ERROR); }
	// }}}
	// {{{ start()
	/**
	 * Start session
	 *
	 * @return bool
	 */
	public function start(): bool {
		if (defined('SESSION_READY_TO_START') && SESSION_READY_TO_START == true) {
			session_start();
			return true;
		}
		return false;
	}
	// }}}
	// {{{ SessionHandlerInterface + SessionUpdateTimestampHandlerInterface
	/**
	 * PHP 8 needs an object handler to expose validateId() to use_strict_mode.
	 * These wrappers delegate to the existing session* methods; validateId() is
	 * the new strict-mode gate that refuses unknown (client-forged) session ids.
	 */
	#[\ReturnTypeWillChange] public function open($save_path, $session_name) { return $this->sessionOpen($save_path, $session_name); }
	#[\ReturnTypeWillChange] public function close() { return $this->sessionClose(); }
	#[\ReturnTypeWillChange] public function read($sid) { return $this->sessionRead($sid); }
	#[\ReturnTypeWillChange] public function write($sid, $data) { return $this->sessionWrite($sid, $data); }
	#[\ReturnTypeWillChange] public function destroy($sid) { return $this->sessionDestroy($sid); }
	#[\ReturnTypeWillChange] public function gc($maxlifetime) { return $this->sessionGc($maxlifetime); }
	#[\ReturnTypeWillChange] public function updateTimestamp($sid, $data) { return $this->sessionWrite($sid, $data); }
	#[\ReturnTypeWillChange] public function validateId($sid) {
		// Accept only a session id that already exists server-side; an unknown id
		// returns false so PHP (under use_strict_mode) discards it and generates a
		// fresh, server-side id instead of adopting the client's.
		$res = lunaDB::query('SELECT session_id FROM '.luna::get_ini('DBtables', 'SESSIONS').' WHERE session_id = '.lunaDB::quote($sid).'');
		$exists = (bool) $res->fetchRow();
		$res->free();
		return $exists;
	}
	// }}}
	// {{{ sessionOpen()
	/**
	 * create an empty user object & return true
	 *
	 * @return bool
	 */
	public function sessionOpen($save_path, $session_name): bool {
		$this->user = new stdclass();
		self::$save_path = $save_path;
		self::$sess_name = $session_name;
		self::$new = true;
		return true;
	}
	// }}}
	// {{{ sessionClose()
	/**
	 * do nothing here, return true
	 *
	 * @return bool
	 */
	public function sessionClose(): bool { return true; }
	// }}}
	// {{{ sessionRead()
	/**
	 * session read function
	 *
	 * @param string $sid
	 * @return mixed
	 */
	public function sessionRead(string $sid) {
		$this->user = $this->get_user_data($sid);
		if (!isset($this->user->nid)) {
			$this->sessionDestroy($sid);
			self::$new = 1;
			$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'SESSIONS').'
					(
						session_id,
						session_user_nid,
						session_start,
						session_time,
						session_ip,
						session_url,
						session_logged_in,
						session_lang,
						session_useragent
					)
				VALUES
					(
						'.lunaDB::quote($sid).',
						(SELECT nid FROM '.luna::get_ini('DBtables', 'NODES').' WHERE lid = '.lunaDB::quote(ANONYMOUS).'),
						'.lunaDB::quote(NOW).',
						'.lunaDB::quote(NOW).',
						'.lunaDB::quote(lunaTools::encode_ip()).',
						'.lunaDB::quote(luna::$path).',
						'.lunaDB::quote(false).',
						'.lunaDB::quote(luna::$lang).',
						'.lunaDB::quote($_SERVER['HTTP_USER_AGENT']).'
					)
			');
			$this->user = $this->get_user_data($sid);
		}
		if (!isset($this->user->nid)) {
			$this->sessionDestroy($sid);
			throw new lunaException(_('Error: cannot read session.'), PEAR_LOG_CRIT);
		}
		// Every session carries a CSRF synchronizer token; mint one on first read
		// (covers brand-new sessions and rows predating the csrf_token column).
		if (empty($this->user->csrf_token)) {
			$csrf = bin2hex(random_bytes(32));
			lunaDB::query('UPDATE '.luna::get_ini('DBtables', 'SESSIONS').' SET csrf_token = '.lunaDB::quote($csrf).' WHERE session_id = '.lunaDB::quote($sid).'');
			$this->user->csrf_token = $csrf;
		}
		// PHP's session handler requires read() to return a STRING (PHP 8 rejects a
		// non-string with "Failed to read session data"). luna keeps user state in
		// $this->user + the DB, so there is no PHP-serialised session payload.
		return '';
	}
	// }}}
	// {{{ sessionWrite()
	/**
	 * session function
	 *
	 * @return bool
	 */
	public function sessionWrite($sid, $data): bool {
		$res = lunaDB::query('
			UPDATE
				'.luna::get_ini('DBtables', 'SESSIONS').'
			SET
				session_user_nid = '.lunaDB::quote($this->user->nid).',
				session_time  = '.lunaDB::quote(NOW).',
				session_ip  = '.lunaDB::quote(lunaTools::encode_ip()).',
				session_url  = '.lunaDB::quote(luna::$path).',
				session_logged_in  = '.lunaDB::quote(($this->user->email == ANONYMOUS ? false : true)).',
				session_lang  = '.lunaDB::quote(luna::$lang).',
				session_useragent  = '.lunaDB::quote($_SERVER['HTTP_USER_AGENT']).'
			WHERE
				session_id = '.lunaDB::quote($sid).'
		');
		if ($this->user->email != ANONYMOUS) {
			$res = lunaDB::query('
				UPDATE
					'.luna::get_ini('DBtables', 'USERS').'
				SET
					last_time  = '.lunaDB::quote(NOW).',
					last_url  = '.lunaDB::quote(luna::$path).'
				WHERE
					nid = '.lunaDB::quote($this->user->nid).'
			');
		}
		return true;
	}
	// }}}
	// {{{ sessionDestroy()
	/**
	 * session function
	 *
	 * @return bool
	 */
	public function sessionDestroy($sid): bool {
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'SESSIONS').'
			WHERE
				session_id = '.lunaDB::quote($sid).'
		');
		$_SESSION = [];
		if (isset($_COOKIE[session_name()])) { lunaTools::set_cookie(session_name(), '', time() - 42000); }
		if (isset($_POST['PHPSESSID'])) { unset($_POST['PHPSESSID']); }
		if (isset($_GET['PHPSESSID'])) { unset($_GET['PHPSESSID']); }
		lunaDB::optimise([luna::get_ini('DBtables', 'SESSIONS')]);
		return true;
	}
	// }}}
	// {{{ sessionGc()
	/**
	 * session function
	 *
	 * SessionHandlerInterface::gc() is specified to return the number of sessions deleted, or
	 * false on failure — not a bool. Returning true here was an interface violation that the
	 * #[\ReturnTypeWillChange] attribute on gc() was hiding.
	 *
	 * @return int|false
	 */
	public function sessionGc($sLifeTime): int|false {
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'SESSIONS').'
			WHERE
				session_time < '.lunaDB::quote(NOW - $sLifeTime).'
		');
		lunaDB::optimise([luna::get_ini('DBtables', 'SESSIONS')]);
		return ($res instanceof lunaResult) ? $res->rowCount() : false;
	}
	// }}}
	// {{{ get_user_data()
	/**
	 * get_user_data
	 *
	 * @param string $sid
	 * @return \stdClass|false
	 */
	public function get_user_data($sid = ''): \stdClass|bool {
		if (!is_string($sid) || empty($sid)) { return false; }
		$sid = substr($sid, 0, 32);
		$sLifeTime = SID_IN ? self::$time_out : self::$min_time_out;
		$user = new stdclass();
		$res = lunaDB::query('
			SELECT DISTINCT
				u.nid as user_nid,
				u.is_active,
				users.firstname,
				users.lastname,
				u.lid as email,
				users.regis_time,
				users.last_time,
				users.last_url,
				users.lang,
				s.session_id,
				s.session_start,
				s.session_time,
				s.session_url,
				s.session_lang,
				s.session_logged_in,
				s.session_ip,
				s.session_useragent,
				s.csrf_token,
				g.nid as group_nid,
				l.nid as level_nid
			FROM
				'.luna::get_ini('DBtables', 'USERS').' users,
				'.luna::get_ini('DBtables', 'SESSIONS').' s,
				'.luna::get_ini('DBtables', 'NODES').' u,
				'.luna::get_ini('DBtables', 'NODES').' l,
				'.luna::get_ini('DBtables', 'NODES').' g,
				'.luna::get_ini('DBtables', 'NODES_MAP').' gl,
				'.luna::get_ini('DBtables', 'NODES_MAP').' ug,
				'.luna::get_ini('DBtables', 'CLASSES').' tu,
				'.luna::get_ini('DBtables', 'CLASSES').' tg,
				'.luna::get_ini('DBtables', 'CLASSES').' tl
			WHERE
				u.nid = users.nid
				AND s.session_user_nid = u.nid
				AND s.session_id = '.lunaDB::quote($sid).'
				AND s.session_time > '.lunaDB::quote(NOW - $sLifeTime).'
				AND s.session_useragent = '.lunaDB::quote($_SERVER['HTTP_USER_AGENT']).'
				AND s.session_ip = '.lunaDB::quote(lunaTools::encode_ip()).'
				AND u.tid = tu.id AND tu.lid = '.lunaDB::quote('user').'
				AND g.tid = tg.id AND tg.lid = '.lunaDB::quote('group').'
				AND l.tid = tl.id AND tl.lid = '.lunaDB::quote('level').'
				AND ug.nid1 = u.nid AND ug.nid2 = g.nid
				AND gl.nid1 = g.nid AND gl.nid2 = l.nid
				AND g.is_active = 1
				AND l.is_active = 1
		');
		while ($row = $res->fetchRow()) {
			$user->nid = $row->user_nid;
			$user->is_active = $row->is_active;
			$user->firstname = $row->firstname;
			$user->lastname = $row->lastname;
			$user->email = $row->email;
			$user->regis_time = $row->regis_time;
			$user->last_time = $row->last_time;
			$user->last_url = $row->last_url;
			$user->session_id = $row->session_id;
			$user->session_start = $row->session_start;
			$user->session_time = $row->session_time;
			$user->session_url = $row->session_url;
			$user->session_lang = $row->session_lang;
			$user->session_logged_in = $row->session_logged_in;
			$user->session_ip = lunaTools::decode_ip($row->session_ip);
			$user->lang = $row->lang;
			$user->session_useragent = $row->session_useragent;
			$user->csrf_token = $row->csrf_token;
			$user->groups[$row->group_nid] = $row->group_nid;
			$user->levels[$row->level_nid] = $row->level_nid;
			self::$new = 0;
		}
		$res->free();
		if (isset($user->last_time) && NOW - $user->last_time <= 1) { sleep(1); }
		return $user;
	}
	// }}}
}
// }}}
