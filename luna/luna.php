<?php

/**
 * Welcome to the lunarSystem. This is the main class.
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
 * @since		2006-07-03
 */
/**
 * Basic setup
 */
// Set time one time for all
define('NOW', time());
// PHP 5.3+ requires an explicit default timezone or every date() call warns.
// Honour an existing php.ini date.timezone; otherwise fall back to UTC.
if (function_exists('date_default_timezone_set')) {
	$tz = @date_default_timezone_get();
	date_default_timezone_set($tz ? $tz : 'UTC');
}
// Turn OFF/ON public error display
ini_set('display_errors', 0);
// Cookie-only sessions: no SID in the URL, and reject attacker-supplied SIDs.
ini_set('session.use_trans_sid', 0);
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('arg_separator.output', '&amp;');
// Report everything. The PHP 5.2/5.3 idioms this mask was once justified by (`=& new`,
// each(), create_function, ereg*, mysql_*) are all gone from the tree, and masking
// E_DEPRECATED hid a dynamic-property write that is fatal on PHP 9. Display stays off
// (see display_errors above); this governs what is *reported*, not what is shown.
error_reporting(E_ALL);
// --- deployment config helpers -------------------------------------------------------------
// Read a setting from the real environment OR an Apache `SetEnv` ($_SERVER), so a shared host
// with no Docker / shell env control can still configure the app from .htaccess.
if (!function_exists('luna_env')) {
	function luna_env($key, $default = '') {
		$v = getenv($key);
		if (($v === false || $v === '') && isset($_SERVER[$key]) && $_SERVER[$key] !== '') { $v = $_SERVER[$key]; }
		return ($v === false || $v === '') ? $default : $v;
	}
}
// Trust X-Forwarded-* headers (set only behind a known reverse proxy / load balancer).
if (!defined('TRUST_PROXY')) { define('TRUST_PROXY', luna_env('TRUST_PROXY', '0') === '1'); }
// Whether the current request is HTTPS — honoured for the canonical scheme, secure cookies and
// HSTS. A front proxy's X-Forwarded-Proto is trusted only when TRUST_PROXY is on (so the header
// can't be spoofed on a directly-served host like DreamHost shared, where $_SERVER['HTTPS'] is set).
if (!function_exists('luna_is_https')) {
	function luna_is_https() {
		if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') { return true; }
		if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) { return true; }
		if (defined('TRUST_PROXY') && TRUST_PROXY) {
			if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https') { return true; }
			if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') { return true; }
		}
		return false;
	}
}
// Master switch for the triplestore layer (SPARQL read + write-through). On by default for the
// Docker stack; set SPARQL_ENABLED=0 (e.g. `SetEnv SPARQL_ENABLED 0` in .htaccess) for a pure
// PHP/MySQL deploy with no triplestore — the publishing surface (HTML + content-negotiated
// RDF/JSON-LD + /id + /data + sitemap) runs entirely from MySQL, so nothing reaches for an
// endpoint that isn't there. See docs/going-public.md.
if (!defined('SPARQL_ENABLED')) { define('SPARQL_ENABLED', luna_env('SPARQL_ENABLED', '1') !== '0'); }
// SPARQL endpoint for the read path. Default = the authenticating reverse proxy (Caddy, HTTP
// basic auth) in front of Oxigraph (the materialised, dual-write-synced triplestore — RDF-native).
// Override to Ontop (virtual SPARQL over MySQL) to read live from the relational store instead:
// SPARQL_ENDPOINT=http://ontop:8080/sparql. See docs/linked-data.md.
if (!defined('SPARQL_ENDPOINT')) { define('SPARQL_ENDPOINT', luna_env('SPARQL_ENDPOINT', 'http://sparql-proxy:7878/query')); }
// SPARQL UPDATE endpoint for content write-through to the triplestore (via the proxy). Best-effort.
if (!defined('SPARQL_UPDATE_ENDPOINT')) { define('SPARQL_UPDATE_ENDPOINT', luna_env('SPARQL_UPDATE_ENDPOINT', 'http://sparql-proxy:7878/update')); }
// Basic-auth credentials the app presents to the SPARQL proxy. The proxy authenticates every
// request before forwarding to Oxigraph, so the triplestore's unauthenticated /update + /store are
// never reachable without these (Oxigraph is also isolated on an internal-only compose network).
// Demo defaults — set SPARQL_AUTH_PASS via .env / the environment for any real use.
if (!defined('SPARQL_AUTH_USER')) { define('SPARQL_AUTH_USER', luna_env('SPARQL_AUTH_USER', 'luna')); }
if (!defined('SPARQL_AUTH_PASS')) { define('SPARQL_AUTH_PASS', luna_env('SPARQL_AUTH_PASS', 'luna-sparql-dev')); }
// Read routing / ACL / texts through SPARQL by default (the triplestore is authoritative for the
// read path; MySQL stays the system of record and a fallback). Set SPARQL_READS=0 to read from SQL.
if (!defined('SPARQL_READS')) { define('SPARQL_READS', luna_env('SPARQL_READS', '1') === '0' ? false : true); }
// Canonical base URI for the site's identity. The cardinal rule is "freeze the URIs": /id/{slug} is
// stable identity, and it (plus JSON-LD @id, canonical/Link headers, the sitemap, and the triplestore
// write IRIs) must NOT depend on the request's Host header — otherwise serving under a second hostname
// silently forks the graph's identity. Set SITE_URI (e.g. `SetEnv SITE_URI https://example.org` in
// .htaccess, or the env) to pin it. Leave empty to derive from the request Host (fine on localhost /
// single-host dev). Include a base path if the app lives in a subdirectory, e.g. https://example.org/luna.
if (!defined('SITE_URI')) { define('SITE_URI', rtrim(luna_env('SITE_URI', ''), '/')); }
// Composer autoloader — brings in HTMLPurifier (the input sanitiser; see
// lunaTools::sanitize()). Hard require: the app is a security boundary and must not run
// without its sanitiser, so fail loudly if the vendored tree is missing.
require_once DIRNAME(__FILE__).'/../vendor/autoload.php';
/**
 * luna Class
 */
