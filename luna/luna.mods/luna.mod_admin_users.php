<?php

/**
 * lunar mod_admin_users module
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
class mod_admin_users {
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
			'firstname',
			'lastname',
			'email',
			'password',
			'active',
			'id',
			'last_time',
			'regis_time',
			'session_url',
			'session_ip',
			'session_length',
			'session_lang',
			'Yes',
			'No',
			'Add a user',
			'Modify the user',
			'Deactivate',
			'Add',
			'Modify',
			'Delete',
			'List of the users',
			'Are you sure you want to delete this user?',
			'groups',
			'Literal identifier',
			'Literal id',
			'Deactivate',
			'Users'
		]);
	}
	// }}}
	// {{{ submit_add()
	/**
	 * @return bool
	 */
	public function submit_add(): bool {
		$inerror = 0;
		// check emptyness
		if (!lunaTools::check_emptyness('add_user_firstname', 'firstname')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_user_email', 'email')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_user_password', 'password')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_user_groups', 'groups')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['add_user_password'] = lunaTools::hash_password($_POST['add_user_password']);
		$_POST['add_user_is_inactive'] = isset($_POST['add_user_is_inactive']) ? ($_POST['add_user_is_inactive'] == 1 ? 1 : 0) : 0;
		// load stuff
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		// check email
		if (!lunaTools::check_email($_POST['add_user_email'])) {
			$inerror++;
			$message = sprintf(_("The email address “%1\$s” is invalid."), $_POST['add_user_email']);
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
		}
		if ($inerror) { return false; }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_user_email'])) { return false; }
		// the groups multi-select posts lids; resolve against the index loaded above, then
		// re-assert emptiness so a list that resolved to nothing is refused rather than saved
		$_POST['add_user_groups'] = luna::model()->nids_from_lids($_POST['add_user_groups'] ?? [], 'group');
		if (!lunaTools::check_emptyness('add_user_groups', 'groups')) { return false; }
		if (isset($_POST['add_user_groups']) && !empty($_POST['add_user_groups'])) {
			foreach ($_POST['add_user_groups'] as $postgroup_nid) {
				if (!$postgroup_node = luna::model()->get_node($postgroup_nid, 'group')) {
					$inerror++;
					$message = _('Unknown group '.intval($postgroup_nid));
					luna::$messages['warning'][] = $message;
					lunaLog::log($message, PEAR_LOG_WARNING);
				} elseif (!lunaAuthz::user_can_access_group(luna::$session->user, intval($postgroup_nid))) {
					$inerror++;
					luna::$messages['warning'][] = _('Access denied: you cannot assign a group that grants levels above your own.');
					lunaLog::log('admin_users: attempt to assign an inaccessible group '.intval($postgroup_nid), PEAR_LOG_WARNING);
				}
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->insert('user', $_POST['add_user_email'], ($_POST['add_user_is_inactive'] ? 0 : 1))) {
			luna::model()->link($node, $_POST['add_user_groups']);
			$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'USERS').'
					(nid, firstname, lastname, password, regis_time, last_time)
				VALUES
					(
						'.lunaDB::quote($node).',
						'.lunaDB::quote($_POST['add_user_firstname']).',
						'.lunaDB::quote($_POST['add_user_lastname']).',
						'.lunaDB::quote($_POST['add_user_password']).',
						'.lunaDB::quote(NOW).',
						'.lunaDB::quote(NOW).'
					)
			');
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("User “%1\$s” has been created."), ($_POST['add_user_firstname'].' '.$_POST['add_user_lastname']));
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['add_user_email', 'add_user_groups', 'add_user_firstname', 'add_user_lastname', 'add_item_lid', 'modify_item_lid', 'user_lid']);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), $_POST['add_mod_lid']);
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
		$inerror = 0;
		// check emptyness
		if (!lunaTools::check_emptyness('modify_user_firstname', 'firstname')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_user_email', 'email')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_user_groups', 'groups')) { $inerror++; }
		$user_lid = (string) lunaTools::request('user_lid');
		if ($user_lid !== '') {
			if ($inerror) { return false; }
			$_POST['modify_user_is_inactive'] = isset($_POST['modify_user_is_inactive']) ? ($_POST['modify_user_is_inactive'] == 1 ? 1 : 0) : 0;
			if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
			// check if the user exists. The lid gives the candidate row to load; the node is then
			// validated through the ACL-scoped index by check_requested_node_by_lid(), which is
			// the same two-step the nid form used — load_users($nid), then resolve against the
			// index — so the addressing changes and the authorisation posture does not.
			luna::model()->merge_index(luna::model()->load_users(intval(luna::model()->get_nid_from_lid($user_lid))));
			$user_nid = intval(luna::model()->check_requested_node_by_lid('user_lid', 'Person', 'foaf'));
			if (empty($user_nid)) {
				$inerror++;
				$message = sprintf(_("User “%1\$s” does not exist."), $user_lid);
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			}
			if ($inerror) { return false; }
			$user_node = luna::model()->get_node($user_nid, 'Person', 'foaf');
			$user_lid = luna::model()->get_lid($user_node);
			// Are we trying to modify an innocent guest?
			if ($_POST['modify_user_email'] == ANONYMOUS) {
				$inerror++;
				$message = _('You cannot modify the guest user.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// check email
			if (!lunaTools::check_email($_POST['modify_user_email'])) {
				$inerror++;
				$message = sprintf(_("The email address “%1\$s” is invalid."), $_POST['modify_user_email']);
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// check if the user is trying to deactivate himself (do not let him do it!)
			if ($user_nid == luna::$session->user->nid && $_POST['modify_user_is_inactive']) {
				$inerror++;
				$message = _('You cannot deactivate yourself.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// check if this email already exists in the database
			$res = lunaDB::query('
				SELECT
					u.nid,
					u.firstname,
					u.lastname,
					n.lid
				FROM
					'.luna::get_ini('DBtables', 'USERS').' u,
					'.luna::get_ini('DBtables', 'NODES').' n
				WHERE
					n.lid = '.lunaDB::quote($_POST['modify_user_email']).'
					AND u.nid = n.nid
					AND n.nid <> '.lunaDB::quote($user_nid).'
				LIMIT 1
			');
			$row = $res->fetchRow();
			if (isset($row->nid) && $row->nid > 0) {
				$inerror++;
				$message = sprintf(_("A user with the same email (%1\$s) already exists."), $row->firstname.' '.$row->lastname.' #'.$row->nid);
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// the groups multi-select posts lids — resolve before the per-target authz below,
			// which must run over group nids this requester can actually address
			$_POST['modify_user_groups'] = luna::model()->nids_from_lids($_POST['modify_user_groups'] ?? [], 'group');
			if (!lunaTools::check_emptyness('modify_user_groups', 'groups')) { return false; }
			if (!$group_default_nid = luna::model()->get_nid_from_lid('group_default')) { throw new lunaException(_('Error: cannot load “group_default”'), PEAR_LOG_CRIT); }
			$_POST['modify_user_groups'][$group_default_nid] = $group_default_nid;
			// Per-target authz: refuse to assign a group that grants any level the actor lacks.
			foreach ($_POST['modify_user_groups'] as $postgroup_nid) {
				if (!lunaAuthz::user_can_access_group(luna::$session->user, intval($postgroup_nid))) {
					$inerror++;
					luna::$messages['warning'][] = _('Access denied: you cannot assign a group that grants levels above your own.');
					lunaLog::log('admin_users: attempt to assign an inaccessible group '.intval($postgroup_nid), PEAR_LOG_WARNING);
				}
			}
			if ($inerror) { return false; }
			// --- admin-lockout guardrails: never let this edit strip the actor's own
			// admin access, nor the site's last active administrator (mirrors the
			// self-deactivation guard above). ---
			$group_admin_nid = luna::model()->get_nid_from_lid('group_admin');
			if (!empty($group_admin_nid)) {
				$submitted_groups = array_map('intval', (array) $_POST['modify_user_groups']);
				$keeps_admin   = in_array(intval($group_admin_nid), $submitted_groups, true) && !$_POST['modify_user_is_inactive'];
				$total_admins  = lunaAuthz::active_admin_count();
				$other_admins  = lunaAuthz::active_admin_count($user_nid);
				$target_is_admin = ($total_admins > $other_admins); // target is an active member of group_admin
				if ($target_is_admin && !$keeps_admin) {
					if ($user_nid == luna::$session->user->nid) {
						$message = _('You cannot remove your own administrator access.');
						luna::$messages['warning'][] = $message; lunaLog::log($message, PEAR_LOG_WARNING);
						return false;
					} elseif ($other_admins < 1) {
						$message = _('You cannot remove the last administrator: the site would be left with no one able to administer it.');
						luna::$messages['warning'][] = $message; lunaLog::log($message, PEAR_LOG_WARNING);
						return false;
					}
				}
			}
			if ($node = luna::model()->update($user_nid, $_POST['modify_user_email'], ($_POST['modify_user_is_inactive'] ? 0 : 1))) {
				luna::model()->unlink($node, 'group');
				luna::model()->link($node, $_POST['modify_user_groups']);
				$res = lunaDB::query('
					UPDATE
						'.luna::get_ini('DBtables', 'USERS').'
					SET
						firstname = '.lunaDB::quote($_POST['modify_user_firstname']).',
						lastname = '.lunaDB::quote($_POST['modify_user_lastname']).'
						'.((isset($_POST['modify_user_password']) && !empty($_POST['modify_user_password'])) ? ', password = '.lunaDB::quote(lunaTools::hash_password($_POST['modify_user_password'])) : ' ').'
					WHERE
						nid = '.lunaDB::quote($node).'
				');
				lunaTools::purge_cache();
				luna::model()->purge_index();
				$message = sprintf(_("User “%1\$s” has been modified."), ($_POST['modify_user_firstname'].' '.$_POST['modify_user_lastname']));
				luna::$messages['okay'][] = $message;
				lunaLog::log($message, PEAR_LOG_INFO);
				lunaTools::unrequest(['modify_user_email', 'modify_user_groups', 'modify_user_firstname', 'modify_user_lastname', 'modify_item_lid', 'user_lid']);
			} else {
				$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_user_email']));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			}
			return true;
		}
		return false;
	}
	// }}}
	// {{{ submit_delete()
	/**
	 * submit_delete()
	 *
	 * @return bool
	 */
	public function submit_delete(): bool {
		$user_lid = (string) lunaTools::request('user_lid');
		if ($user_lid !== '') {
			$inerror = 0;
			if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
			$_POST['modify_user_is_inactive'] = isset($_POST['modify_user_is_inactive']) ? ($_POST['modify_user_is_inactive'] == 1 ? 1 : 0) : 0;
			// check if the user exists — same two-step as submit_modify(): the lid names the row
			// to load, the ACL-scoped index says whether this requester may address it
			luna::model()->merge_index(luna::model()->load_users(intval(luna::model()->get_nid_from_lid($user_lid))));
			$user_nid = intval(luna::model()->check_requested_node_by_lid('user_lid', 'Person', 'foaf'));
			if (empty($user_nid)) {
				$inerror++;
				$message = sprintf(_("User “%1\$s” does not exist."), $user_lid);
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			}
			if ($inerror) { return false; }
			$user_node = luna::model()->get_node($user_nid, 'Person', 'foaf');
			$user_lid = luna::model()->get_lid($user_node);
			// Are we trying to delete ourselve? (we cannot allow this to happen)
			if ($user_nid == luna::$session->user->nid) {
				$inerror++;
				$message = _('You cannot delete yourself.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// Are we trying to delete an innocent guest? shame on us..
			if ($_POST['modify_user_email'] == ANONYMOUS) {
				$inerror++;
				$message = _('You cannot delete the guest user.');
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_NOTICE);
			}
			if ($inerror) { return false; }
			// admin-lockout guardrail: never delete the last active administrator
			// (self-deletion is already blocked above; this covers delegated admins).
			$group_admin_nid = luna::model()->get_nid_from_lid('group_admin');
			if (!empty($group_admin_nid)) {
				$total_admins = lunaAuthz::active_admin_count();
				$other_admins = lunaAuthz::active_admin_count($user_nid);
				if (($total_admins > $other_admins) && $other_admins < 1) {
					$message = _('You cannot delete the last administrator.');
					luna::$messages['warning'][] = $message; lunaLog::log($message, PEAR_LOG_WARNING);
					return false;
				}
			}
			if (luna::model()->delete($user_nid)) {
				$res = lunaDB::query('
					DELETE FROM
						'.luna::get_ini('DBtables', 'USERS').'
					WHERE
						nid = '.lunaDB::quote($user_nid).'
				');
				lunaTools::purge_cache();
				luna::model()->purge_index();
				$message = sprintf(_("User “%1\$s” has been deleted."), ($_POST['modify_user_firstname'].' '.$_POST['modify_user_lastname']));
				luna::$messages['okay'][] = $message;
				lunaLog::log($message, PEAR_LOG_INFO);
				lunaTools::unrequest(['modify_user_email', 'modify_user_groups', 'modify_user_firstname', 'modify_user_lastname', 'modify_item_lid', 'user_lid']);
			} else {
				$message = sprintf(_("The deletion of the user “%1\$s” has failed."), ($_POST['modify_user_firstname'].' '.$_POST['modify_user_lastname']));
				luna::$messages['warning'][] = $message;
				lunaLog::log($message, PEAR_LOG_WARNING);
			}
		}
		return true;
	}
	// }}}
	// {{{ load()
	/**
	 * load()
	 * @return bool
	 */
	public function load(): bool {
		$inerror = 0;
		if (!luna::model()->merge_index(luna::model()->load_nodes('group', 'level'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		$user_lid = (string) lunaTools::request('user_lid');
		luna::model()->merge_index(luna::model()->load_users(intval(luna::model()->get_nid_from_lid($user_lid))));
		luna::model()->check_requested_node_by_lid('user_lid', 'Person', 'foaf');
		return true;
	}
	// }}}
}
// }}}
