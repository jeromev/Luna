<?php

/**
 * migrate-texts.php — bring luna_texts to the one-row-per-(node, language) invariant.
 *
 * PHP 8.1+ (tested on 8.3) · CLI only.
 * LICENSE: GPL v2 — see <http://www.gnu.org/copyleft/gpl.html>.
 *
 * Run:  docker-compose exec -T app php bin/migrate-texts.php [--apply]
 * Or:   make migrate-texts        (dry run)
 *       make migrate-texts-apply  (writes)
 *
 * WHY THIS EXISTS. The schema now declares `UNIQUE KEY nid_lang (nid, lang)` and `lang NOT NULL`.
 * A database created from luna.mysql.sql already satisfies it. A database created before that
 * change may not, and MySQL will refuse to add the key while a violation exists — so this walks the
 * three ways an old install can violate it and reports or repairs each:
 *
 *   1. rows whose lang is NULL or empty     -> set to the site's first configured language
 *   2. more than one row for a (nid, lang)  -> keep the highest id (the most recently inserted),
 *                                              delete the rest
 *   3. the unique key is absent             -> add it
 *
 * It is IDEMPOTENT and safe to re-run: every step is a no-op once satisfied, and a second run of
 * --apply reports "nothing to do". It is also honest about the destructive step: (2) deletes rows,
 * so a dry run is the default and it prints exactly which ids it would remove before it removes
 * anything.
 *
 * SCOPE, stated plainly: this is a one-off repair for one table, NOT a migration framework. The
 * project still has no general schema-migration mechanism (docs/internal state-of-the-project,
 * risk #7). Building one is a separate decision; pretending this is one would be worse than
 * admitting it is not.
 *
 * @author      Jérôme Vogel
 * @license     http://www.gnu.org/copyleft/gpl.html  GPL
 * @link        https://github.com/jeromev/LunarSystem
 * @package     lunarSystem
 */

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "migrate-texts.php is CLI-only.\n"); exit(2); }

$apply = in_array('--apply', $argv, true);

// Minimal non-web bootstrap, exactly as bin/resync-triplestore.php does it: luna::singleton() is
// the *web* constructor and would try to build a page and a session, which a maintenance task has
// no business doing.
define('LUNA_MAINTENANCE', true);
require_once __DIR__.'/../luna/luna.php';

luna::$lunaPath = realpath(__DIR__.'/../luna').'/';
if (!defined('LUNAPATH')) { define('LUNAPATH', luna::$lunaPath); }
luna::$site_path = LUNAPATH.'luna.domains/luna.default/';
if (!defined('SITEPATH')) { define('SITEPATH', luna::$site_path); }
if (!defined('INI_PATH')) { define('INI_PATH', SITEPATH.'ini/'); }
ini_set('include_path', LUNAPATH.'luna.lib'.PATH_SEPARATOR.ini_get('include_path'));

$ini = @parse_ini_file(INI_PATH.'luna.ini', true);
if (!$ini || empty($ini['DBtables'])) { fwrite(STDERR, 'Error: cannot load '.INI_PATH."luna.ini\n"); exit(1); }
foreach ($ini['Paths'] as $k => $v) { if (!defined($k)) { define($k, LUNAPATH.$v); } }
foreach ($ini['Constantes'] as $k => $v) { if (!defined($k)) { define($k, $v); } }
if (!defined('ANONYMOUS')) { define('ANONYMOUS', 'guest'); }
luna::$ini = $ini;

require_once LUNAPATH.'luna.classes/luna.log.class.php';
require_once LUNAPATH.'luna.classes/luna.tools.class.php';
require_once LUNAPATH.'luna.classes/luna.db.class.php';

if (!lunaDB::prepare() || !lunaDB::connect()) { fwrite(STDERR, "Error: cannot connect to the database.\n"); exit(1); }

$TEXTS = luna::get_ini('DBtables', 'TEXTS');
// get_ini() reads luna::$ini, which on this path holds only the ini FILE — the configured language
// list lives in the luna_config table, so read it the way the web path does and reduce it to the
// two-letter content codes this table stores.
luna::$cache = false;
$config = lunaTools::load_config();
$langs = (is_array($config) && isset($config['site_langs']) && is_array($config['site_langs'])) ? $config['site_langs'] : [];
$default = 'en';
foreach ($langs as $l) {
	$c = lunaTools::content_language(is_string($l) ? $l : '');
	if ($c !== '') { $default = $c; break; }
}

