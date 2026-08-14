<?php

/**
 * lunar mod_example module
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
class mod_example {
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
			'example'
		]);
	}
	// }}}
	// {{{ submit()
	/**
	 * do things
	 * @return bool
	 */
	public function submit(): bool {
		// do things
		return true;
	}
	// }}}
	// {{{ load()
	/**
	 * load things
	 * @return bool
	 */
	public function load(): bool {
		// load things
		return true;
	}
	// }}}
}
// }}}
