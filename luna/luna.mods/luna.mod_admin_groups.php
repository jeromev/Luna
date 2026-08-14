<?php

/**
 * lunar mod_admin_groups module
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
class mod_admin_groups {
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
	 * @return void
	 */
	private function __construct() {
		lunaTools::add_vocabulary([
			'Choose an action',
			'Delete from the group',
			'Add a group',
			'Group to delete',
			'Delete a group',
			'Add',
			'Delete',
			'Identifier',
			'Be aware that this identifier will be used in the url.',
			'Are you sure you want to delete this group?',
			'It must be unique.',
			'Yes',
			'No',
			'Modify the group',
			'Group to modify',
			'Modify',
			'Select one',
			'List of the groups',
			'locked',
			'name',
			'Accessible levels',
			'firstname',
			'lastname',
			'email',
			'active',
			'id',
			'last_time',
			'regis_time',
			'session_url',
			'session_ip',
			'session_length',
			'session_lang',
			'Users who belong to this group',
			'Groups',
			'For the selection',
			'Select one',
			'Literal identifier',
			'Literal id',
			'Deactivate'
		]);
	}
	// }}}
	// {{{ submit_add()
	/**
	 * @return bool
	 */
	public function submit_add(): bool {
		// initialise the errors counter
		$inerror = 0;
		// clean things
		$_POST['add_group_lid'] = lunaTools::prepare_lid($_POST['add_group_lid']);
		// check emptyness
		if (!lunaTools::check_emptyness('add_group_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_group_levels', 'Accessible levels')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['add_group_is_inactive'] = isset($_POST['add_group_is_inactive']) ? ($_POST['add_group_is_inactive'] == 1 ? 1 : 0) : 0;
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load data.'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('level'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// the levels multi-select posts lids; resolve them here, against the index just loaded,
		// and re-assert emptiness — a list whose every entry failed to resolve must be refused,
		// not saved as "no levels".
		$_POST['add_group_levels'] = luna::model()->nids_from_lids($_POST['add_group_levels'], 'level');
		if (!lunaTools::check_emptyness('add_group_levels', 'Accessible levels')) { return false; }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_group_lid'])) { return false; }
		// any group must be able to access the public level
		if (!$level_public_nid = luna::model()->get_nid_from_lid('level_public')) { throw new lunaException(_('Error: cannot load “level_public”'), PEAR_LOG_CRIT); }
		$_POST['add_group_levels'][$level_public_nid] = $level_public_nid;
		foreach ($_POST['add_group_levels'] as $postlevel_nid) {
			if (!$postlevel_node = luna::model()->get_node($postlevel_nid, 'level')) {
				$inerror++;
				$message = _('Unknown access level '.intval($postlevel_nid));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			} elseif (!lunaAuthz::user_can_access_level(luna::$session->user, intval($postlevel_nid))) {
				$inerror++;
				luna::$messages['warning'][] = _('Access denied: you cannot grant a level you do not hold.');
				lunaLog::log('admin_groups: attempt to grant an inaccessible level '.intval($postlevel_nid), PEAR_LOG_WARNING);
			}
			if ($inerror) { return false; }
		}
		if ($node = luna::model()->insert('group', $_POST['add_group_lid'], ($_POST['add_group_is_inactive'] ? 0 : 1))) {
			luna::model()->link($node, $_POST['add_group_levels']);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("Group “%1\$s” has been added."), $_POST['add_group_lid']);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['group_id', 'group_lid', 'add_item_lid']);
		} else {
			$message = sprintf(_("The insertion of the item “%1\$s” has failed."), _($_POST['add_group_lid']));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
		}
		return true;
	}
	// }}}
	// {{{ submit_modify()
	/**
	 * @return bool
	 */
	public function submit_modify(): bool {
		// initialise the errors counter
		$inerror = 0;
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load groups.'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('level'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// clean things
		$_POST['modify_group_lid'] = lunaTools::prepare_lid($_POST['modify_group_lid']);
		if (lunaTools::request('batch_submit')) {
			if (!lunaTools::check_emptyness('modify_users_list', 'List of the users who belong to this group')) { $inerror++; }
			if (!lunaTools::check_emptyness('modify_users_action', 'Choose an action')) { $inerror++; }
			if ($inerror) { return false; }
			$group_lid = lunaTools::request('group_lid');
			$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('group_lid', 'group'));
			if ($modify_item_nid) {
				if (!$item_node = luna::model()->get_node($modify_item_nid, 'group')) {
					$inerror++;
					$message = sprintf(_("Unknown group “%1\$s”."), $group_lid);
					luna::$messages['warning'][] = $message;
					lunaLog::log($message, PEAR_LOG_WARNING);
				}
				if ($inerror) { return false; }
				// preserve sensitive data
				if (!$item_lid = luna::model()->check_if_lid_is_protected($item_node, ['group_default', 'group_admin'])) { return false; }
				// the checkbox column posts user lids, and users are not in the index yet on this
				// path — load the group's members before resolving any of them.
				luna::model()->merge_index(luna::model()->load_users(false, $modify_item_nid));
				switch ($_POST['modify_users_action']) {
					case 'delete_from_group':
						$usrstr = '';
						foreach ($_POST['modify_users_list'] as $user_lid) {
							if (!$user_node = luna::model()->get_node_from_slug((string) $user_lid, 'Person', 'foaf')) { continue; }
							$user_nid = intval(luna::model()->get_nid($user_node, 'Person', 'foaf'));
							if ($user_nid < 1) { continue; }
							$usrstr .= ' “'.$user_lid.'”,';
							$res = lunaDB::query('
								DELETE FROM
									'.luna::get_ini('DBtables', 'NODES_MAP').'
								WHERE
									(nid1 = '.lunaDB::quote($modify_item_nid).' AND nid2 = '.lunaDB::quote($user_nid).')
									OR
									(nid2 = '.lunaDB::quote($modify_item_nid).' AND nid1 = '.lunaDB::quote($user_nid).')
							');
							luna::model()->insert_action($user_nid);
						}
						$usrstr = substr($usrstr, 0, -1);
						lunaDB::optimise([luna::get_ini('DBtables', 'NODES_MAP')]);
						lunaTools::purge_cache();
						luna::model()->purge_index();
						$message = sprintf(_("The following users have been deleted from the group “%1\$s”: "), _($_POST['modify_group_lid'])).$usrstr.'.';
						luna::$messages['okay'][] = $message;
						lunaLog::log($message, PEAR_LOG_INFO);
						$_POST['group_lid'] = $_POST['modify_item_lid'] ?? $group_lid;
						break;
				}
			}
			if ($inerror) { return false; }
			return true;
		}
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_lid', 'Group to modify')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_group_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_group_levels', 'Accessible levels')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['modify_group_is_inactive'] = isset($_POST['modify_group_is_inactive']) ? ($_POST['modify_group_is_inactive'] == 1 ? 1 : 0) : 0;
		// the form addresses the group by lid; resolve it to the nid the write path works in
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'group'));
		// the levels multi-select posts lids too — resolve before the access checks below,
		// which must run over nids the requester can actually address
		$_POST['modify_group_levels'] = luna::model()->nids_from_lids($_POST['modify_group_levels'], 'level');
		if (!lunaTools::check_emptyness('modify_group_levels', 'Accessible levels')) { return false; }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($modify_item_nid, 'group')) { return false; }
		// preserve sensitive data
		if (!$item_lid = luna::model()->check_if_lid_is_protected($item_node, ['group_default', 'group_admin'])) { return false; }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['modify_group_lid'], $modify_item_nid)) { return false; }
		if (!$level_public_nid = luna::model()->get_nid_from_lid('level_public')) { throw new lunaException(_('Error: cannot load “level_public”'), PEAR_LOG_CRIT); }
		$_POST['modify_group_levels'][$level_public_nid] = $level_public_nid;
		foreach ($_POST['modify_group_levels'] as $postlevel_nid) {
			if (!$postlevel_node = luna::model()->get_node($postlevel_nid, 'level')) {
				$inerror++;
				$message = _('Unknown access level '.intval($postlevel_nid));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			} elseif (!lunaAuthz::user_can_access_level(luna::$session->user, intval($postlevel_nid))) {
				$inerror++;
				luna::$messages['warning'][] = _('Access denied: you cannot grant a level you do not hold.');
				lunaLog::log('admin_groups: attempt to grant an inaccessible level '.intval($postlevel_nid), PEAR_LOG_WARNING);
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->update($modify_item_nid, $_POST['modify_group_lid'], ($_POST['modify_group_is_inactive'] ? 0 : 1))) {
			luna::model()->unlink($node, 'level');
			luna::model()->link($node, $_POST['modify_group_levels']);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['group_id', 'group_lid', 'modify_item_lid']);
			$message = sprintf(_("The group “%1\$s” has been modified."), _($_POST['modify_group_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_group_lid']));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
		}
		return true;
	}
	// }}}
	// {{{ submit_delete()
	/**
	 * @return bool
	 */
	public function submit_delete(): bool {
		$inerror = 0;
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_lid', 'Group to modify')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load groups.'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('level'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// check for already existing entries
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'group'));
		if (!$item_node = luna::model()->get_node($modify_item_nid, 'group')) {
			$inerror++;
			$message = sprintf(_("Unknown group “%1\$s”."), lunaTools::request('modify_item_lid'));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
		}
		if ($inerror) { return false; }
		// preserve sensitive data
		if (!$item_lid = luna::model()->check_if_lid_is_protected($item_node, ['group_default', 'group_admin'])) { return false; }
		$item_nid = luna::model()->get_nid($item_node);
		if (luna::model()->delete($item_nid)) {
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['group_lid', 'modify_item_lid']);
			$message = sprintf(_("The group “%1\$s” has been deleted."), _($item_lid));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($item_lid));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
		}
		return true;
	}
	// }}}
	// {{{ load()
	/**
	 * @return bool
	 */
	public function load(): bool {
		$inerror = 0;
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load groups.'), PEAR_LOG_CRIT); }
		if ($nid = luna::model()->check_requested_node_by_lid('group_lid', 'group')) {
			luna::model()->merge_index(luna::model()->load_users(false, $nid));
		}
		return true;
	}
	// }}}
}
// }}}