$changes = 0;
$note = function (string $s): void { fwrite(STDOUT, $s."\n"); };
$note($apply ? "migrate-texts: APPLY (writes enabled)" : "migrate-texts: dry run — pass --apply to write");
$note("default language for unlabelled rows: {$default}");
$note('');

// --- 1. rows with no language ------------------------------------------------------------------
$res = lunaDB::query('SELECT id, nid FROM '.$TEXTS." WHERE lang IS NULL OR lang = ''");
$unlabelled = [];
if ($res) { while ($r = $res->fetchRow()) { $unlabelled[] = $r; } $res->free(); }
if ($unlabelled === []) {
	$note('1. unlabelled rows: none');
} else {
	$note('1. unlabelled rows: '.count($unlabelled).' -> lang = "'.$default.'"');
	foreach ($unlabelled as $r) { $note('     id '.$r->id.' (node '.$r->nid.')'); }
	if ($apply) {
		lunaDB::query('UPDATE '.$TEXTS.' SET lang = '.lunaDB::quote($default)." WHERE lang IS NULL OR lang = ''");
		$changes += count($unlabelled);
	}
}

// --- 2. duplicate (nid, lang) ------------------------------------------------------------------
// Keep the highest id per pair: ids ascend with insertion, so the survivor is the newest write,
// which is the only defensible choice without a modification timestamp on the row.
$res = lunaDB::query('
	SELECT nid, lang, COUNT(*) AS n, MAX(id) AS keep_id
	FROM '.$TEXTS.'
	GROUP BY nid, lang
	HAVING n > 1
');
$dupes = [];
if ($res) { while ($r = $res->fetchRow()) { $dupes[] = $r; } $res->free(); }
if ($dupes === []) {
	$note('2. duplicate (node, language) pairs: none');
} else {
	$note('2. duplicate (node, language) pairs: '.count($dupes));
	foreach ($dupes as $d) {
		$victims = [];
		$vres = lunaDB::query('SELECT id FROM '.$TEXTS.'
			WHERE nid = '.lunaDB::quote($d->nid).'
			AND lang = '.lunaDB::quote($d->lang).'
			AND id <> '.lunaDB::quote($d->keep_id));
		if ($vres) { while ($v = $vres->fetchRow()) { $victims[] = intval($v->id); } $vres->free(); }
		$note('     node '.$d->nid.' lang "'.$d->lang.'": keep id '.$d->keep_id
			.', delete '.(count($victims) ? implode(', ', $victims) : '(none)'));
		if ($apply && $victims !== []) {
			lunaDB::query('DELETE FROM '.$TEXTS.' WHERE id IN ('.implode(',', $victims).')');
			$changes += count($victims);
		}
	}
}

// --- 3. the unique key -------------------------------------------------------------------------
$has_key = false;
$res = lunaDB::query('SHOW INDEX FROM '.$TEXTS." WHERE Key_name = 'nid_lang'");
if ($res) { $has_key = (bool) $res->fetchRow(); $res->free(); }
if ($has_key) {
	$note('3. UNIQUE KEY nid_lang: already present');
} else {
	$note('3. UNIQUE KEY nid_lang: absent -> will be added (with lang NOT NULL)');
	if ($apply) {
		lunaDB::query('ALTER TABLE '.$TEXTS." MODIFY `lang` char(2) NOT NULL DEFAULT ".lunaDB::quote($default));
		lunaDB::query('ALTER TABLE '.$TEXTS.' DROP INDEX `nid`');   // redundant: leftmost prefix of nid_lang
		lunaDB::query('ALTER TABLE '.$TEXTS.' ADD UNIQUE KEY `nid_lang` (`nid`,`lang`)');
		$changes++;
	}
}

$note('');
if (!$apply) {
	$note('dry run complete — nothing was written. Re-run with --apply to make the changes above.');
	exit(0);
}
$note($changes === 0 ? 'nothing to do; the invariant already holds.' : 'applied. '.$changes.' change(s).');
$note('Now re-project the graph so the triplestore carries every translation: make resync-triplestore');
exit(0);
