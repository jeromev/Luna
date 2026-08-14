<?php

/**
 * Authorization — every question of the form "may this user do this?".
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
 * These six methods were the access-control core of the whole application, and they lived in
 * the middle of lunaTools — between a date formatter and a transliteration table — where the
 * one subsystem a reader most needs to find had no address. Nothing here changed in the move.
 *
 * Every one of them fails CLOSED: an unresolvable level, group or page denies rather than
 * allows. The delegated-admin and admin-lockout suites exist to hold exactly that.
 */

// {{{
class lunaAuthz {
	// {{{ check_privileges()
	/**
	 * @param int|false $level_nid
	 * @return bool
	 */
	public static function check_privileges(int|false $level_nid = false): bool {
		$level_nid = intval($level_nid);
		if (empty($level_nid)) {
			if (!$level_nid = luna::model()->get_nid(luna::model()->get_level_node(luna::$page_node))) { return false; }
		}
		$res = false;
		if (isset(luna::$session->user->levels[$level_nid])) { $res = true; }
		if (luna::get_ini('config', 'disable')) {
			// admins can access the website, even if it is down
			if (!self::user_can_access_level(luna::$session->user, 'level_admin')) { die(luna::get_ini('config', 'disable_txt') ? _(luna::get_ini('config', 'disable_txt')) : _('This website is temporarily down.')); }
		}
		return $res;
	}
	// }}}
	// {{{ user_can_access_level()
	/**
	 * @param object|false $user
	 * @param mixed $level
	 * @return bool
	 */
	public static function user_can_access_level($user = false, $level = false): bool {
		if (!is_object($user)) { return false; }
		if (empty($level)) { return false; }
		if (is_string($level)) { $level = luna::model()->get_nid_from_lid($level); }
		if (isset($user->levels[$level])) { return true; }
		return false;
	}
	// }}}
	// {{{ user_can_access_page()
	/**
	 * True when the current user may act on $page_node — i.e. can access the level
	 * the page is bound to. Fail-closed: a page with no resolvable level is denied.
	 */
	public static function user_can_access_page($page_node = false) {
		if (empty($page_node) || !is_array($page_node)) { return false; }
		$level_node = luna::model()->get_level_node($page_node);
		if (!$level_node) { return false; }
		$level_nid = intval(luna::model()->get_nid($level_node, 'level'));
		return self::user_can_access_level(luna::$session->user, $level_nid);
	}
	// }}}
	// {{{ user_can_access_group()
	/**
	 * True when $user holds EVERY level the group grants, so assigning this group
	 * hands out no level the actor lacks — stops a delegated admin escalating via
	 * group assignment. (An unknown/level-less group grants nothing, so true.)
	 */
	public static function user_can_access_group($user = false, $group_nid = false) {
		if (!is_object($user)) { return false; }
		$group_nid = intval($group_nid);
		if (empty($group_nid)) { return false; }
		$nodes = luna::get_ini('DBtables', 'NODES'); $map = luna::get_ini('DBtables', 'NODES_MAP'); $types = luna::get_ini('DBtables', 'CLASSES');
		$res = lunaDB::query('
			SELECT l.nid AS level_nid
			FROM '.$map.' gl
			JOIN '.$nodes.' l ON l.nid = gl.nid2 AND l.tid = (SELECT id FROM '.$types.' WHERE lid = '.lunaDB::quote('level').')
			WHERE gl.nid1 = '.lunaDB::quote($group_nid).'
		');
		while ($row = $res->fetchRow()) {
			if (!self::user_can_access_level($user, intval($row->level_nid))) { $res->free(); return false; }
		}
		$res->free();
		return true;
	}
	// }}}
	// {{{ active_admin_count()
	/**
	 * Count active user accounts that are members of the admin group (group_admin).
	 * Used by the admin-lockout guardrails to refuse any change that would leave the
	 * site with no one able to administer it. $exclude_user_nid omits one user from
	 * the tally (to ask "would ANY OTHER admin remain after this change?").
	 * @return int
	 */
	public static function active_admin_count($exclude_user_nid = null): int {
		$ga = luna::model()->get_nid_from_lid('group_admin');
		if (empty($ga)) { return 0; }
		$nodes = luna::get_ini('DBtables', 'NODES'); $map = luna::get_ini('DBtables', 'NODES_MAP'); $types = luna::get_ini('DBtables', 'CLASSES');
		$sql = '
			SELECT COUNT(DISTINCT u.nid) AS n
			FROM '.$map.' m
			JOIN '.$nodes.' u ON u.nid = m.nid2 AND u.is_active = 1 AND u.tid = (SELECT id FROM '.$types.' WHERE lid = '.lunaDB::quote('user').')
			WHERE m.nid1 = '.lunaDB::quote(intval($ga));
		if ($exclude_user_nid !== null) { $sql .= ' AND u.nid <> '.lunaDB::quote(intval($exclude_user_nid)); }
		$res = lunaDB::query($sql);
		$row = $res->fetchRow();
		$n = isset($row->n) ? intval($row->n) : 0;
		$res->free();
		return $n;
	}
	// }}}
	// {{{ user_can_act_on_text()
	/**
	 * True when the current user may modify/delete $text_nid — i.e. can access the
	 * level of EVERY page the text is linked to (so a text living on a higher-level
	 * page cannot be edited from below). A text with no pages is allowed.
	 */
	public static function user_can_act_on_text($text_nid) {
		$text_nid = intval($text_nid);
		if (empty($text_nid)) { return false; }
		$nodes = luna::get_ini('DBtables', 'NODES'); $map = luna::get_ini('DBtables', 'NODES_MAP'); $types = luna::get_ini('DBtables', 'CLASSES');
		$levels = implode(',', array_map('intval', (array) luna::$session->user->levels)) ?: '0';
		// Fail closed: deny unless EVERY distinct page the text links to has a level the
		// user holds (a page with no resolvable level counts as inaccessible).
		$res = lunaDB::query('
			SELECT COUNT(DISTINCT p.nid) AS total,
			       COUNT(DISTINCT CASE WHEN l.nid IN ('.$levels.') THEN p.nid END) AS allowed
			FROM '.$map.' tp
			JOIN '.$nodes.' p ON p.nid = tp.nid2 AND p.tid = (SELECT id FROM '.$types.' WHERE lid = '.lunaDB::quote('page').')
			LEFT JOIN '.$map.' pl ON pl.nid1 = p.nid
			LEFT JOIN '.$nodes.' l ON l.nid = pl.nid2 AND l.tid = (SELECT id FROM '.$types.' WHERE lid = '.lunaDB::quote('level').')
			WHERE tp.nid1 = '.lunaDB::quote($text_nid).'
		');
		$row = $res->fetchRow(); $res->free();
		return ($row && intval($row->total) === intval($row->allowed));
	}
	// }}}
}
// }}}
