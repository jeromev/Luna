<?php

/**
 * Constants that exist at runtime but not at analysis time — a bootstrap stub for PHPStan only.
 * This file is never loaded by the application.
 *
 * luna::load_ini() defines the [Paths] and [Constantes] sections of luna.ini with a
 * `foreach (… ) { define($k, …); }` loop. A static analyser cannot see through a define()
 * whose name is a variable, so every use of XSL_PATH, PERPAGE and friends would otherwise be
 * reported as an undefined constant. Declaring them here is a statement about what the ini
 * contract guarantees, not a suppression: if the ini stops defining one of these, the app
 * breaks and this file becomes the record of what it promised.
 *
 * Keep in step with luna/luna.domains/luna.default/ini/luna.ini.
 *
 * @author  Jérôme Vogel
 * @license http://www.gnu.org/copyleft/gpl.html  GPL
 * @link    https://github.com/jeromev/Luna
 */

// [Paths] — each is defined as LUNAPATH . <value>
define('MODS_PATH', '');
define('CLASSES_PATH', '');
define('LOCALE_PATH', '');
define('XSL_PATH', '');

// Defined at runtime by the bootstrap and by lunaTools::check_cache(), both of which run
// before any code that reads them.
define('NOW', 0);
define('CACHE_PATH', '');

// [Constantes]
define('ANONYMOUS', 'guest');
define('PERPAGE', 20);
define('CACHE', 0);
define('INCLUDEPATH', '');
define('CLEAN_URLS', 1);
define('DEBUG', 0);
