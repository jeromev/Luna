<?php

/**
 * lunar mod_admin_pages module
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
class mod_admin_pages {
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
		lunaTools::add_vocabulary([
			'Add a page',
			'Parent page',
			'Page to delete',
			'Delete a page',
			'Add',
			'Delete',
			'Identifier',
			'Be aware that this identifier will be used in the url.',
			'Are you sure you want to delete this page?',
			'It must be unique.',
			'Yes',
			'No',
			'Static page',
			'Page access level',
			'Modify the page',
			'Page to modify',
			'Modify',
			'Select one',
			'Modules to use with this page',
			'Modules',
			'List of the pages',
			'static',
			'Lock',
			'Locked',
			'name',
			'Access level',
			'Literal identifier',
			'Literal id',
			'Deactivate',
			'Pages'
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
		$_POST['add_page_lid'] = lunaTools::prepare_lid($_POST['add_page_lid']);
		$_POST['add_parent_nid'] = intval($_POST['add_parent_nid']);
		// check emptyness
		if (!lunaTools::check_emptyness('add_parent_nid', 'Parent page')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_page_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_page_level', 'Page access level')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['add_page_is_inactive'] = isset($_POST['add_page_is_inactive']) ? ($_POST['add_page_is_inactive'] == 1 ? 1 : 0) : 0;
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('page', 'mod'))) { throw new lunaException(_('Error: cannot load data.'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('level'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// check if the identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_page_lid'])) { return false; }
		// make sure the parent node exists
		if (!$item_parent_node = luna::model()->check_if_node_exists($_POST['add_parent_nid'], 'page')) { return false; }
		// make sure the level node exists
		if (!$item_level_node = luna::model()->check_if_node_exists($_POST['add_page_level'], 'level')) { return false; }
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval($_POST['add_page_level']))) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_pages: attempt to use an inaccessible access level', PEAR_LOG_WARNING); return false; }
		// make sure each mod node exists
		if (isset($_POST['add_page_mods']) && is_array($_POST['add_page_mods']) && !empty($_POST['add_page_mods'])) {
			foreach ($_POST['add_page_mods'] as $mod_nid) {
				if (!$item_mod_node = luna::model()->check_if_node_exists($mod_nid, 'mod')) { return false; }
			}
		} else {
			$_POST['add_page_mods'] = [];
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->insert('page', $_POST['add_page_lid'], ($_POST['add_page_is_inactive'] ? 0 : 1), $_POST['add_parent_nid'])) {
			luna::model()->link($node, $_POST['add_page_mods']);
			luna::model()->link($node, [$_POST['add_page_level']]);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The page “%1\$s” has been added."), _($_POST['add_page_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest('pageid');
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
		$_POST['modify_page_lid'] = lunaTools::prepare_lid($_POST['modify_page_lid']);
		$_POST['modify_item_nid'] = intval($_POST['modify_item_nid']);
		$_POST['modify_parent_nid'] = intval($_POST['modify_parent_nid']);
		$_POST['modify_page_level'] = intval($_POST['modify_page_level']);
		$_POST['modify_page_is_inactive'] = isset($_POST['modify_page_is_inactive']) ? ($_POST['modify_page_is_inactive'] == 1 ? 1 : 0) : 0;
		// check emptyness
		if (!lunaTools::check_emptyness('modify_parent_nid', 'Parent page')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_item_nid', 'Page')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_page_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_page_level', 'Page access level')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('page', 'mod'))) { throw new lunaException(_('Error: cannot load data.'), PEAR_LOG_CRIT); }
		if (!luna::model()->merge_index(luna::model()->load_nodes('level'))) { throw new lunaException(_('Error: cannot load levels.'), PEAR_LOG_CRIT); }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($_POST['modify_item_nid'], 'page')) { return false; }
		$page_lid = luna::model()->get_lid($item_node);
		$page_level_node = luna::model()->get_level_node($item_node);
		$page_level_nid = luna::model()->get_nid($page_level_node);
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval($page_level_nid)) || !lunaAuthz::user_can_access_level(luna::$session->user, intval($_POST['modify_page_level']))) { luna::$messages['warning'][] = _('Access denied: that access level is above your own.'); lunaLog::log('admin_pages: attempt to modify a page across an inaccessible level', PEAR_LOG_WARNING); return false; }
		$page_parent_node = luna::model()->get_parent_node($item_node);
		$page_parent_nid = luna::model()->get_nid($page_parent_node);
		// preserve the root
		$root_nid = luna::model()->get_nid_from_lid('root');
		if ($page_lid == 'root') {
			// do not change the root name
			if ($_POST['modify_page_lid'] != 'root') {
				$inerror++;
				$message = _('You cannot change the root page identifier.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			// do not deactivate the root
			if ($_POST['modify_page_is_inactive']) {
				$inerror++;
				$message = _('The root page must be active.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			// do not move the root
			if ($_POST['modify_parent_nid'] != $root_nid) {
				$inerror++;
				$message = _('The root page cannot move.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			// keep the root public
			if (!$level_public_nid = luna::model()->get_nid_from_lid('level_public')) { throw new lunaException(_('Error: cannot load “level_public”'), PEAR_LOG_CRIT); }
			if ($_POST['modify_page_level'] != $level_public_nid) {
				$inerror++;
				$message = _('The root page must be public.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
		}
		// preserve admin pages and login/out pages
		if (luna::lid_is_protected($page_lid)) {
			if ($_POST['modify_page_lid'] != $page_lid) {
				$inerror++;
				$message = _('You cannot change the identifier of this item.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($_POST['modify_page_level'] != $page_level_nid) {
				$inerror++;
				$message = _('You cannot change the level of this item.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($_POST['modify_parent_nid'] != $page_parent_nid) {
				$inerror++;
				$message = _('You cannot move this item.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
		}
		if ($inerror) { return false; }
		// check if the identifier is already used by another item
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['modify_page_lid'], $_POST['modify_item_nid'])) { return false; }
		// make sure the parent node exists
		if (!$item_parent_node = luna::model()->check_if_node_exists($_POST['modify_parent_nid'], 'page')) { return false; }
		// make sure the level node exists
		if (!$item_level_node = luna::model()->check_if_node_exists($_POST['modify_page_level'], 'level')) { return false; }
		// make sure each mod node exists
		if (isset($_POST['modify_page_mods']) && is_array($_POST['modify_page_mods']) && !empty($_POST['modify_page_mods'])) {
			foreach ($_POST['modify_page_mods'] as $mod_nid) {
				if (!$item_mod_node = luna::model()->check_if_node_exists($mod_nid, 'mod')) { return false; }
			}
		} else {
			$_POST['modify_page_mods'] = [];
		}
		// look for hierarchical problems: the parent page cannot be the modified page, except this page is the root page.
		if ($_POST['modify_parent_nid'] != $root_nid) {
			if ($_POST['modify_parent_nid'] == $_POST['modify_item_nid']) {
				$inerror++;
				$message = _('The hierarchy is incorrect.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// look for hierarchical problems: the modified page cannot become a descendant of
			// itself at ANY depth (the old get_children_nids() test only caught direct children).
			if (luna::model()->would_create_cycle($_POST['modify_item_nid'], $_POST['modify_parent_nid'])) {
				$inerror++;
				$message = _("The hierarchy is incorrect.");
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->update($_POST['modify_item_nid'], $_POST['modify_page_lid'], ($_POST['modify_page_is_inactive'] ? 0 : 1), $_POST['modify_parent_nid'])) {
			luna::model()->unlink($node, 'mod');
			luna::model()->unlink($node, 'level');
			if (isset($_POST['modify_page_level']) && !empty($_POST['modify_page_level'])) { luna::model()->link($node, $_POST['modify_page_level']); }
			if (isset($_POST['modify_page_mods']) && !empty($_POST['modify_page_mods'])) { luna::model()->link($node, $_POST['modify_page_mods']); }
			lunaTools::purge_cache();
			luna::model()->purge_index();
			lunaTools::unrequest(['pageid', 'page_nid', 'modify_item_nid']);
			$message = sprintf(_("The page “%1\$s” has been modified."), _($_POST['modify_page_lid']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_page_lid']));
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
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('page', 'mod'))) { throw new lunaException(_('Error: cannot load data.'), PEAR_LOG_CRIT); }
		$_POST['modify_item_nid'] = intval($_POST['modify_item_nid']);
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_nid', 'Page')) { $inerror++; }
		if ($inerror) { return false; }
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($_POST['modify_item_nid'], 'page')) { return false; }
		$page_lid = luna::model()->get_lid($item_node);
		if (!lunaAuthz::user_can_access_level(luna::$session->user, intval(luna::model()->get_nid(luna::model()->get_level_node($item_node))))) { luna::$messages['warning'][] = _('Access denied: this page is above your access level.'); lunaLog::log('admin_pages: attempt to delete an inaccessible page', PEAR_LOG_WARNING); return false; }
		if ($page_lid == 'root') {
			$inerror++;
			$message = _('You cannot delete the root page.');
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
		}
		// admin-lockout guardrail: protected admin pages cannot be deleted (submit_modify
		// already guards modify; this closes the delete path, backstopped by lunaModel::delete()).
		if (luna::lid_is_protected($page_lid)) {
			$message = sprintf(_('You cannot delete the protected page “%1$s”.'), _($page_lid));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
			return false;
		}
		if ($inerror) { return false; }
		$page_nid = luna::model()->get_nid($item_node);
		// A page that still has sub-pages cannot be deleted: they would be left filed under a
		// parent that no longer exists — listed here, unreachable on the site. Re-parent or delete
		// them first. Backstopped by lunaModel::delete(). The message names no child on purpose:
		// the check runs at full scope, so a child may be one this administrator is not entitled
		// to see, and a refusal must not become the channel that discloses it.
		if (luna::model()->first_child_nid($page_nid)) {
			$message = sprintf(
				_('You cannot delete “%1$s” while other pages are filed under it. Move or delete them first.'),
				_($page_lid)
			);
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
			return false;
		}
		if (luna::model()->delete($page_nid)) {
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The page “%1\$s” has been deleted."), _($page_lid));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['pageid', 'page_nid', 'modify_item_nid']);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($page_lid));
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
		luna::model()->merge_index(luna::model()->load_nodes('page', 'mod'));
		luna::model()->merge_index(luna::model()->load_nodes('mod'));
		luna::model()->merge_index(luna::model()->load_nodes('level'));
		$nid = luna::model()->check_requested_node('page_nid', 'page');
		return true;
	}
	// }}}
}
// }}}
