<?php

/**
 * lunar mod_edit_texts module
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
class mod_edit_texts {
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
		luna::model()->merge_index(luna::model()->load_var([
			'type' => 'data',
			'lid' => 'markdown',
			'value' => '1'
		]));
		lunaTools::add_vocabulary([
			'Written in Markdown (headings, bold, italics, links, lists).',
			'Add a text',
			'Modify the text',
			'Add',
			'Modify',
			'Delete',
			'List of the texts',
			'Delete a text',
			'identifier',
			'title',
			'content',
			'lang',
			'id',
			'Are you sure you want to delete this text?',
			'modified',
			'When',
			'Who',
			'Language',
			'Literal identifier',
			'Literal id',
			'Pages using the text',
			'Filter',
			'Modules using the text',
			'Deactivate',
			'Texts'
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
		$_POST['add_text_lid'] = lunaTools::prepare_lid($_POST['add_text_lid']);
		// check emptyness
		if (!lunaTools::check_emptyness('add_text_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('add_text_content', 'content')) { $inerror++; }
		if ($inerror) { return false; }
		$_POST['add_text_is_inactive'] = isset($_POST['add_text_is_inactive']) ? ($_POST['add_text_is_inactive'] == 1 ? 1 : 0) : 0;
		// set default values
		$langs = luna::get_ini('config', 'site_langs');
		if (!isset($_POST['add_text_lang']) || empty($_POST['add_text_lang']) || !in_array($_POST['add_text_lang'], $langs)) { $_POST['add_text_lang'] = isset($langs[0]) ? $langs[0] : 'en'; }
		if (!isset($_POST['add_text_pages'])) { $_POST['add_text_pages'] = []; }
		if (!isset($_POST['add_text_title']) || empty($_POST['add_text_title'])) { $_POST['add_text_title'] = ''; }
		// check if identifier is already used
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['add_text_lid'])) { return false; }
		$_POST['add_text_pages'] = luna::model()->nids_from_lids($_POST['add_text_pages'] ?? [], 'page');
		if (isset($_POST['add_text_pages']) && !empty($_POST['add_text_pages'])) {
			foreach ($_POST['add_text_pages'] as $postpage_nid) {
				if (!$postpage_node = luna::model()->get_node($postpage_nid, 'page')) {
					$inerror++;
					$message = _('Unknown page '.intval($postpage_nid));
					luna::$messages['warning'][] = $message;
					lunaLog::log($message, PEAR_LOG_WARNING);
				} elseif (!lunaAuthz::user_can_access_page($postpage_node)) {
					$inerror++;
					$message = _('Access denied to page '.intval($postpage_nid));
					luna::$messages['warning'][] = $message;
					lunaLog::log('edit_texts: attempt to link a text to an inaccessible page '.intval($postpage_nid), PEAR_LOG_WARNING);
				}
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->insert('text', $_POST['add_text_lid'], ($_POST['add_text_is_inactive'] ? 0 : 1))) {
			luna::model()->link($node, $_POST['add_text_pages']);
			// Same upsert as submit_modify(), for the same reason: (nid, lang) is the row's
			// identity, so a second add under a language that already exists must update that
			// translation rather than insert a duplicate the unique index would reject.
			$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'TEXTS').'
					(nid, title, lang, content)
				VALUES
					(
						'.lunaDB::quote($node).',
						'.lunaDB::quote($_POST['add_text_title']).',
						'.lunaDB::quote(lunaTools::content_language($_POST['add_text_lang'])).',
						'.lunaDB::quote($_POST['add_text_content']).'
					)
				ON DUPLICATE KEY UPDATE
					title = VALUES(title),
					content = VALUES(content)
			');
			// RDF write-through: project the new text (and its page links) into the graph (best-effort).
			lunaGraph::rdf_sync_node($node);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The text “%1\$s” has been created."), $_POST['add_text_lid']);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['text_lid', 'modify_item_lid']);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['add_text_lid']));
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
		// clean things
		$_POST['modify_text_lid'] = lunaTools::prepare_lid($_POST['modify_text_lid']);
		// check emptyness
		if (!lunaTools::check_emptyness('modify_item_lid', 'Text to modify')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_text_lid', 'Literal identifier')) { $inerror++; }
		if (!lunaTools::check_emptyness('modify_text_content', 'content')) { $inerror++; }
		if ($inerror) { return false; }
		// set default values
		$langs = luna::get_ini('config', 'site_langs');
		if (!isset($_POST['modify_text_lang']) || empty($_POST['modify_text_lang']) || !in_array($_POST['modify_text_lang'], $langs)) { $_POST['modify_text_lang'] = isset($langs[0]) ? $langs[0] : 'en'; }
		$_POST['modify_text_is_inactive'] = isset($_POST['modify_text_is_inactive']) ? ($_POST['modify_text_is_inactive'] == 1 ? 1 : 0) : 0;
		// load stuff. The lid names the row to load; the ACL-scoped index then says whether this
		// requester may address it, and user_can_act_on_text() — which asks whether the text's
		// PAGE is within reach — still receives the same integer it always did.
		$modify_item_lid = (string) lunaTools::request('modify_item_lid');
		luna::model()->merge_index(luna::model()->load_texts(intval(luna::model()->get_nid_from_lid($modify_item_lid))));
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'text'));
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($modify_item_nid, 'text')) { return false; }
		if (!lunaAuthz::user_can_act_on_text($modify_item_nid)) {
			luna::$messages['warning'][] = _('Access denied: this text belongs to a page above your level.');
			lunaLog::log(
				'edit_texts: denied acting on a text bound to an inaccessible page ('.$modify_item_lid.')',
				PEAR_LOG_WARNING
			);
			return false;
		}
		// check if identifier is already used by antoher item
		if (!$is_not_taken = luna::model()->check_if_lid_is_taken($_POST['modify_text_lid'], $modify_item_nid)) { return false; }
		$_POST['modify_text_pages'] = luna::model()->nids_from_lids($_POST['modify_text_pages'] ?? [], 'page');
		if (isset($_POST['modify_text_pages']) && !empty($_POST['modify_text_pages'])) {
			foreach ($_POST['modify_text_pages'] as $postpage_nid) {
				if (!$postpage_node = luna::model()->get_node($postpage_nid, 'page')) {
					$inerror++;
					$message = _('Unknown page '.intval($postpage_nid));
					luna::$messages['warning'][] = $message;
					lunaLog::log($message, PEAR_LOG_WARNING);
				} elseif (!lunaAuthz::user_can_access_page($postpage_node)) {
					$inerror++;
					$message = _('Access denied to page '.intval($postpage_nid));
					luna::$messages['warning'][] = $message;
					lunaLog::log('edit_texts: attempt to link a text to an inaccessible page '.intval($postpage_nid), PEAR_LOG_WARNING);
				}
			}
		}
		if ($inerror) { return false; }
		if ($node = luna::model()->update($modify_item_nid, $_POST['modify_text_lid'], ($_POST['modify_text_is_inactive'] ? 0 : 1))) {
			if (isset($_POST['modify_text_pages']) && !empty($_POST['modify_text_pages'])) { luna::model()->unlink($node, 'page'); luna::model()->link($node, $_POST['modify_text_pages']); }
			// One row per (node, language). The previous statement matched on nid alone and set the
			// language column too, so saving a translation rewrote EVERY language row for the node
			// to the submitted language and body — editing the French text destroyed the English
			// one, silently, with no way back. Keying the write on (nid, lang) makes that
			// impossible; the UNIQUE index added in luna.mysql.sql means the database refuses it
			// even if some future caller forgets.
			//
			// The upsert is deliberate rather than incidental: the language selector now chooses
			// WHICH translation is being written, so saving under a language that has no row yet
			// creates that translation, and saving under one that exists updates it. That is the
			// whole "add a translation" flow, with no new form and no second code path.
			$lang = lunaTools::content_language($_POST['modify_text_lang']);
			$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'TEXTS').'
					(nid, title, lang, content)
				VALUES
					(
						'.lunaDB::quote($node).',
						'.lunaDB::quote($_POST['modify_text_title']).',
						'.lunaDB::quote($lang).',
						'.lunaDB::quote($_POST['modify_text_content']).'
					)
				ON DUPLICATE KEY UPDATE
					title = VALUES(title),
					content = VALUES(content)
			');
			// RDF write-through: re-project the edited text into the graph
			// (best-effort; see docs/linked-data.md).
			lunaGraph::rdf_sync_node($node);
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The text “%1\$s” has been modified."), _($_POST['modify_text_lid']));
			lunaTools::unrequest(['text_lid', 'modify_item_lid']);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($_POST['modify_text_lid']));
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
		if (!lunaTools::check_emptyness('modify_item_lid', 'Text to modify')) { $inerror++; }
		if ($inerror) { return false; }
		// load stuff. The lid names the row to load; the ACL-scoped index then says whether this
		// requester may address it, and user_can_act_on_text() — which asks whether the text's
		// PAGE is within reach — still receives the same integer it always did.
		$modify_item_lid = (string) lunaTools::request('modify_item_lid');
		luna::model()->merge_index(luna::model()->load_texts(intval(luna::model()->get_nid_from_lid($modify_item_lid))));
		$modify_item_nid = intval(luna::model()->check_requested_node_by_lid('modify_item_lid', 'text'));
		// check if node exists
		if (!$item_node = luna::model()->check_if_node_exists($modify_item_nid, 'text')) { return false; }
		if (!lunaAuthz::user_can_act_on_text($modify_item_nid)) {
			luna::$messages['warning'][] = _('Access denied: this text belongs to a page above your level.');
			lunaLog::log(
				'edit_texts: denied acting on a text bound to an inaccessible page ('.$modify_item_lid.')',
				PEAR_LOG_WARNING
			);
			return false;
		}
		if ($inerror) { return false; }
		if (luna::model()->delete($modify_item_nid)) {
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = sprintf(_("The text “%1\$s” has been deleted."), $modify_item_nid);
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
			lunaTools::unrequest(['text_lid', 'modify_item_lid']);
		} else {
			$message = sprintf(_("The modification of the item “%1\$s” has failed."), _($modify_item_nid));
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
		// if (!luna::model()->merge_index(luna::model()->load_nodes('text'))) { throw new lunaException(_('Error: cannot load data'), PEAR_LOG_CRIT); }
		$text_lid = (string) lunaTools::request('text_lid');
		luna::model()->merge_index(luna::model()->load_texts(intval(luna::model()->get_nid_from_lid($text_lid))));
		luna::model()->check_requested_node_by_lid('text_lid', 'text');
		return true;
	}
	// }}}
}
// }}}
