<?php

/**
 * lunar mod_journal module
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
 */
// {{{
class mod_journal {
	/**
	 * instance
	 * @var self|null
	 */
	private static $instance;
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
	public function __clone() { trigger_error('Lunar clones are not allowed.', E_USER_ERROR); }
	// }}}
	// {{{ constructor
	/**
	 * Constructor.
	 * @return void
	 */
	private function __construct() {
		// This list must match what the two journal views actually ask for, in both directions —
		// a lid registered here but named by no stylesheet is dead weight the catalogues get asked
		// to translate, and a label the view renders but that is missing here falls through to
		// luna.journal.html.xsl's <xsl:otherwise> and prints the bare field name in every language.
		// Both drifts were present until 0.9.5-alpha: six lids were registered for fields the log
		// node does not have ('id', 'type', 'user', 'error', 'user-name', 'Server' — the last of
		// these belonging to a block that has been commented out in the stylesheet), while 'lid'
		// and 'content' were rendered untranslated. 'content' was the sharper case: its French
		// 'contenu' has been in the catalogue all along, unreachable because only mod_edit_texts
		// registered it and this is the only mod the journal page loads.
		lunaTools::add_vocabulary([
			// the log LIST view — literal lookups, column headings
			'List of the log entries',
			'Message',
			'Type',
			'Date',
			'User',
			// the log ANALYSE view — its <dt> labels are looked up by local-name() over the
			// children of the ui:log node built in load(), so this list must track that array:
			// lid, message, code, date, content.
			'Log entry analyse',
			'lid',
			'message',
			'code',
			'date',
			'content'
		]);
	}
	// }}}
	// {{{ load()
	/**
	 * @return bool
	 */
	public function load(): bool {
		$inerror = 0;
		// POST-only: request() also reads GET, so a forged link/img could wipe the
		// whole log with a single request. Require a real form POST.
		if (isset($_POST['purgelogs']) && $_SERVER['REQUEST_METHOD'] === 'POST'
			&& hash_equals((string) luna::$session->user->csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
			$res = lunaDB::query('
				DELETE FROM
					'.luna::get_ini('DBtables', 'LOGS').'
			');
		}
		$log_id = false;
		if ($log_id = lunaTools::request('log_id')) {
			luna::$data['log_id'] = $log_id;
			$res = lunaDB::query('
				SELECT
					*
				FROM
					'.luna::get_ini('DBtables', 'LOGS').'
				WHERE
					id = '.lunaDB::quote(intval($log_id)).'
			');
			while ($row = $res->fetchRow()) {
				$msg = self::decode_message($row->message);
				$message = lunaTools::display_string(isset($msg->message) ? $msg->message : '');
				$var = [
					'type' => 'log',
					'lid' => $row->id,
					'value' => [
						'message' => $message,
						'code' => _(lunaLog::priorityToString($row->priority)),
						'date' => $row->logtime,
						'content' => print_r($msg, true)
					],
				];
				if (!luna::model()->merge_index(luna::model()->load_var($var))) { throw new lunaException(_('Error: cannot load log entry.'), PEAR_LOG_CRIT); }
			}
			$res->free();
		} else {
			$cookie = [];
			if (isset($_COOKIE[luna::$data['lid'].'_sort'])) {
				// json_decode (not unserialize) to avoid PHP object injection from a crafted cookie.
				$cookie = json_decode($_COOKIE[luna::$data['lid'].'_sort'], true);
				$cookie = is_array($cookie) ? lunaTools::sanitize($cookie) : [];
				if (!is_array($cookie)) { $cookie = []; }
				foreach ($cookie as $k => $v) { $_COOKIE[$k] = $v; }
			}
			// Whitelist the sort column: it is interpolated as a SQL identifier into
			// COUNT()/ORDER BY below, so it must never come straight from request input.
			$order_by = lunaTools::request('order_by', 0, 'logtime');
			$allowed_order_by = ['logtime', 'id', 'priority', 'ident'];
			if (!in_array($order_by, $allowed_order_by, true)) { $order_by = 'logtime'; }
			luna::$data['order_by'] = $order_by;
			$cookie['order_by'] = luna::$data['order_by'];
			$order_by_ok = 'l.'.$order_by;
			$order_dir = (lunaTools::request('order_dir', 0, 'DESC') == 'ASC') ? 'ASC' : 'DESC';
			luna::$data['order_dir'] = $order_dir;
			$cookie['order_dir'] = luna::$data['order_dir'];
			if (!defined('PERPAGE')) { define('PERPAGE', 20); }
			luna::$data['limit'] = intval(lunaTools::request('limit', 0, PERPAGE));
			if (luna::$data['limit'] < 1) { luna::$data['limit'] = PERPAGE; }
			$cookie['limit'] = luna::$data['limit'];
			if (isset($_GET['start'])) {
				$start = $_GET['start'];
			} else {
				$start = lunaTools::request('start', $_GET);
			}
			$start = intval($start);
			if ($start < 0) { $start = 0; }
			luna::$data['start'] = $start;
			$cookie['start'] = luna::$data['start'];
			if (!lunaTools::set_cookie(luna::$data['lid'].'_sort', $cookie)) { throw new lunaException(_('Error: cannot set cookie.'), PEAR_LOG_CRIT); }
			$res = lunaDB::query('
				SELECT
					COUNT('."$order_by_ok".') as total
				FROM
					'.luna::get_ini('DBtables', 'LOGS').' l
			');
			$row = $res->fetchRow();
			$res->free();
			$total = $row->total;
			$res = lunaDB::query('
				SELECT
					*
				FROM
					'.luna::get_ini('DBtables', 'LOGS').' l
				ORDER BY
					'.$order_by_ok.' '.$order_dir.'
				LIMIT
					'.$start.', '.luna::$data['limit'].'
			');
			while ($row = $res->fetchRow()) {
				$msg = self::decode_message($row->message);
				$message = lunaTools::display_string(isset($msg->message) ? $msg->message : '');
				// 'code' is the severity as a LABEL and is translated; 'priority' is the same
				// severity as a MACHINE TOKEN and must not be. luna.journal.html.xsl puts the
				// token in the row's class attribute, where css/luna.css matches tr.info,
				// tr.notice, tr.warning, tr.critical and tr.error — so translating the value it
				// styles on silently drops the colour for any severity that has a translation.
				// Only 'error' has one today (fr 'erreur', added in 0.9.4-alpha), which made this
				// a latent French-only defect: an error row still listed, just unstyled.
				$var = [
					'type' => 'log',
					'lid' => $row->id,
					'value' => [
						'message' => $message,
						'code' => _(lunaLog::priorityToString($row->priority)),
						'priority' => lunaLog::priorityToString($row->priority),
						'date' => $row->logtime,
						'user-name' => isset($msg->session->user->firstname) ? $msg->session->user->firstname.' '.$msg->session->user->lastname : _(ANONYMOUS)
					],
				];
				if (!luna::model()->merge_index(luna::model()->load_var($var))) { throw new lunaException(_('Error: cannot load log entry.'), PEAR_LOG_CRIT); }
			}
			$res->free();
			luna::model()->merge_index(luna::model()->load_pager($total, $start, luna::$data['limit'], __CLASS__));
		}
		return true;
	}
	// }}}
	// {{{ decode_message()
	/**
	 * Decode a luna_logs.message to an object with at least ->message. New rows are
	 * JSON (no object sink); pre-0.8.14 rows were PHP-serialized -> decode those with
	 * a strict class allowlist (transitional).
	 */
	private static function decode_message($raw) {
		$m = json_decode((string) $raw);
		if (is_object($m)) { return $m; }
		$legacy = @unserialize((string) $raw, ['allowed_classes' => ['lunaException', 'stdClass']]);
		if ($legacy instanceof lunaException) {
			return (object) [
				'message' => $legacy->getMessage(),
				'session' => isset($legacy->session) ? $legacy->session : null,
				'server'  => isset($legacy->server) ? $legacy->server : null,
			];
		}
		if (is_object($legacy)) { return $legacy; }
		return (object) ['message' => ''];
	}

	// }}}
}
// }}}
