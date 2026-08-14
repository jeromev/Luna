<?php

/**
 * lunar mod_admin_mods module
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
class mod_admin_mods {
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
			'Add a module',
			'Parent module',
			'Module identifier',
			'Module to delete',
			'Delete a module',
			'Add',
			'Delete',
			'Identifier',
			'Be aware that this identifier will be used in the url.',
			'Are you sure you want to delete this module?',
			'It must be unique.',
			'Yes',
			'No',
			'Lock',
			'Default',
			'Access level',
			'Modify the module',
			'Module to modify',
			'Modify',
			'Select one',
			'Deactivate',
			'List of the modules',
			'name',
			'active',
			'default',
			'locked',
			'path',
			'Pages using the module',
			'Literal identifier',
			'Literal id',
			'Modules'
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
		$_POST['add_mod_lid'] = lunaTools::prepare_lid($_POST['add_mod_lid']);
		$_POST['add_mod_is_inactive'] = isset($_POST['add_mod_is_inactive']) ? ($_POST['add_mod_is_inactive'] == 1 ? 1 : 0) : 0;
		// check emptyness
		if (!lunaTools::check_emptyness('add_mod_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_mod_level', 'Access level')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'page'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		// check if the identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_mod_lid'])) { return false; }
		// make sure the level node exists
		if (!$item_level_node = luna::model()->check_if_node_exists($_POST['add_mod_level'], 'level')) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval($_POST['add_mod_level']))) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_mods: attempt to use an inaccessible access level', PEAR_LOG_WARNING); return false; }
		// make sure each page node exists
		if (isset($_POST['add_mod_pages']) && is_array($_POST['add_mod_pages']) && !empty($_POST['add_mod_pages'])) {
			foreach ($_POST['add_mod_pages'] as $page_nid) {
				if (!$item_page_node = luna::model()->check_if_node_exists($page_nid, 'page')) { return false; }
			}
		} else {
			$_POST['add_mod_pages'] = [];
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->insert('mod', $_POST['add_mod_lid'], ($_POST['add_mod_is_inactive'] ? 0 : 1))) {
			luna::model()->link($node, $_POST['add_mod_pages']);
			luna::model()->link($node, [$_POST['add_mod_level']]);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The module “%1\$s” has been created."), $_POST['add_mod_lid']);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['add_mod_lid']));
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
		$_POST['modify_mod_lid'] = lunaTools::prepare_lid($_POST['modify_mod_lid']);
		$_POST['modify_item_nid'] = intval($_POST['modify_item_nid']);
		$_POST['modifymodisinactive'] = isset($_POST['modifymodisinactive']) ? ($_POST['modifymodisinactive'] == 1 ? 1 : 0) : 0;
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_nid', 'Module to modify')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_mod_lid', 'Module lid')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_mod_level', 'Access level')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'page'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($_POST['modify_item_nid'], 'mod')) { return false; }
		if ($inerror) { return false; }
		// admin-lockout guardrail: the modules that power the admin pages must not be
		// renamed, relevelled, deactivated or unbound from their pages.
		if (!$protected_lid = luna::model()->check_if_lid_is_protected($item_node, ['mod_admin', 'mod_admin_groups', 'mod_admin_levels', 'mod_admin_mods', 'mod_admin_pages', 'mod_admin_users', 'mod_journal'])) { return false; }
		// check if the identifier is already used by another item
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['modify_mod_lid'], $_POST['modify_item_nid'])) { return false; }
		// make sure the level node exists
		if (!$item_level_node = luna::model()->check_if_node_exists($_POST['modify_mod_level'], 'level')) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval($_POST['modify_mod_level']))) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_mods: attempt to use an inaccessible access level', PEAR_LOG_WARNING); return false; }
		// make sure each page node exists
		if (isset($_POST['modify_mod_pages']) && is_array($_POST['modify_mod_pages']) && !empty($_POST['modify_mod_pages'])) {
			foreach ($_POST['modify_mod_pages'] as $page_nid) {
				if (!$item_page_node = luna::model()->check_if_node_exists($page_nid, 'page')) { return false; }
			}
		} else {
			$_POST['modify_mod_pages'] = [];
		}
		$item_lid = luna::model()->get_lid($item_node);
		if ($node = luna::model()->update($_POST['modify_item_nid'], $_POST['modify_mod_lid'], ($_POST['modifymodisinactive'] ? 0 : 1))) {
			luna::model()->unlink($node, 'page');
			luna::model()->unlink($node, 'level');
			if (isset($_POST['modify_mod_pages']) && !empty($_POST['modify_mod_pages'])) { luna::model()->link($node, $_POST['modify_mod_pages']); }
			if (isset($_POST['modify_mod_level']) && !empty($_POST['modify_mod_level'])) { luna::model()->link($node, $_POST['modify_mod_level']); }
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['mod_nid', 'mod_nid', 'modify_item_nid']);
			$message = sprintf(_("The module “%1\$s” has been modified."), _($_POST['modify_mod_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_mod_lid']));
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
		// initialise the errors counter
		$inerror = 0;
		// clean things
		$_POST['modify_item_nid'] = intval($_POST['modify_item_nid']);
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_nid', 'Module to delete')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'page'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($_POST['modify_item_nid'], 'mod')) { return false; }
		// admin-lockout guardrail: the modules that power the admin pages cannot be deleted.
		if (!$protected_lid = luna::model()->check_if_lid_is_protected($item_node, ['mod_admin', 'mod_admin_groups', 'mod_admin_levels', 'mod_admin_mods', 'mod_admin_pages', 'mod_admin_users', 'mod_journal'])) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval(luna::model()->get_nid(luna::model()->get_level_node($item_node))))) {
			luna::$messages['warning'][] = _('Access denied: this module is above your access level.');
			lunaLog::log('admin_mods: attempt to delete an inaccessible module', PEAR_LOG_WARNING);
			return false;
		}
		if ($inerror) { return false; }
		if (luna::model()->delete($_POST['modify_item_nid'])) {
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['mod_nid', 'mod_nid', 'modify_item_nid']);
			$message = sprintf(_("The module “%1\$s” has been deleted."), _($_POST['modify_mod_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_mod_lid']));
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
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('mod', 'page'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		$nid = luna::model()->check_requested_node('mod_nid', 'mod');
		return true;
	}
	// }}}
}
// }}}
