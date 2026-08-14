<?php

/**
 * lunar Exception class
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 * For more details, see <http://www.gnu.org/copyleft/gpl.html>
 *
 * @author		Jérôme Vogel
 * @license		http://www.gnu.org/copyleft/gpl.html  GPL
 * @link		https://github.com/jeromev/LunarSystem
 * @package		lunarSystem
 */

// PEAR_LOG_* priority constants — formerly supplied by the vendored PEAR Log base
// class (now removed). They are the severity codes passed to lunaException() /
// lunaLog::log() across the app; lunaLog::log() writes the row directly via the PDO
// lunaDB layer below, and lunaLog::priorityToString() (used by mod_journal) replaces
// the old Log::priorityToString(). Values are the stable syslog levels (0–7).
define('PEAR_LOG_EMERG', 0); // system is unusable
define('PEAR_LOG_ALERT', 1); // immediate action required
define('PEAR_LOG_CRIT', 2); // critical conditions
define('PEAR_LOG_ERR', 3); // error conditions
define('PEAR_LOG_WARNING', 4); // warning conditions
define('PEAR_LOG_NOTICE', 5); // normal but significant
define('PEAR_LOG_INFO', 6); // informational
define('PEAR_LOG_DEBUG', 7); // debug-level messages
// {{{
class lunaException extends Exception { public $session; public $server; }
// }}}
// {{{
class lunaLog {
	// {{{
	/**
	 * log
	 * @param object|string $e
	 * @param int|string $code
	 * @return bool
	 */
	public static function log($e, int|string $code = PEAR_LOG_ERR): bool {
		if (!luna::get_ini('DBtables', 'LOGS')) { return false; }
		if (is_object($e) && get_class($e) == 'lunaException') {
			$payload = [
				'message' => $e->getMessage(),
				'code'    => $e->getCode(),
				'session' => self::session_summary(),
				'server'  => self::server_whitelist(),
			];
			$priority = $e->getCode() ? (int) $e->getCode() : PEAR_LOG_ERR;
		} elseif (is_string($e)) {
			$payload = [
				'message' => $e,
				'session' => self::session_summary(),
				'server'  => self::server_whitelist(),
			];
			$priority = (int) $code;
		} else {
			return false;
		}
		// Store the payload as JSON, never serialize(): the journal reader then never
		// has to unserialize() attacker-influenceable data (no object-injection sink).
		$message = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
		// Direct INSERT — replaces the old PEAR Log 'mdb2' handler. logtime
		// auto-fills (CURRENT_TIMESTAMP). Never let a logging failure throw (it
		// would recurse through the exception path), so swallow any DB error.
		try {
			lunaDB::query('INSERT INTO '.luna::get_ini('DBtables', 'LOGS').'
				(ident, priority, message) VALUES ('
				.lunaDB::quote('luna').', '
				.lunaDB::quote($priority).', '
				.lunaDB::quote($message).')');
		} catch (Exception $ex) {
			return false;
		}
		return true;
	}
	// }}}
	// {{{ session_summary()
	/**
	 * The minimal identity to attach to a log line (who was acting). Avoids
	 * persisting the whole session object (csrf token, levels, internals).
	 */
	private static function session_summary() {
		$u = isset(luna::$session->user) ? luna::$session->user : null;
		if (!is_object($u)) { return null; }
		return ['user' => [
			'firstname' => isset($u->firstname) ? $u->firstname : null,
			'lastname'  => isset($u->lastname) ? $u->lastname : null,
			'email'     => isset($u->email) ? $u->email : (isset($u->lid) ? $u->lid : null),
		]];
	}
	// }}}
	// {{{ server_whitelist()
	/**
	 * A trimmed copy of $_SERVER for logging. The full $_SERVER carries the
	 * cookie header (incl. the session id), auth headers and env, which should
	 * not be persisted into luna_logs on every error.
	 *
	 * @return array
	 */
	private static function server_whitelist(): array {
		$keep = ['REMOTE_ADDR', 'REQUEST_METHOD', 'REQUEST_URI', 'SERVER_NAME', 'HTTP_HOST', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'REQUEST_TIME'];
		$server = [];
		foreach ($keep as $k) { if (isset($_SERVER[$k])) { $server[$k] = $_SERVER[$k]; } }
		return $server;
	}
	// }}}
	// {{{ priorityToString()
	/**
	 * Map a PEAR_LOG_* priority integer to its label — replaces the removed PEAR
	 * Log::priorityToString(); used by the journal views (the label is passed through
	 * _() for i18n, so the strings must match the legacy ones).
	 * @param int $priority
	 * @return string
	 */
	public static function priorityToString(int $priority): string {
		$levels = [
			PEAR_LOG_EMERG   => 'emergency',
			PEAR_LOG_ALERT   => 'alert',
			PEAR_LOG_CRIT    => 'critical',
			PEAR_LOG_ERR     => 'error',
			PEAR_LOG_WARNING => 'warning',
			PEAR_LOG_NOTICE  => 'notice',
			PEAR_LOG_INFO    => 'info',
			PEAR_LOG_DEBUG   => 'debug',
		];
		return isset($levels[$priority]) ? $levels[$priority] : 'unknown';
	}
	// }}}
}
// }}}
