<?php

/**
 * lunar mod_admin_levels module
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
class mod_admin_levels {
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
			'Pages on this level',
			'Choose an action',
			'Delete from the level',
			'Groups admitted to this level',
			'Add a level',
			'Level to delete',
			'Delete a level',
			'Deactivate',
			'Add',
			'Delete',
			'Identifier',
			'Be aware that this identifier will be used in the url.',
			'Are you sure you want to delete this level?',
			'It must be unique.',
			'Yes',
			'No',
			'Modify the level',
			'Level to modify',
			'Modify',
			'Select one',
			'List of the levels',
			'name',
			'groups',
			'Groups which belong to this level',
			'Levels',
			'For the selection',
			'Select one',
			'Literal identifier',
			'Literal id',
			'Last modification'
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
		$_POST['add_level_lid'] = lunaTools::prepare_lid($_POST['add_level_lid']);
		// check emptyness
		if (!lunaTools::check_emptyness('add_level_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_level_groups', 'Groups admitted to this level')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['add_level_is_inactive'] = isset($_POST['add_level_is_inactive']) ? ($_POST['add_level_is_inactive'] == 1 ? 1 : 0) : 0;
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('level', 'group'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('group'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_level_lid'])) { return false; }
		// the groups multi-select posts lids; resolve against the loaded index, then re-assert
		// emptiness so a list that resolved to nothing is refused rather than saved as "no groups"
		$_POST['add_level_groups'] = luna::model()->nids_from_lids($_POST['add_level_groups'] ?? [], 'group');
		if (!lunaTools::check_emptyness('add_level_groups', 'Groups admitted to this level')) { return false; }
		// any level must be accessible to administrators
		if (!$group_admin_nid = luna::model()->get_nid_from_lid('group_admin')) { throw new lunaException(_('Error: cannot load “group_admin”'), PEAR_LOG_CRIT); }
		$_POST['add_level_groups'][$group_admin_nid] = $group_admin_nid;
		foreach ($_POST['add_level_groups'] as $postgroup_nid) {
			if (!$postgroup_node = luna::model()->get_node($postgroup_nid, 'group')) {
				$inerror++;
				$message = _('Unknown group '.intval($postgroup_nid));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			} elseif (!lunaAuthz::user_can_access_group(luna::$session->user, intval($postgroup_nid))) {
				$inerror++;
				luna::$messages['warning'][] = _('Access denied: you cannot admit a group that grants levels above your own.');
				lunaLog::log('admin_levels: attempt to admit an inaccessible group '.intval($postgroup_nid), PEAR_LOG_WARNING);
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->insert('level', $_POST['add_level_lid'], ($_POST['add_level_is_inactive'] ? 0 : 1))) {
			luna::model()->link($node, $_POST['add_level_groups']);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("Level “%1\$s” has been added."), $_POST['add_level_lid']);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['level_id', 'level_lid', 'add_item_lid']);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['add_level_lid']));
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
		// clean things
		$_POST['modify_level_lid'] = lunaTools::prepare_lid($_POST['modify_level_lid']);
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_lid', 'Level to modify')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_level_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_level_groups', 'Groups admitted to this level')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('level', 'group'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('group'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		$_POST['modify_level_is_inactive'] = isset($_POST['modify_level_is_inactive']) ? ($_POST['modify_level_is_inactive'] == 1 ? 1 : 0) : 0;
		// the form addresses the level by lid; resolve it to the nid the guards below work in
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'level'));
		// the groups multi-select posts lids too
		$_POST['modify_level_groups'] = luna::model()->nids_from_lids($_POST['modify_level_groups'] ?? [], 'group');
		if (!lunaTools::check_emptyness('modify_level_groups', 'Groups admitted to this level')) { return false; }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($modify_item_nid, 'level')) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, $modify_item_nid)) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_levels: attempt to use an inaccessible access level', PEAR_LOG_WARNING); return false; }
		// preserve sensitive data
		if (!$item_lid = luna::model()->check_if_lid_is_protected($item_node, ['level_admin', 'level_public'])) { return false; }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['modify_level_lid'], $modify_item_nid)) { return false; }
		// any level must be accessible to administrators
		if (!$group_admin_nid = luna::model()->get_nid_from_lid('group_admin')) { throw new lunaException(_('Error: cannot load “group_admin”'), PEAR_LOG_CRIT); }
		$_POST['modify_level_groups'][$group_admin_nid] = $group_admin_nid;
		foreach ($_POST['modify_level_groups'] as $postgroup_nid) {
			if (!$postgroup_node = luna::model()->get_node($postgroup_nid, 'group')) {
				$inerror++;
				$message = _('Unknown group '.intval($postgroup_nid));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->update($modify_item_nid, $_POST['modify_level_lid'], ($_POST['modify_level_is_inactive'] ? 0 : 1))) {
			luna::model()->unlink($node, 'group');
			luna::model()->link($node, $_POST['modify_level_groups']);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['level_lid', 'modify_item_lid']);
			$message = sprintf(_("The level “%1\$s” has been modified."), _($_POST['modify_level_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_level_lid']));
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
		if (!luna::model()->merge_index(luna::model()->load_nodes('level', 'group'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_lid', 'Level to modify')) { $inerror++; }
		if ($inerror) { return false; }
		// check if node exists
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'level'));
		if (!$item_node = luna::model()->check_if_node_exists($modify_item_nid, 'level')) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, $modify_item_nid)) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_levels: attempt to use an inaccessible access level', PEAR_LOG_WARNING); return false; }
		// preserve sensitive data
		if (!$item_lid = luna::model()->check_if_lid_is_protected($item_node, ['level_admin', 'level_public'])) { return false; }
		$item_nid = luna::model()->get_nid($item_node);
		if (luna::model()->delete($item_nid)) {
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['level_lid', 'modify_item_lid']);
			$message = sprintf(_("The level “%1\$s” has been deleted."), _($item_lid));
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
		luna::model()->merge_index(luna::model()->load_nodes('level', 'group'));
		$nid = luna::model()->check_requested_node_by_lid('level_lid', 'level');
		return true;
	}
	// }}}
}
// }}}
