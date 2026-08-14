<?php

/**
 * lunar mod_online_users module
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
// {{{
class mod_online_users {
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
	 * Class constructor.
	 * @return void
	 */
	private function __construct() {
		lunaTools::add_vocabulary([
			'firstname',
			'lastname',
			'email',
			'session_url',
			'IP',
			'session_length',
			'session_lang'
		]);
	}
	// }}}
	// {{{ load()
	/**
	 * load()
	 * @return bool
	 */
	public function load(): bool {
		$nodes = [];
		$res = lunaDB::query('
			SELECT DISTINCT
				u.nid,
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
				AND ('.lunaDB::quote(NOW).' - s.session_time) < '.lunaDB::quote(lunaSession::$min_time_out).'
				AND u.tid = tu.id AND tu.lid = '.lunaDB::quote('user').'
				AND g.tid = tg.id AND tg.lid = '.lunaDB::quote('group').'
				AND l.tid = tl.id AND tl.lid = '.lunaDB::quote('level').'
				AND ug.nid1 = u.nid AND ug.nid2 = g.nid
				AND gl.nid1 = g.nid AND gl.nid2 = l.nid
				AND g.is_active = 1
				AND l.is_active = 1
			GROUP BY
				s.session_id
			ORDER BY
				s.session_time DESC
		');
		$users = [];
		while ($row = $res->fetchRow()) {
			$users[$row->nid]['nid'] = $row->nid;
			$users[$row->nid]['is_active'] = $row->is_active;
			$users[$row->nid]['firstname'] = $row->firstname;
			$users[$row->nid]['lastname'] = $row->lastname;
			$users[$row->nid]['email'] = $row->email;
			$users[$row->nid]['regis_time'] = $row->regis_time;
			$users[$row->nid]['last_time'] = $row->last_time;
			$users[$row->nid]['last_url'] = $row->last_url;
			$users[$row->nid]['lang'] = $row->lang;
			$users[$row->nid]['groups'][$row->group_nid] = $row->group_nid;
			$users[$row->nid]['levels'][$row->level_nid] = $row->level_nid;
			$users[$row->nid]['is_current'] = ($row->nid == luna::$session->user->nid) ? 1 : 0;
			$users[$row->nid]['session_id'] = $row->session_id;
			$users[$row->nid]['session_start'] = $row->session_start;
			$users[$row->nid]['session_time'] = $row->session_time;
			$users[$row->nid]['session_url'] = $row->session_url;
			$users[$row->nid]['session_lang'] = $row->session_lang;
			$users[$row->nid]['session_logged_in'] = $row->session_logged_in;
			$users[$row->nid]['session_ip'] = lunaTools::decode_ip($row->session_ip);
		}
		$res->free();
		luna::model()->merge_index(luna::model()->load_user($users));
		// The query above has no LIMIT: every online user is listed, so there is nothing to page
		// through. load_pager() returns false for a zero total and merge_index() drops it, so no
		// pager node is emitted — and the widget's XSLT never calls the pager template either.
		luna::model()->merge_index(luna::model()->load_pager(0, 0, (luna::$data['limit'] ?? PERPAGE), __CLASS__));
		return true;
	}
	// }}}
}
// }}}
