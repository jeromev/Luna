<?php

/**
 * lunarSystem
 *
 * PHP 8.1+ (tested on 8.3)
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
 * For more details, see <http://www.gnu.org/copyleft/gpl.html>
 *
 * @author Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link https://github.com/jeromev/LunarSystem
 * @package lunarSystem
 * @since 2006-07-03
 */

require_once './luna/luna.php';
if ($luna = luna::singleton()) {
	if ($luna->load_mods()) {
		die($luna->transform());
	}
}