// {{{
class luna {
	/**
	 * lunaVersion
	 * @var		string
	 */
	public static $lunaVersion = '0.8.66-alpha';
	/**
	 * instance
	 * @var self|null
	 */
	private static $instance;
	/**
	 * messages
	 * @var		array
	 */
	public static $messages = [];
	/**
	 * protected_lids — structural nodes the admin tier depends on. Deleting or
	 * repurposing any of them can lock every administrator out of the site, so
	 * lid_is_protected() and the central guard in lunaModel::delete() refuse to
	 * touch these whichever handler asks.
	 * @var		array
	 */
	private static $protected_lids = [
		'admin', 'admin_groups', 'admin_levels', 'admin_users', 'admin_mods', 'admin_pages', 'journal', 'login', 'logout',
		'group_admin', 'group_default', 'level_admin', 'level_public',
		'mod_admin', 'mod_admin_groups', 'mod_admin_levels', 'mod_admin_mods', 'mod_admin_pages', 'mod_admin_users', 'mod_journal',
	];
	/**
	 * SitePath
	 * @var		string
	 */
	public static $site_path = '';
	/**
	 * lunaSession
	 * @var		lunaSession|null
	 */
	public static $session = null;
	/**
	 * language
	 * @var		string
	 */
	public static $lang = 'en-US';
	/**
	 * data model
	 * @var		lunaModel|null
	 */
	public static $model = null;
	/**
	 * ini vars
	 * @var		array
	 */
	public static $ini = [];
	/**
	 * page_node
	 * @var		array|false|null
	 */
	public static $page_node = null;
	/**
	 * Data
	 * @var		array
	 */
	public static $data = [];
	/**
	 * vocabulary
	 * @var		array
	 */
	public static $vocabulary = [];
	/**
	 * site_uri
	 * @var		string
	 */
	public static $site_uri = '';
	/**
	 * site_relative_url
	 * @var		string
	 */
	public static $site_relative_url = '';
	/**
	 * cache
	 * @var		bool
	 */
	public static $cache = false;
	/**
	 * Request start time, from microtime().
	 * @var		float|int
	 */
	public static $start = 0;
	/**
	 * Absolute path to luna/, with a trailing slash; false until the constructor sets it.
	 * @var		string|false
	 */
	public static $lunaPath = false;
	/**
	 * cache_timeout.
	 * @var		int
	 */
	public static $cache_timeout = 604800; // 604800 = 7 days
	/**
	 * URL Path: where we are
	 * @var		string
	 */
	public static $path = '';
	/**
	 * outPutFormat
	 * @var		string
	 */
	public static $output_format = 'html';
	/**
	 * output_formats
	 * @var		array
	 */
	public static $output_formats = ['html', 'xml', 'turtle', 'json', 'n3', 'jsonld'];
	/**
	 * mods
	 * @var		array
	 */
	public static $mods = [];
	// {{{ constructor
	/**
	 * Constructor.
	 * @return void
	 */
	private function __construct() {
		try {
			// First things first: prepend luna.lib so the bundled PEAR/MDB2/Log/etc.
			// take precedence over any system-installed PEAR in the PHP image. (If the
			// system PEAR.php wins, MDB2's PEAR::raiseError() resolves to a class
			// without that method and fatals via __call on the first DB error.)
			ini_set('include_path', DIRNAME(__FILE__).'/luna.lib'.':'.ini_get('include_path'));
			// start the timer
			self::$start = self::set_microtime();
			// Set the luna Path
			self::$lunaPath = preg_replace('@^\/+(.*?)\/+$@', '/$1/', '/'.DIRNAME(__FILE__).'/');
			define('LUNAPATH', self::$lunaPath);
			// Define Site Paths
			if (!$this->set_site_path()) { die(_('Error: cannot set site path.')); }
			// load basic prefs
			if (!self::$ini = $this->load_ini()) { die(_('Error: cannot load ini.')); }
			// add the include path if needed.
			if (self::get_ini('Constantes', 'INCLUDEPATH')) { ini_set('include_path', ini_get('include_path').':'.self::get_ini('Constantes', 'INCLUDEPATH').':'); }
			// require lunar Classes
			require_once LUNAPATH.'luna.classes/luna.log.class.php';
			require_once LUNAPATH.'luna.classes/luna.tools.class.php';
			require_once LUNAPATH.'luna.classes/luna.authz.class.php';
			require_once LUNAPATH.'luna.classes/luna.cache.class.php';
			require_once LUNAPATH.'luna.classes/luna.db.class.php';
			require_once LUNAPATH.'luna.classes/luna.session.class.php';
			require_once LUNAPATH.'luna.classes/luna.graph.class.php';
			require_once LUNAPATH.'luna.classes/luna.model.class.php';
			require_once LUNAPATH.'luna.classes/luna.render.class.php';
			// clean $_GET, $_POST, $_COOKIE, $_SESSION & $_REQUEST
			lunaTools::sanitize_inputs();
			// send baseline security headers before any output
			lunaTools::send_security_headers();
			// display errors if the DEBUG constant is set to 1.
			if (self::get_ini('Constantes', 'DEBUG')) { ini_set('display_errors', 1); }
			// check cache
			if (!lunaTools::check_cache()) { throw new lunaException(_('Error: cannot set cache.'), PEAR_LOG_CRIT); }
			// turn on/off Ajax
			define('AJAX', (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') ? true : false);
			// connect to the database
			if (!lunaDB::prepare()) { throw new lunaException(_('Error: cannot prepare the database.'), PEAR_LOG_CRIT); }
			if (!lunaDB::connect()) { throw new lunaException(_('Error: cannot connect to the database.'), PEAR_LOG_CRIT); }
			// load config
			if (!self::$ini['config'] = lunaTools::load_config()) { throw new lunaException(_('Error: cannot connect load config.'), PEAR_LOG_CRIT); }
			// set requested path
			if (!$this->set_requested_path()) { throw new lunaException(_('Error: cannot set requested path.'), PEAR_LOG_CRIT); }
			// Create a session object
			if (!self::$session = lunaSession::singleton()) { throw new lunaException(_('Error: cannot create a lunaSession object.'), PEAR_LOG_CRIT); }
			// Start the session
			if (!self::$session->start()) { throw new lunaException(_('Error: cannot start the lunaSession.'), PEAR_LOG_CRIT); }
			// set language
			if (!self::$lang = lunaTools::set_language()) { throw new lunaException(_('Error: cannot set language.'), PEAR_LOG_CRIT); }
			// set output format
			if (!self::$output_format = lunaTools::set_output_format()) { throw new lunaException(_('Error: cannot set output format.'), PEAR_LOG_CRIT); }
			// build RDF model
			if (!self::$model = lunaModel::singleton()) { throw new lunaException(_('Error: cannot create RDF object.'), PEAR_LOG_CRIT); }
			// Linked Data resource routing (P4): /id/{slug} is the resource identity —
			// 303 See Other to a concrete document by the negotiated format; /data/{slug}
			// is the RDF document describing it. Both resolve the slug against the
			// ACL-filtered graph, so they inherit the HTML view's access control.
			$this->route_linked_data();
			// Crawler-discovery endpoints (publishing surface): /sitemap.xml lists the
			// public page tree (as the requester sees it — anonymous for a crawler) and
			// /robots.txt points at it. Both emit and exit.
			if (self::$path === 'sitemap.xml') { lunaTools::emit(self::model()->build_sitemap(), 'application/xml; charset=utf-8'); }
			if (self::$path === 'robots.txt')  { lunaTools::emit(self::model()->build_robots(), 'text/plain; charset=utf-8'); }
			// if user is admin, disable cache
			if (lunaAuthz::user_can_access_level(self::$session->user, 'level_admin')) {
				self::$cache = false;
				define('IS_ADMIN', true);
			} else {
				define('IS_ADMIN', false);
			}
			// load Page data. If the page does not exist, output a 404.
			if (!self::$page_node = self::model()->get_page_node_from_alias(self::$path)) { lunaTools::raise_error_page('404', self::$path); }
			// Define current page NID
			if (!$page_nid = self::model()->get_nid(self::$page_node)) { throw new lunaException(_('Error: cannot find page nid.'), PEAR_LOG_CRIT); }
			define('PAGENID', $page_nid);
			// Define current page LID
			if (!$page_lid = self::model()->get_lid(self::$page_node)) { throw new lunaException(_('Error: cannot find page lid.'), PEAR_LOG_CRIT); }
			define('PAGELID', $page_lid);
			// Check privileges. If user is unauthorized, send him to login
			if (!lunaAuthz::check_privileges()) { lunaTools::go('login'); }
			if (!in_array(self::$output_format, self::$output_formats)) { self::$output_format = isset(self::$output_formats[0]) ? self::$output_formats[0] : 'html'; }
			// Advertise the resource's identity + alternate representations (Linked Data trail).
			lunaTools::send_alternate_links();
			// Load texts associated with the page — from the graph by default,
			// falling back to SQL if the SPARQL path is off or yields nothing.
			$texts = false;
			if (lunaGraph::sparql_reads()) { $texts = self::model()->load_texts_sparql(PAGENID); }
			if (empty($texts)) { $texts = self::model()->load_texts(0, PAGENID); }
			self::model()->merge_index($texts);
			// Collect Data
			if (!self::$data['lid'] = self::model()->get_lid(self::$page_node)) { throw new lunaException(_('Error: cannot find page lid.'), PEAR_LOG_CRIT); }
			self::$data['lunaversion'] = self::$lunaVersion;
			self::$data['site_uri'] = self::$site_uri;
			self::$data['site_relative_url'] = self::$site_relative_url;
			self::$data['sitename'] = _(self::get_ini('config', 'sitename'));
			self::$data['site_desc'] = self::get_ini('config', 'site_desc');
			self::$data['site_version'] = self::get_ini('config', 'version');
			self::$data['clean_urls'] = CLEAN_URLS;
			self::$data['author'] = self::get_ini('config', 'author');
			self::$data['general_email'] = self::get_ini('config', 'general_email');
			self::$data['lang'] = self::$lang;
			self::$data['csrf_token'] = self::$session->user->csrf_token ?? '';
			return;
		} catch (lunaException $e) {
			lunaLog::log($e);
			die();
		}
	}
	// }}}
	// {{{ model()
	/**
	 * The data model, guaranteed present.
	 *
	 * The static property this wraps is `lunaModel|null` — null before the constructor builds
	 * it — and that nullability made every one of the 246 calls through it unanalysable: a
	 * static analyser will not check argument types on a method call whose receiver it cannot
	 * resolve. A parameter type on lunaModel was therefore a hypothesis nothing could confirm,
	 * which is how merge_index(array $nodes) came to be handed a false. See docs/coding-style.md.
	 *
	 * Reaching the model before it exists is a bug, not a state to be handled politely, so this
	 * throws rather than returning null.
	 *
	 * @return lunaModel
	 */
	public static function model(): lunaModel {
		if (!self::$model instanceof lunaModel) {
			throw new lunaException(_('Error: the model was read before it was built.'), PEAR_LOG_CRIT);
		}
		return self::$model;
	}
	// }}}
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
	// {{{ set_requested_path()
	/**
	 * @return bool
	 */
	private function set_requested_path(): bool {
		// set requested path
		self::$path = preg_replace('@^\/*(.*?)\/*$@', '$1', lunaTools::request('path'));
		return true;
	}
	// }}}
	// {{{ route_linked_data()
	/**
	 * Linked Data resource routing (P4 — see docs/linked-data.md). Two reserved path
	 * prefixes turn the canonical /id/{slug} into dereferenceable Linked Data:
	 *
	 *  - /id/{slug}    the resource *identity*. Per the httpRange-14 / Cool URIs
	 *                  pattern it never returns content itself: it 303 See Others to a
	 *                  concrete document — the HTML page for a browser, the RDF
	 *                  /data/{slug} for a Linked Data client — by the negotiated format.
	 *  - /data/{slug}  the RDF *document* describing the resource. Served as Turtle /
	 *                  JSON-LD / RDF-XML / … by content negotiation, never as HTML; it
	 *                  re-enters the normal pipeline against the resource's alias so its
	 *                  texts load and dump() emits the same graph as ?output=.
	 *
	 * The slug resolves against the ACL-filtered graph the model just built, so a
	 * resource the current user can't see 404s here exactly as the HTML view does.
	 *
	 * @return void
	 */
	private function route_linked_data(): void {
		$path  = self::$path;
		$is_id = (strpos($path, 'id/') === 0);
		$is_dt = (strpos($path, 'data/') === 0);
		if (!$is_id && !$is_dt) { return; }
		$slug = $is_id ? substr($path, 3) : substr($path, 5);
		// a single path segment names the resource; anything deeper is not an /id|/data URI
		if ($slug === '' || strpos($slug, '/') !== false) { lunaTools::raise_error_page('404', $path); }
		if (!$node = self::model()->get_node_from_slug($slug, 'page')) { lunaTools::raise_error_page('404', $path); }
		$base = rtrim(self::$site_uri, '/');
		if ($is_id) {
			// 303 to the concrete document chosen by the already-negotiated format.
			if (self::$output_format === 'html') {
				$target = lunaTools::link((string) self::model()->get_alias($node), true);
			} else {
				$target = $base.'/data/'.rawurlencode($slug);
				$req = lunaTools::request('output');
				if (!empty($req) && in_array("$req", self::$output_formats)) { $target .= '?output='.rawurlencode($req); }
			}
			lunaTools::redirect($target, 303);
		}
		// /data/{slug}: a data document — never HTML. Default to Turtle (the canonical
		// RDF text form) when content negotiation didn't pick an RDF flavour.
		if (self::$output_format === 'html') { self::$output_format = 'turtle'; }
		// Re-enter the normal pipeline against the resource's canonical alias, so texts
		// load and the page node resolves exactly as for the HTML view.
		self::$path = (string) self::model()->get_alias($node);
	}
	// }}}
	// {{{ set_site_path()
	/**
	 * @return bool
	 */
	private function set_site_path(): bool {
		$site_path = LUNAPATH.'luna.domains';
		$sitefound = false;
		// {{{ Copied from Drupal 5.1 function conf_path() (file: bootstrap.inc, line: 195, license: GPL 2)
		/**
		 * Find the appropriate configuration directory.
		 *
		 * Try finding a matching configuration directory by stripping the website's
		 * hostname from left to right and pathname from right to left. The first
		 * configuration file found will be used; the remaining will ignored. If no
		 * configuration file is found, return a default value '$site_path/luna.Default/'.
		 *
		 * Example for a fictitious site installed at
		 * http://www.mywebsite.org:8080/mysite/test/ the 'luna.ini' is searched in
		 * the following directories:
		 *
		 *  1. $site_path/8080.www.mywebsite.org.mysite.test/
		 *  2. $site_path/www.mywebsite.org.mysite.test/
		 *  3. $site_path/mywebsite.org.mysite.test/
		 *  4. $site_path/org.mysite.test/
		 *  5. $site_path/8080.www.mywebsite.org.mysite/
		 *  6. $site_path/www.mywebsite.org.mysite/
		 *  7. $site_path/mywebsite.org.mysite/
		 *  8. $site_path/org.mysite/
		 *  9. $site_path/8080.www.mywebsite.org/
		 * 10. $site_path/www.mywebsite.org/
		 * 11. $site_path/mywebsite.org/
		 * 12. $site_path/org/
		 * 13. $site_path/luna.Default/
		 */
		$uri = explode('/', $_SERVER['PHP_SELF'] ? $_SERVER['PHP_SELF'] : $_SERVER['SCRIPT_FILENAME']);
		$server = explode('.', implode('.', array_reverse(explode(':', rtrim($_SERVER['HTTP_HOST'], '.')))));
		for ($i = count($uri) - 1; $i > 0; $i--) {
			for ($j = count($server); $j > 0; $j--) {
				$dir = implode('.', array_slice($server, -$j)).implode('.', array_slice($uri, 0, $i));
				if (file_exists("$site_path/$dir/ini/luna.ini")) {
					$site_path = "$site_path/$dir/";
					$sitefound = true;
					break;
				}
			}
		}
		// }}} end drupal
		if ($sitefound == false) { $site_path = "$site_path/luna.default/"; }
		self::$site_path = $site_path;
		//  {{{ Copied from Drupal 5.1 function conf_init() (file: bootstrap.inc, line: 235, license: GPL 2)
		$base_root = luna_is_https() ? 'https' : 'http';
		$base_url = $base_root.'://'.$_SERVER['HTTP_HOST'];
		if ($dir = trim(dirname($_SERVER['PHP_SELF']), '\,/')) {
			$base_path = "/$dir";
		} else {
			$base_path = '';
		}
		// }}} end drupal
		// Absolute identity base. A configured SITE_URI wins over the Host-derived value (see the
		// SITE_URI define near the top of this file and the "freeze the URIs" rule) so RDF IRIs /
		// JSON-LD @id / canonical links / the sitemap stay stable regardless of the request Host. The
		// relative base path (used only for building in-app links) stays request-derived either way.
		self::$site_uri = (defined('SITE_URI') && SITE_URI !== '') ? SITE_URI : $base_url.$base_path;
		self::$site_relative_url = $base_path.'/';
		if (!defined('SITEPATH')) { define('SITEPATH', self::$site_path); }
		return true;
	}
	// }}}
	// {{{ load_mods()
	/**
	 * Load all the modules from the database into the xml
	 *
	 * @return	bool
	 */
	public function load_mods(): bool {
		try {
			if (self::$cache) { $cache_obj = new lunaCache(['cacheDir' => CACHE_PATH, 'lifetime' => self::$cache_timeout]); }
			if (self::$cache && ($cache_str = $cache_obj->get('node-'.PAGENID.'.mods'))) {
				$nodes = unserialize($cache_str, ['allowed_classes' => false]);
			} else {
				// load mods from db
				$res = lunaDB::query('
					SELECT
						DISTINCT m.nid,
						m.lid,
						m.is_active,
						l.nid as nid2,
						l.lid as lid2,
						l.is_active as is_active2
					FROM
						'.self::get_ini('DBtables', 'NODES').' m,
						'.self::get_ini('DBtables', 'NODES').' l,
						'.self::get_ini('DBtables', 'NODES_MAP').' pm,
						'.self::get_ini('DBtables', 'NODES_MAP').' ml,
						'.self::get_ini('DBtables', 'CLASSES').' tm,
						'.self::get_ini('DBtables', 'CLASSES').' tl
					WHERE
						pm.nid1 = '.lunaDB::quote(PAGENID).' AND pm.nid2 = m.nid
						AND ml.nid1 = m.nid AND ml.nid2 = l.nid
						AND tm.lid = '.lunaDB::quote('mod').' AND m.tid = tm.id
						AND tl.lid = '.lunaDB::quote('level').' AND l.tid = tl.id
						AND m.is_active = 1
						AND l.is_active = 1
						AND m.lid != ""
					ORDER BY
						m.lid
				');
				$nodes = [];
				while ($row = $res->fetchRow()) { $nodes = array_merge($nodes, self::model()->load_node($row, 'mod', 'level')); }
				$res->free();
				if (self::$cache) { $cache_obj->save(serialize($nodes)); }
			}
			// self::model()->merge_index($nodes);
			foreach ($nodes as $mod_uri => $mod_node) {
				$file = '';
				$ok = false;
				$type = self::model()->get_type($mod_node);
				if ($type != self::model()->lunaNameSpace.'mod') { continue; }
				$nid = self::model()->get_nid($mod_node);
				$lid = self::model()->get_lid($mod_node);
				$level_nid = self::model()->get_nid(self::model()->get_level_node($mod_node));
				if (isset(self::$session->user->levels[$level_nid])) {
					$file = SITEPATH.'mods/'.$lid.'/'.$lid.'.php';
					if (file_exists($file)) {
						$ok = true;
					} else {
						$file = SITEPATH.'mods/'.$lid.'.php';
						if (file_exists($file)) {
							$ok = true;
						} else {
							$file = MODS_PATH.'luna.'.$lid.'/'.$lid.'.php';
							if (file_exists($file)) {
								$ok = true;
							} else {
								$file = MODS_PATH.'luna.'.$lid.'.php';
								if (file_exists($file)) {
									$ok = true;
								}
							}
						}
					}
				}
				if (!$ok) { throw new lunaException(_('Error: cannot find mod file ').$lid, PEAR_LOG_CRIT); }
				require_once $file;
				if (method_exists($lid, 'singleton')) {
					if (!self::$mods[$nid] = call_user_func($lid.'::singleton')) { throw new lunaException(_('Error: cannot create mod object: ').$lid, PEAR_LOG_CRIT); }
				} else {
					throw new lunaException(_('Error: cannot find singleton method in class ').$lid, PEAR_LOG_CRIT);
					return false;
				}
				if ((lunaTools::request('submit') || lunaTools::request('batch_submit'))) {
					// CSRF + POST-only guard: every state-changing action needs POST and a valid token.
					if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string) self::$session->user->csrf_token, (string)($_POST['csrf_token'] ?? ''))) {
						luna::$messages['warning'][] = _('Security check failed. Please reload the page and try again.');
						lunaLog::log('CSRF/POST check failed for mod '.$lid, PEAR_LOG_WARNING);
					} else {
						if (method_exists($lid, 'submit')) { self::$mods[$nid]->submit(); }
						if (isset($_POST['mode'])) {
							switch ($_POST['mode']) {
								case 'add':
									if (method_exists($lid, 'submit_add')) { self::$mods[$nid]->submit_add(); }
									break;
								case 'modify':
									if (lunaTools::request('submit') == _('Delete')) {
										if (method_exists($lid, 'submit_delete')) { self::$mods[$nid]->submit_delete(); }
									} else {
										if (method_exists($lid, 'submit_modify')) { self::$mods[$nid]->submit_modify(); }
									}
									break;
								case 'delete':
									if (method_exists($lid, 'submit_delete')) { self::$mods[$nid]->submit_delete(); }
									break;
							}
						}
						if (!defined('AJAX') || !AJAX) {
							lunaDB::optimise([
								self::get_ini('DBtables', 'NODES'),
								self::get_ini('DBtables', 'NODES_MAP'),
								self::get_ini('DBtables', 'ACTIONS')
							]);
						}
					}
				}
				if (method_exists($lid, 'load')) { self::$mods[$nid]->load(); }
				$mod_node = self::model()->set_property($mod_node, 'is_loaded', '1');
				self::model()->merge_index([$mod_uri => $mod_node]);
			}
			// load messages
			self::model()->merge_index(self::model()->load_messages(self::$messages));
			return true;
		} catch (lunaException $e) {
			lunaLog::log($e);
			die();
		}
	}
	// }}}
	// {{{ transform()
	/**
	 * Transform the page.
	 *
	 * @return	string
	 */
	public function transform(): string {
		try {
			lunaTools::add_vocabulary([
				'You are here: ',
				'Sitemap',
				'Jump to page',
				'Creation date',
				'Modification date',
				'Page loaded in',
				'Elements per page: ',
				'Previous page',
				'Next page',
				'First page',
				'Last page',
				'Page: ',
				'Site powered by',
				'Language',
				'langs',
				'Navigation',
				'Output formats',
				'You are logged in as',
				ANONYMOUS,
				'node'
			]);
			// Data outputs (xml/json/n3/jsonld) carry the content graph only — emit here,
			// before the HTML-only chrome (current user, language/format switchers, site
			// config + sort cookies, i18n vocabulary, request params) is merged into the model.
			if (self::$output_format != 'html') {
				lunaTools::emit(
					self::model()->serialize(self::$output_format),
					lunaModel::content_type(self::$output_format),
					lunaModel::data_response_csp()
				);
			}
			// Insert user
			if (!self::model()->merge_index(self::model()->load_user(self::$session->user, 1))) { throw new lunaException(_('Error: cannot load user.'), PEAR_LOG_CRIT); }
			// Insert langs
			$langs = [];
			foreach (self::get_ini('config', 'site_langs') as $lang) {
				$var_node = self::model()->load_var([
					'type' => 'lang',
					'lid' => $lang,
					'lang' => $lang,
					'value' => [
						'value' => _($lang),
						'link' => lunaTools::append_to_link('lang', $lang),
						'selected' => ($lang == self::$lang) ? 1 : 0
					]
				]);
				$langs = self::model()->merge_nodes($langs, $var_node);
			}
			self::model()->merge_index($langs);
			// Insert output formats
			$output_formats = [];
			foreach (self::$output_formats as $output_format) {
				$var_node = self::model()->load_var([
					'type' => 'output-format',
					'lid' => $output_format,
					'value' => [
						'value' => _($output_format),
						'link' => lunaTools::append_to_link('output', $output_format),
						'selected' => ($output_format == self::$output_format) ? 1 : 0
					]
				]);
				$output_formats = self::model()->merge_nodes($output_formats, $var_node);
			}
			self::model()->merge_index($output_formats);
			// Insert Data
			self::model()->merge_index(self::model()->load_data(self::$data));
			self::model()->merge_index(self::model()->load_vocabulary(self::$vocabulary));
			self::model()->merge_index(self::model()->load_request($_REQUEST, 'request'));
			// If ajax is on, no need to go further
			if (defined('AJAX') && AJAX) { die(); }
			$output = false;
			$xslok = false;
			$XSLpath = XSL_PATH.self::$output_format.'.xsl/';
			$XSLfile = SITEPATH.'xsl/'.self::$output_format.'.xsl/'.self::$data['lid'].'.'.self::$output_format.'.xsl';
			if (file_exists($XSLfile)) {
				$xslok = true;
			} else {
				$XSLfile = SITEPATH.'xsl/'.self::$output_format.'.xsl/'.'default'.'.'.self::$output_format.'.xsl';
				if (file_exists($XSLfile)) {
					$xslok = true;
				} else {
					$XSLfile = SITEPATH.'xsl/'.self::$data['lid'].'.xsl';
					if (file_exists($XSLfile)) {
						$xslok = true;
					} else {
						$XSLfile = SITEPATH.'xsl/'.'default'.'.xsl';
						if (file_exists($XSLfile)) {
							$xslok = true;
						} else {
							$XSLfile = XSL_PATH.'luna.'.self::$output_format.'.xsl/'.'luna.'.self::$data['lid'].'.'.self::$output_format.'.xsl';
							if (file_exists($XSLfile)) {
								$xslok = true;
							} else {
								$XSLfile = XSL_PATH.'luna.'.self::$data['lid'].'.xsl';
								if (file_exists($XSLfile)) {
									$xslok = true;
								} else {
									$XSLfile = XSL_PATH.'luna.'.self::$output_format.'.xsl/'.'luna.'.'default'.'.'.self::$output_format.'.xsl';
									if (file_exists($XSLfile)) {
										$xslok = true;
									}
								}
							}
						}
					}
				}
			}
			if (!$xslok) { throw new lunaException(_('Error: cannot load XSL.'), PEAR_LOG_CRIT); }
			if (!$output = self::model()->transform($XSLfile)) { throw new lunaException(_('Error: cannot transform XSL.'), PEAR_LOG_CRIT); }
			// $output = str_replace('{LOADINGTIME}', (round(self::set_microtime() - luna::$start, 4).'s. '), $output);
			// Embed schema.org JSON-LD structured data in the <head> (Linked Data, Phase 0 — see docs/linked-data.md).
			if (stripos($output, '</head>') !== false && ($ld = self::model()->to_jsonld())) {
				$output = preg_replace('#</head>#i', "<script type=\"application/ld+json\">\n".$ld."\n</script>\n</head>", $output, 1);
			}
			return $output;
		} catch (lunaException $e) {
			lunaLog::log($e);
			die();
		}
	}
	// }}}
	// {{{ load_ini()
	/**
	 * Parse luna ini file
	 *
	 * @return array|false
	 */
	private function load_ini(): array|false {
		// load ini file
		if (!file_exists(SITEPATH.'ini/luna.ini')) { return false; }
		define('INI_PATH', SITEPATH.'ini/');
		if (!$ini = parse_ini_file(INI_PATH.'luna.ini', true)) { return false; }
		if (empty($ini['Paths']) || empty($ini['DBtables']) || empty($ini['Constantes'])) { return false; }
		foreach ($ini['Paths'] as $k => $v) { define($k, LUNAPATH.$v); }
		foreach ($ini['Constantes'] as $k => $v) { define($k, $v); }
		if (!defined('ANONYMOUS')) { define('ANONYMOUS', 'guest'); }
		return $ini;
	}
	// }}}
	// {{{ set_microtime()
	/**
	 * @param int|false $microtime
	 * @return float
	 */
	public static function set_microtime(int|false $microtime = false): float {
		$microtime = intval($microtime);
		if (empty($microtime)) { $microtime = microtime(); }
		list($usec, $sec) = explode(' ', $microtime);
		return((float)$usec + (float)$sec);
	}
	// }}}
	// {{{ lid_is_protected()
	/**
	 * @param string|false $lid
	 * @return bool
	 */
	public static function lid_is_protected(string|false $lid = false): bool {
		if (empty($lid)) { return false; }
		if (in_array("$lid", self::$protected_lids)) { return true; }
		return false;
	}
	// }}}
	// {{{ get_ini()
	/**
	 * @param	string|false $domain
	 * @param	string|false $var
	 * @param	string|false $subvar
	 * @return	string|array|false
	 */
	public static function get_ini(string|false $domain = false, $var = false, string|false $subvar = false): string|array|false {
		if (empty($domain) || empty($var)) { return false; }
		if (is_array($var)) {
			if (!isset(luna::$ini["$domain"]["$var"][$subvar])) { return false; }
			return self::$ini["$domain"]["$var"][$subvar];
		} else {
			if (!isset(luna::$ini["$domain"]["$var"])) { return false; }
			return self::$ini["$domain"]["$var"];
		}
	}
	// }}}
}
// }}}
