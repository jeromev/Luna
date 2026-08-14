<?php

/**
 * resync-triplestore — rebuild the Oxigraph triplestore from MySQL.
 *
 * The runnable form of lunaGraph::rdf_resync_all($prune = true): clears the graph,
 * then re-projects every relational node through the same write-through the app's CRUD
 * uses, bringing Oxigraph to exact parity with MySQL (graph-only orphans dropped). This
 * is the pure-PHP "rebuild the store" path the docs reference. Invoke it with:
 *
 *   make resync-triplestore                         # from the host (wraps the line below)
 *   docker-compose exec -T app php bin/resync-triplestore.php
 *
 * Honours the app's environment: SPARQL_UPDATE_ENDPOINT / SPARQL_AUTH_USER /
 * SPARQL_AUTH_PASS (where + how to write), and SITE_URI (default http://localhost:8080)
 * for the <base>/id/{slug} resource URIs — SITE_URI MUST match the host the app serves
 * under, or the rebuilt URIs won't match the read path.
 *
 * @package lunarSystem
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "resync-triplestore.php is CLI-only.\n"); exit(2); }

// luna.php defines the SPARQL_* constants and the luna / lunaDB / lunaModel classes. Its
// web constructor (luna::singleton()) loads a page + session, which a maintenance task
// neither has nor needs — so do a minimal non-web bootstrap by hand and let the
// LUNA_MAINTENANCE guard skip the model's session-bound read-model build.
define('LUNA_MAINTENANCE', true);
require_once __DIR__.'/../luna/luna.php';

luna::$lunaPath = realpath(__DIR__.'/../luna').'/';
if (!defined('LUNAPATH')) { define('LUNAPATH', luna::$lunaPath); }
luna::$site_path = LUNAPATH.'luna.domains/luna.default/';
if (!defined('SITEPATH')) { define('SITEPATH', luna::$site_path); }
if (!defined('INI_PATH')) { define('INI_PATH', SITEPATH.'ini/'); }
luna::$site_uri = rtrim(getenv('SITE_URI') ?: 'http://localhost:8080', '/');

// Prepend the bundled PEAR/Log/etc. so the class requires below resolve, exactly as the
// web constructor does (luna.log.class.php requires PEAR's Log.php from luna.lib).
ini_set('include_path', LUNAPATH.'luna.lib'.PATH_SEPARATOR.ini_get('include_path'));

// Parse the site ini as luna::load_ini() does on the web path, so get_ini('DBtables', …)
// and the [Paths]/[Constantes] defines the model relies on are present.
$ini = @parse_ini_file(INI_PATH.'luna.ini', true);
if (!$ini || empty($ini['DBtables'])) { fwrite(STDERR, 'Error: cannot load '.INI_PATH."luna.ini\n"); exit(1); }
foreach ($ini['Paths'] as $k => $v) { if (!defined($k)) { define($k, LUNAPATH.$v); } }
foreach ($ini['Constantes'] as $k => $v) { if (!defined($k)) { define($k, $v); } }
if (!defined('ANONYMOUS')) { define('ANONYMOUS', 'guest'); }
luna::$ini = $ini;

require_once LUNAPATH.'luna.classes/luna.log.class.php';
require_once LUNAPATH.'luna.classes/luna.tools.class.php';
require_once LUNAPATH.'luna.classes/luna.db.class.php';
require_once LUNAPATH.'luna.classes/luna.graph.class.php';
require_once LUNAPATH.'luna.classes/luna.model.class.php';

if (!lunaDB::prepare() || !lunaDB::connect()) { fwrite(STDERR, "Error: cannot connect to the database.\n"); exit(1); }
if (!defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) {
	fwrite(STDERR, "Error: SPARQL_UPDATE_ENDPOINT is not configured; nothing to rebuild.\n"); exit(1);
}

$model = lunaModel::singleton();
fwrite(STDOUT, 'Rebuilding the triplestore at '.SPARQL_UPDATE_ENDPOINT.' (base '.luna::$site_uri.") from MySQL …\n");
$n = lunaGraph::rdf_resync_all($model->outbound_index(), true);
fwrite(STDOUT, "Re-projected $n node(s) from MySQL into the triplestore.\n");
exit($n > 0 ? 0 : 1);
