<?php

/**
 * lunar mod_admin module
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
class mod_admin {
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
			'Configuration form',
			'Configuration',
			'Yes',
			'No',
			'Save',
			'Literal identifier',
			'Literal id',
			'disable',
			'keywords',
			'session_length',
			'startdate',
			'root_module',
			'disable_txt',
			'langs',
			'cache_timeout',
			'author',
			'sitename',
			'version',
			'general_email',
			'site_desc',
			'timezone'
		]);
	}
	// }}}
	// {{{ submit()
	/**
	 * submit()
	 * @return bool
	 */
	public function submit(): bool {
		$submits = [];
		foreach (luna::$ini['config'] as $key => $value) {
			$submitvalue = false;
			if ($submitvalue = lunaTools::request($key, [$_POST])) {
				if ($submitvalue != $value) { $submits[$key] = $submitvalue; }
			}
		}
		if (luna::get_ini('config', 'disable')) {
			if (!$disable = lunaTools::request('disable', [$_POST])) { $submits['disable'] = 0; }
		}
		if (!empty($submits)) {
			$sql_delete = '';
			$sql_insert = '';
			foreach ($submits as $key => $value) {
				$sql_delete .= lunaDB::quote($key).',';
				$sql_insert .= '('.lunaDB::quote($key).', '.lunaDB::quote($value).'),';
				luna::$ini['config'][$key] = $value;
			}
			$sql_delete = substr($sql_delete, 0, -1);
			$sql_insert = substr($sql_insert, 0, -1);
			$res = lunaDB::query('
				DELETE FROM
					'.luna::get_ini('DBtables', 'CONFIG').'
				WHERE
					name IN ('.$sql_delete.')
			');
			$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'CONFIG').'
					(name, value)
				VALUES
					'.$sql_insert.'
			');
			lunaTools::purge_cache();
			luna::model()->purge_index();
			$message = _("The website configuration has been updated.");
			luna::$messages['okay'][] = $message;
			lunaLog::log($message, PEAR_LOG_INFO);
		} else {
			$message = _("Nothing changed.");
			luna::$messages['notice'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
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
		luna::model()->merge_index(luna::model()->load_data(luna::$ini['config'], 'config'));
		return true;
	}

	// }}}
}
// }}}
