<?php

/**
 * lunar mod_node module
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
class mod_node {
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
			'node'
		]);
	}
	// }}}
	// {{{ load()
	/**
	 * load things
	 * @return bool
	 */
	public function load(): bool {
		try {
			if (!isset(luna::$data['subdir']) || empty(luna::$data['subdir'])) { throw new lunaException(_('Notice: node nid undefined.'), PEAR_LOG_NOTICE); }
			luna::model()->merge_index($nodes = luna::model()->load_nodes(false, 'level', luna::$data['subdir']));
			$node = luna::model()->get_node(luna::$data['subdir']);
			if (!$node) { throw new lunaException(_('Warning: requested node does not exist.'), PEAR_LOG_WARNING); }
			$node_level_node = luna::model()->get_level_node($node);
			if (!$node_level_node) { throw new lunaException(_('Error: level node not found.'), PEAR_LOG_CRIT); }
			$node_level_nid = intval(luna::model()->get_nid($node_level_node, 'level'));
			if (!lunaAuthz::user_can_access_level(luna::$session->user, $node_level_nid)) { throw new lunaException(_('Error: restricted access.'), PEAR_LOG_CRIT); }
			$graph = [luna::model()->node_path.'/'.intval(luna::$data['subdir']) => $node];
			lunaTools::emit(
				luna::model()->serialize('xml', $graph),
				lunaModel::content_type('xml'),
				lunaModel::data_response_csp()
			);
		} catch (lunaException $e) {
			lunaLog::log($e);
			die();
		}
		return true;
	}
	// }}}
}
// }}}
