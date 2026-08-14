<?php

/**
 * lunar model class
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
class lunaModel {
	/**
	 * index
	 * @var array
	 */
	private $index = [];
	/**
	 * node_path
	 * @var string
	 */
	public $node_path = '';
	/**
	 * aliases
	 * @var array
	 */
	private $aliases = [];
	/**
	 * conf
	 * @var array
	 */
	private $conf = [];
	/**
	 * triples
	 * @var array
	 */
	private $triples = [];
	/**
	 * instance
	 * @var self|null
	 */
	private static $instance = null;
	/**
	 * lunaNameSpace
	 * @var		string
	 */
	/** The luna: vocabulary namespace — the single source of truth. The 16 XSLT
	 *  `xmlns:luna="…"` declarations must match this exactly, and the triplestore
	 *  must be re-projected (`make resync-triplestore`) whenever it changes. */
	public const LUNA_NS = 'https://jeromev.github.io/LunarSystem/ontology#';
	public const LUNA_RENDER_NS = 'https://jeromev.github.io/LunarSystem/render#'; // UI render-model (NOT content)
	public $lunaNameSpace = self::LUNA_NS;
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
		// Maintenance/CLI entrypoints (e.g. bin/resync-triplestore.php) need only the
		// RDF write-through (rdf_sync_node / rdf_resync_all), not the web read-model:
		// skip building the ACL-filtered page index, which requires a live session.
		if (defined('LUNA_MAINTENANCE') && LUNA_MAINTENANCE) { return; }
		ksort(luna::$session->user->levels);
		$cache_rdf_name = 'luna.'.implode('-', luna::$session->user->levels).'.'.luna::$lang;
		$this->conf = [
			'ns' => [
				'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
				'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
				'foaf' => 'http://xmlns.com/foaf/0.1/',
				'dc' => 'http://purl.org/dc/elements/1.1/',
				'dcterms' => 'http://purl.org/dc/terms/',
				'owl' => 'http://www.w3.org/2002/07/owl#',
				'schema' => 'https://schema.org/',
				'luna' => $this->lunaNameSpace,
				'ui' => self::LUNA_RENDER_NS, // UI render-model: vocabulary/data/pager/request/message/log/lang/...
			],
			'serializer_prettyprint_containers' => 1,
			'serializer_type_nodes' => 1,
		];
		$this->node_path = lunaTools::link('node', true);
		if (luna::$cache) { $cache_obj = new lunaCache(['cacheDir' => CACHE_PATH, 'lifetime' => luna::$cache_timeout]); }
		if (luna::$cache && ($cache_str = $cache_obj->get($cache_rdf_name))) {
			$array = unserialize($cache_str, ['allowed_classes' => false]);
			$this->index = $array['index'];
			$this->aliases = $array['aliases'];
			unset($array);
		} else {
			// load the page tree into the model — from the graph by default, falling
			// back to SQL if the SPARQL path is off or yields nothing (routing safety net).
			$nodes = false;
			if (lunaGraph::sparql_reads()) { $nodes = $this->load_nodes_sparql('page'); }
			if (empty($nodes)) { $nodes = $this->load_nodes('page', 'level'); }
			$this->merge_index($nodes);
			if (empty($this->index)) { throw new lunaException(_('Error: cannot build index.'), PEAR_LOG_CRIT); }
			if (luna::$cache) { $cache_obj->save(serialize(['index' => $this->index, 'aliases' => $this->aliases])); }
		}
	}
	// }}}
	// {{{ get_node()
	/**
	 * @param int|false $nid
	 * @param string|false $type
	 * @return mixed
	 */
	public function get_node(int|false $nid = false, $type = false, $ns = 'luna') {
		$nid = intval($nid);
		if ($nid < 1) { return false; }
		if (empty($ns) || !isset($this->conf['ns']["$ns"])) { $ns = 'luna'; }
		$ns = $this->conf['ns']["$ns"];
		if (!isset($this->index[$this->node_path.'/'.$nid])) { return false; }
		if (!empty($type)) {
			if ($this->index[$this->node_path.'/'.$nid][$this->conf['ns']['rdf'].'type'][0]['value'] != $ns.$type) { return false; }
		}
		return $this->index[$this->node_path.'/'.$nid];
	}
	// }}}
	// {{{ merge_index()
	/**
	 * Merge a set of nodes into the working index.
	 *
	 * Takes `false` as well as an array, and that is the contract rather than an accident:
	 * every loader that feeds this returns `array|false`, returning false when it finds
	 * nothing, and the `!empty()` below is what absorbs it. The docblock used to say `array`,
	 * which is how a native `array` type came to be added here four times and take the login
	 * page down each time — the login page is the one with no text blocks.
	 *
	 * @param array|false $nodes
	 * @return true
	 */
	public function merge_index($nodes) {
		if (!empty($nodes)) { $this->index = $this->merge_nodes($this->index, $nodes); }
		return true;
	}
	// }}}
	// {{{ purge_index()
	/**
	 * @return mixed
	 */
	public function purge_index() {
		$this->index = [];
		$this->aliases = [];
		$this->merge_index($this->load_nodes('page', 'level'));
		if (empty($this->index)) { throw new lunaException(_('Error: cannot build index.'), PEAR_LOG_CRIT); }
		return true;
	}
	// }}}
	// {{{ merge_nodes()
	/**
	 * @param array $nodes1
	 * @param array $nodes2
	 * @return array|false
	 */
	public function merge_nodes($nodes1, $nodes2): array|false {
		if (!is_array($nodes1) || !is_array($nodes2)) { return false; }
		foreach ($nodes2 as $node2_uri => $node2_data) {
			if (!isset($nodes1[$node2_uri])) {
				$nodes1[$node2_uri] = $node2_data;
			} else {
				foreach ($node2_data as $data2_uri => $data2_array) {
					if (!isset($nodes1[$node2_uri][$data2_uri])) {
						$nodes1[$node2_uri][$data2_uri] = $data2_array;
					} else {
						foreach ($data2_array as $k2 => $data2) {
							$found = false;
							foreach ($nodes1[$node2_uri][$data2_uri] as $k1 => $data1) {
								if ($data1['value'] == $data2['value']) {
									$found = true;
								}
							}
							if (!$found) { $nodes1[$node2_uri][$data2_uri][] = $data2; }
						}
					}
				}
			}
		}
		return $nodes1;
	}
	// }}}
	// {{{ get_level_node()
	/**
	 * @param array|false $node
	 * @return mixed
	 */
	public function get_level_node($node = false) {
		if (empty($node) || !is_array($node)) { return false; }
		if (!isset($node[$this->conf['ns']['luna'].'level'][0]['value'])) { return false; }
		if (!isset($this->index[$node[$this->conf['ns']['luna'].'level'][0]['value']])) { return false; }
		return $this->index[$node[$this->conf['ns']['luna'].'level'][0]['value']];
	}
	// }}}
	// {{{ get_page_node_from_alias()
	/**
	 * @param string $path (optional)
	 * @return mixed
	 */
	public function get_page_node_from_alias($path = '') {
		$pagenode = false;
		$subdir = false;
		if (empty($path)) { return $this->get_node_from_alias("root", "page"); }
		$node = $this->get_node_from_alias($path);
		if (!$node) {
			if (strpos($path, '/') === false) {
				return false;
			} else {
				$patharray = explode('/', $path);
				foreach ($patharray as $k => $v) { if (empty($v)) { unset($patharray[$k]); } }
				$subdir = array_pop($patharray);
				$path = implode('/', $patharray);
				// TO DO: if ($path == 'node') { $node_nid = intval($subdir); $path = ''; $node = $this->get_node_from_alias("root", "page"); }
				$node = $this->get_node_from_alias($path, 'page');
				if (!$node) {
					return false;
				} else {
					luna::$data['subdir'] = $subdir;
					return $node;
				}
			}
		} else {
			return $node;
		}
		return false;
	}
	// }}}
	// {{{ get_node_from_alias()
	/**
	 * @param string $alias
	 * @param string|false $type
	 * @param string $ns
	 * @return mixed
	 */
	public function get_node_from_alias($alias = '', $type = false, $ns = 'luna') {
		if (empty($alias)) { $alias = "root"; }
		if (!isset($this->aliases["$alias"])) { return false; }
		if (empty($ns) || !isset($this->conf['ns']["$ns"])) { $ns = 'luna'; }
		$ns = $this->conf['ns']["$ns"];
		if (!isset($this->index[$this->node_path.'/'.$this->aliases["$alias"][$this->conf['ns']['luna'].'nid'][0]['value']])) { return false; }
		if (!empty($type)) {
			if ($this->index[$this->node_path.'/'.$this->aliases["$alias"][$this->conf['ns']['luna'].'nid'][0]['value']][$this->conf['ns']['rdf'].'type'][0]['value'] != $ns.$type) { return false; }
		}
		return $this->index[$this->node_path.'/'.$this->aliases["$alias"][$this->conf['ns']['luna'].'nid'][0]['value']];
	}
	// }}}
	// {{{ get_nid()
	/**
	 * @param array|false $node
	 * @param string|false $type
	 * @param string $ns
	 * @return mixed
	 */
	public function get_nid($node = false, $type = false, $ns = 'luna') {
		if (empty($node) || !is_array($node)) { return false; }
		if (empty($ns) || !isset($this->conf['ns']["$ns"])) { $ns = 'luna'; }
		$ns = $this->conf['ns']["$ns"];
		if (!isset($node[$this->conf['ns']['luna'].'nid'][0]['value'])) { return false; }
		if (!empty($type)) {
			if ($node[$this->conf['ns']['rdf'].'type'][0]['value'] != $ns.$type) { return false; }
		}
		return $node[$this->conf['ns']['luna'].'nid'][0]['value'];
	}
	// }}}
	// {{{ get_type()
	/**
	 * @param array|false $node
	 * @return mixed
	 */
	public function get_type($node = false) {
		if (empty($node) || !is_array($node)) { return false; }
		if (!isset($node[$this->conf['ns']['rdf'].'type'][0]['value'])) { return false; }
		return $node[$this->conf['ns']['rdf'].'type'][0]['value'];
	}
	// }}}
	// {{{ get_lid()
	/**
	 * @param array|false $node
	 * @return mixed
	 */
	public function get_lid($node = false) {
		if (empty($node) || !is_array($node)) { return false; }
		if (!isset($node[$this->conf['ns']['luna'].'lid'][0]['value'])) { return false; }
		return $node[$this->conf['ns']['luna'].'lid'][0]['value'];
	}
	// }}}
	// {{{ get_node_from_slug()
	/**
	 * Resolve a slug (luna:lid) to its node in the *loaded* model. The model index is
	 * already scoped to the levels the current user holds, so this inherits the page
	 * tree's access control: a slug the user can't see resolves to false (a 404),
	 * exactly as the HTML route 404s. Backs the Linked Data /id/{slug} and /data/{slug}
	 * URIs, whose local name is the slug, not the breadcrumb alias.
	 *
	 * @param string $slug
	 * @param string $type a luna: type to require (e.g. 'page'); '' to accept any
	 * @return mixed the node, or false
	 */
	public function get_node_from_slug(string $slug = '', $type = 'page') {
		if ($slug === '' || $slug === false) { return false; }
		$lid   = $this->conf['ns']['luna'].'lid';
		$rtype = $this->conf['ns']['rdf'].'type';
		$want  = $this->conf['ns']['luna'].$type;
		foreach ($this->index as $node) {
			if (!isset($node[$lid][0]['value']) || $node[$lid][0]['value'] !== "$slug") { continue; }
			if (!empty($type) && (!isset($node[$rtype][0]['value']) || $node[$rtype][0]['value'] !== $want)) { continue; }
			return $node;
		}
		return false;
	}
	// }}}
	// {{{ get_alias()
	/**
	 * The node's canonical clean-URL alias (the breadcrumb path calculate_aliases()
	 * derived) — '' for the root page. Used to point the /id/{slug} 303 at the HTML
	 * document and to re-enter the normal pipeline for /data/{slug}.
	 *
	 * @param array|false $node
	 * @return mixed the alias string (possibly ''), or false if the node carries none
	 */
	public function get_alias($node = false) {
		if (empty($node) || !is_array($node)) { return false; }
		if (!isset($node[$this->conf['ns']['luna'].'alias'][0]['value'])) { return false; }
		return $node[$this->conf['ns']['luna'].'alias'][0]['value'];
	}
	// }}}
	// {{{ build_sitemap()
	/**
	 * Build an XML sitemap of the page tree. Lists every active page the
	 * *current* requester can see — which, for the anonymous crawler this is meant for,
	 * is exactly the public set — as canonical HTML URLs (those pages carry the JSON-LD
	 * and the Link headers that lead on to /id and /data). Part of the publishing
	 * surface; needs no triplestore. See docs/going-public.md.
	 *
	 * @return string the sitemap XML; the caller emits it
	 */
	public function build_sitemap(): string {
		$luna = $this->conf['ns']['luna'];
		$rdf  = $this->conf['ns']['rdf'];
		$pagetype = $luna.'page';
		// utility pages that aren't indexable content
		$skip = ['login' => 1, 'logout' => 1];
		$urls = [];
		foreach ($this->index as $node) {
			if (!isset($node[$rdf.'type'][0]['value']) || $node[$rdf.'type'][0]['value'] !== $pagetype) { continue; }
			if (isset($node[$luna.'isActive'][0]['value']) && (string) $node[$luna.'isActive'][0]['value'] === '0') { continue; }
			if (!isset($node[$luna.'alias'][0]['value'])) { continue; }
			if (isset($node[$luna.'lid'][0]['value'], $skip[$node[$luna.'lid'][0]['value']])) { continue; }
			$alias = $node[$luna.'alias'][0]['value'];
			$urls[$alias] = lunaTools::link($alias, true);
		}
		ksort($urls);
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
		foreach ($urls as $u) { $xml .= '  <url><loc>'.htmlspecialchars($u, ENT_QUOTES | ENT_XML1, 'UTF-8').'</loc></url>'."\n"; }
		$xml .= '</urlset>'."\n";
		return $xml;
	}
	// }}}
	// {{{ build_robots()
	/**
	 * Build a robots.txt that allows crawling and points at the sitemap with an absolute
	 * URL (correct on any host).
	 *
	 * @return string the robots.txt body; the caller emits it
	 */
	public function build_robots(): string {
		$base = rtrim(luna::$site_uri, '/');
		return "User-agent: *\nAllow: /\nSitemap: ".$base."/sitemap.xml\n";
	}
	// }}}
	// {{{ set_property()
	/**
	 * @param array|false $node
	 * @param string|false $prop_lid
	 * @param string|false $prop_value
	 * @param string $ns
	 * @return mixed
	 */
	public function set_property($node = false, $prop_lid = false, $prop_value = false, $ns = 'luna') {
		if (empty($node) || !is_array($node) || empty($prop_lid) || empty($prop_value)) { return false; }
		if (empty($ns) || !isset($this->conf['ns']["$ns"])) { $ns = 'luna'; }
		$ns = $this->conf['ns']["$ns"];
		if (!$nid = $this->get_nid($node)) { return false; }
		$node[$ns.$prop_lid][0]['value'] = "$prop_value";
		return $node;
	}
	// }}}
	// {{{ get_nid_from_lid()
	/**
	 * @param string|false $lid
	 * @return mixed
	 */
	public function get_nid_from_lid($lid = false) {
		if (empty($lid)) { return false; }
		$nid = false;
		$res = lunaDB::query('
			SELECT
				nid
			FROM
				'.luna::get_ini('DBtables', 'NODES').'
			WHERE
				lid = '.lunaDB::quote("$lid").'
			LIMIT 1
		');
		while ($row = $res->fetchRow()) { $nid = $row->nid; }
		$res->free();
		return $nid;
	}
	// }}}
	// {{{ get_parent_node()
	/**
	 * @param array|false $node
	 * @return array|false
	 */
	public function get_parent_node($node = false): array|false {
		if (empty($node) || !is_array($node)) { return false; }
		if (!isset($node[$this->conf['ns']['schema'].'isPartOf'][0]['value'])) { return false; }
		if (!isset($this->index[$node[$this->conf['ns']['schema'].'isPartOf'][0]['value']])) { return false; }
		return $this->index[$node[$this->conf['ns']['schema'].'isPartOf'][0]['value']];
	}
	// }}}
	// {{{ get_children_nodes()
	/**
	 * @param array|false $parent_node
	 * @return array|false
	 */
	public function get_children_nodes($parent_node = false): array|false {
		if (empty($parent_node) || !is_array($parent_node)) { return false; }
		$children = [];
		$parent_nid = $this->get_nid($parent_node);
		foreach ($this->index as $node) {
			$node_parent_node = $this->get_parent_node($node);
			$node_parent_nid = $this->get_nid($node_parent_node);
			if ($node_parent_nid == $parent_nid) {
				$children[] = $node;
				$subchildren = $this->get_children_nodes($node);
				if ($subchildren) {
					$children[] = $subchildren;
				}
			}
		}
		return $children;
	}
	// }}}
	// {{{ serialize()
	/**
	 * Serialise the published graph into one of the RDF flavours and RETURN it.
	 *
	 * This used to be dump(), and it ended the request: every branch finished with header()
	 * and die(). That is why it needed a $return flag to be usable as a function at all, and
	 * why to_jsonld() needed one too. The caller now decides what to do with the document —
	 * emit it with lunaTools::emit(), embed it in a page, or hand it to a test.
	 *
	 * @param string $flavor xml | json | n3 | turtle | jsonld
	 * @param array|false $node serialise this sub-graph instead of the whole published one
	 * @return string
	 */
	public function serialize(string $flavor = 'xml', $node = false): string {
		require_once LUNAPATH.'luna.lib/arc/ARC2.php';
		$index = (empty($node) || !is_array($node)) ? $this->build_schema_index() : $node;
		switch ($flavor) {
			case 'json':
				return ARC2::getRDFJSONSerializer($this->conf)->getSerializedIndex($index);
			case 'n3':
				return ARC2::getNtriplesSerializer($this->conf)->getSerializedIndex($index);
			case 'jsonld':
				return $this->to_jsonld();
			case 'turtle':
				return ARC2::getTurtleSerializer($this->conf)->getSerializedIndex($index);
			case 'xml':
			default:
				return ARC2::getRDFXMLSerializer($this->conf)->getSerializedIndex($index);
		}
	}
	// }}}
	// {{{ content_type()
	/**
	 * The Content-Type each flavour is served as. Turtle is what a Linked Data client expects
	 * from /data/{slug}; RDF/XML goes out as application/xml (not application/rdf+xml) so a
	 * browser renders it inline instead of offering it as a .rdf download — the body is still
	 * valid RDF/XML for anything that parses it.
	 *
	 * @param string $flavor
	 * @return string
	 */
	public static function content_type(string $flavor = 'xml'): string {
		static $types = [
			'json'   => 'application/rdf+json',
			'n3'     => 'application/rdf+n3',
			'jsonld' => 'application/ld+json',
			'turtle' => 'text/turtle; charset=utf-8',
			'xml'    => 'application/xml; charset=utf-8',
		];
		return isset($types[$flavor]) ? $types[$flavor] : $types['xml'];
	}
	// }}}
	// {{{ data_response_csp()
	/**
	 * The relaxed CSP a data response needs. These non-HTML documents are shown by the
	 * browser's built-in data viewer, which injects an inline stylesheet; the strict global
	 * policy (style-src 'self') blocks it and the document renders as unstyled run-on text.
	 * HTML pages keep the strict policy.
	 *
	 * @return array one header line, for lunaTools::emit()
	 */
	public static function data_response_csp(): array {
		return ["Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:"];
	}
	// }}}
	// {{{ to_jsonld()
	/**
	 * Project the current page (and its text blocks) into compact schema.org
	 * JSON-LD, built from the same in-memory model that drives the HTML view.
	 * Phase-0 prototype of the Linked Data direction — see docs/linked-data.md.
	 * In Phase A this same shape comes from a SPARQL CONSTRUCT instead.
	 *
	 * @return string the JSON-LD document; the caller emits or embeds it
	 */
	public function to_jsonld(): string {
		$base    = rtrim(luna::$site_uri, '/');
		$rdf     = $this->conf['ns']['rdf'];
		$rdfs    = $this->conf['ns']['rdfs'];
		$luna    = $this->conf['ns']['luna'];
		$owl     = $this->conf['ns']['owl'];
		$pagenid = defined('PAGENID') ? PAGENID : false;
		$pageuri = $this->node_path.'/'.$pagenid;
		// first value of a predicate on an ARC2-style node, or null
		$first = function ($node, $pred) { return isset($node[$pred][0]['value']) ? $node[$pred][0]['value'] : null; };

		$doc = ['@context' => 'https://schema.org/'];
		if ($pagenid !== false && isset($this->index[$pageuri])) {
			$page = $this->index[$pageuri];
			$slug = $first($page, $luna.'lid');
			$name = $first($page, $rdfs.'label');
			$doc['@type'] = 'WebPage';
			$doc['@id']   = $base.'/id/'.rawurlencode($slug);
			if ($name !== null) { $doc['name'] = $name; }
			$doc['url'] = $base.'/'.ltrim(luna::$path, '/');
			if (defined('LANG')) { $doc['inLanguage'] = LANG; }
			// parent -> schema:isPartOf (skip the root self-link)
			$parent = $first($page, $this->conf['ns']['schema'].'isPartOf');
			if ($parent && $parent != $pageuri && isset($this->index[$parent])) {
				$pslug = $first($this->index[$parent], $luna.'lid');
				if ($pslug !== null) { $doc['isPartOf'] = ['@id' => $base.'/id/'.rawurlencode($pslug)]; }
			}
			// text blocks attached to this page -> schema:hasPart Articles
			$parts = [];
			foreach ($this->index as $node) {
				if ($first($node, $rdf.'type') !== $luna.'text' || !isset($node[$luna.'page'])) { continue; }
				$belongs = false;
				foreach ($node[$luna.'page'] as $p) { if ($p['value'] == $pageuri) { $belongs = true; break; } }
				if (!$belongs) { continue; }
				$part = ['@type' => 'Article'];
				$headline = $first($node, $rdfs.'label');
				if ($headline !== null) { $part['headline'] = $headline; $part['name'] = $headline; }
				if (isset($node[$luna.'content'][0]['value'])) {
					$part['articleBody'] = lunaTools::markdown_to_text($node[$luna.'content'][0]['value']);
					if (isset($node[$luna.'content'][0]['lang'])) { $part['inLanguage'] = $node[$luna.'content'][0]['lang']; }
				}
				$parts[] = $part;
			}
			if (count($parts)) { $doc['hasPart'] = $parts; }
			// Outbound links to the wider web of data (sameAs / about / seeAlso / …):
			// schema.org predicates compact to their bare term under the @context; any
			// other vocabulary keeps its full IRI as the key (still valid JSON-LD).
			$out = $this->outbound_index();
			if (isset($doc['@id'], $out[$doc['@id']])) {
				$schemans = 'https://schema.org/';
				$add = [];
				foreach ($out[$doc['@id']] as $pred => $vals) {
					$key = (strpos($pred, $schemans) === 0) ? substr($pred, strlen($schemans)) : $pred;
					foreach ($vals as $v) {
						if (!isset($v['value'])) { continue; }
						$add[$key][] = (isset($v['type']) && $v['type'] === 'uri') ? ['@id' => $v['value']] : $v['value'];
					}
				}
				foreach ($add as $key => $list) { $doc[$key] = (count($list) === 1) ? $list[0] : $list; }
			}
		} else {
			// no content page (e.g. an admin screen) -> a minimal WebSite node
			$doc['@type'] = 'WebSite';
			$doc['@id']   = $base.'/id/site';
			$doc['url']   = $base.'/';
			if (isset(luna::$data['sitename'])) { $doc['name'] = luna::$data['sitename']; }
		}
		// JSON_HEX_TAG/AMP keep the block safe inside <script> in the HTML <head>.
		$flags = 0;
		foreach (['JSON_PRETTY_PRINT', 'JSON_UNESCAPED_UNICODE', 'JSON_UNESCAPED_SLASHES', 'JSON_HEX_TAG', 'JSON_HEX_AMP'] as $f) { if (defined($f)) { $flags |= constant($f); } }
		return (string) json_encode($doc, $flags);
	}
	// }}}
	// {{{ build_schema_index()
	/**
	 * Project the current page (and its text blocks) into a clean, standards-based ARC2
	 * index — the same schema.org/FOAF shape as the triplestore: slug IRIs (/id/{slug}),
	 * schema:WebPage / schema:Article, schema:isPartOf / hasPart, and the three luna: terms
	 * (isActive, level, content). This is what ?output=xml/n3/json serialise. Since the Phase
	 * 1-4 retirement of the legacy model the XSLT-facing graph is the SAME schema.org/slug
	 * shape — project_to_schema() re-keys /node/{nid} subjects to /id/{slug} and maps the
	 * content vocabulary to schema.org at the transform() boundary — so /node/{nid},
	 * owl:isChildOf and luna:is_active are gone everywhere, not just from the published RDF.
	 * to_jsonld() is the JSON-LD form of the same projection.
	 *
	 * @return array an ARC2 index (uri => predicate => [ {value,type,datatype?,lang?} ])
	 */
	private function build_schema_index(): array {
		$base   = rtrim(luna::$site_uri, '/');
		$rdf    = $this->conf['ns']['rdf'];
		$rdfs   = $this->conf['ns']['rdfs'];
		$luna   = $this->conf['ns']['luna'];
		$owl    = $this->conf['ns']['owl'];
		$schema = 'https://schema.org/';
		$xint   = 'http://www.w3.org/2001/XMLSchema#integer';
		$first  = function ($node, $pred) { return isset($node[$pred][0]['value']) ? $node[$pred][0]['value'] : null; };
		$index  = [];
		$pagenid = defined('PAGENID') ? PAGENID : false;
		$pinternal = $this->node_path.'/'.$pagenid;
		// no content page (e.g. an admin screen): a minimal schema:WebSite node
		if ($pagenid === false || !isset($this->index[$pinternal])) {
			$site = $base.'/id/site';
			$index[$site][$rdf.'type'][] = ['value' => $schema.'WebSite', 'type' => 'uri'];
			if (isset(luna::$data['sitename'])) { $index[$site][$schema.'name'][] = ['value' => (string) luna::$data['sitename'], 'type' => 'literal']; }
			return $index;
		}
		$page = $this->index[$pinternal];
		$slug = $first($page, $luna.'lid');
		if ($slug === null) { return $index; }
		$puri = $base.'/id/'.rawurlencode($slug);
		$index[$puri][$rdf.'type'][] = ['value' => $schema.'WebPage', 'type' => 'uri'];
		if (($name = $first($page, $rdfs.'label')) !== null) { $index[$puri][$schema.'name'][] = ['value' => (string)$name, 'type' => 'literal']; }
		if (($nid  = $first($page, $luna.'nid'))    !== null) { $index[$puri][$schema.'identifier'][] = ['value' => (string)$nid, 'type' => 'literal', 'datatype' => $xint]; }
		if (($act  = $first($page, $luna.'isActive')) !== null) { $index[$puri][$luna.'isActive'][] = ['value' => (string)$act, 'type' => 'literal', 'datatype' => $xint]; }
		if (($lvl = $first($page, $luna.'level')) !== null && isset($this->index[$lvl]) && ($lslug = $first($this->index[$lvl], $luna.'lid')) !== null) {
			$index[$puri][$luna.'level'][] = ['value' => $base.'/id/'.rawurlencode($lslug), 'type' => 'uri'];
		}
		$parent = $first($page, $this->conf['ns']['schema'].'isPartOf');
		if ($parent && $parent != $pinternal && isset($this->index[$parent]) && ($pslug = $first($this->index[$parent], $luna.'lid')) !== null) {
			$index[$puri][$schema.'isPartOf'][] = ['value' => $base.'/id/'.rawurlencode($pslug), 'type' => 'uri'];
		}
		foreach ($this->index as $node) {
			if ($first($node, $rdf.'type') !== $luna.'text' || !isset($node[$luna.'page'])) { continue; }
			$belongs = false;
			foreach ($node[$luna.'page'] as $pp) { if (isset($pp['value']) && $pp['value'] == $pinternal) { $belongs = true; break; } }
			if (!$belongs) { continue; }
			$tslug = $first($node, $luna.'lid');
			if ($tslug === null) { continue; }
			$turi = $base.'/id/'.rawurlencode($tslug);
			$index[$turi][$rdf.'type'][] = ['value' => $schema.'Article', 'type' => 'uri'];
			if (($tnid = $first($node, $luna.'nid')) !== null) { $index[$turi][$schema.'identifier'][] = ['value' => (string)$tnid, 'type' => 'literal', 'datatype' => $xint]; }
			if (($head = $first($node, $rdfs.'label')) !== null) { $index[$turi][$schema.'headline'][] = ['value' => (string)$head, 'type' => 'literal']; $index[$turi][$schema.'name'][] = ['value' => (string)$head, 'type' => 'literal']; }
			if (isset($node[$luna.'content'][0]['value'])) {
				$body = $node[$luna.'content'][0]['value'];
				$lang = isset($node[$luna.'content'][0]['lang']) ? $node[$luna.'content'][0]['lang'] : '';
				$ab = ['value' => lunaTools::markdown_to_text($body), 'type' => 'literal']; if ($lang !== '') { $ab['lang'] = $lang; }
				$index[$turi][$schema.'articleBody'][] = $ab;
				$cn = ['value' => $body, 'type' => 'literal']; if ($lang !== '') { $cn['lang'] = $lang; }
				$index[$turi][$luna.'content'][] = $cn;
				if ($lang !== '') { $index[$turi][$schema.'inLanguage'][] = ['value' => $lang, 'type' => 'literal']; }
			}
			$index[$turi][$schema.'isPartOf'][] = ['value' => $puri, 'type' => 'uri'];
			$index[$puri][$schema.'hasPart'][] = ['value' => $turi, 'type' => 'uri'];
		}
		// Merge the operator-curated outbound links for every resource this document
		// describes (the page and its articles), so ?output=* and /data/{slug} reach
		// beyond the site instead of describing an island.
		$out = $this->outbound_index();
		if (!empty($out)) {
			foreach ($index as $uri => $node) {
				if (!isset($out[$uri])) { continue; }
				foreach ($out[$uri] as $pred => $vals) {
					foreach ($vals as $v) { $index[$uri][$pred][] = $v; }
				}
			}
		}
		return $index;
	}
	// }}}
	// {{{ load_texts_sparql()
	/**
	 * SPARQL-backed replacement for load_texts(): fetches a page's text blocks
	 * from the schema.org graph (via Ontop) and rebuilds them through the same
	 * load_text() index builder, so the model — and therefore the HTML/JSON-LD
	 * views — are populated identically whether sourced from SQL or SPARQL.
	 *
	 * @param int $page_nid
	 * @return array nodes
	 */
	public function load_texts_sparql(int $page_nid): array {
		$base = rtrim(luna::$site_uri, '/');
		$pageuri = $base.'/id/'.rawurlencode(defined('PAGELID') ? PAGELID : '');
		$q = 'PREFIX schema: <https://schema.org/> '
		   .'PREFIX luna: <'.self::LUNA_NS.'> SELECT ?text ?title ?body ?content ?lang ?tident WHERE { '
		   .'<'.$pageuri.'> schema:hasPart ?text . '
		   .'?text a schema:Article ; schema:identifier ?tident ; '
		   .'schema:headline ?title ; schema:articleBody ?body . '
		   .'OPTIONAL { ?text schema:inLanguage ?lang } OPTIONAL { ?text luna:content ?content } }';
		$rows = lunaGraph::sparql_select($q);
		$items = [];
		foreach ($rows as $r) {
			$texturi = isset($r['text']['value']) ? $r['text']['value'] : '';
			$lid = ($p = strrpos($texturi, '/id/')) !== false ? substr($texturi, $p + 4) : $texturi;
			$items[] = [
				'nid'          => isset($r['tident']['value']) ? $r['tident']['value'] : '',
				'lid'          => $lid,
				'title'        => isset($r['title']['value']) ? $r['title']['value'] : '',
				'lang'         => isset($r['lang']['value']) ? $r['lang']['value'] : luna::$lang,
				'content'      => isset($r['content']['value']) ? $r['content']['value'] : (isset($r['body']['value']) ? $r['body']['value'] : ''),
				'is_active'    => 1,
				'save_time'    => 0,
				'pages'        => [$page_nid]
			];
		}
		if (empty($items)) { return []; }
		return $this->load_text($items);
	}
	// }}}
	// {{{ load_nodes_sparql()
	/**
	 * SPARQL-backed replacement for load_nodes('page','level'): loads the page
	 * tree (scoped to the levels the current user holds) from the schema.org
	 * graph and rebuilds it through the same load_node() + calculate_aliases()
	 * the SQL path uses — so routing and access control are driven by SPARQL.
	 *
	 * @param string $type1  unused — accepted only for signature parity with load_nodes()
	 * @return array nodes
	 */
	public function load_nodes_sparql(string $type1 = 'page'): array|false {
		$levels = (luna::$session && isset(luna::$session->user->levels) && is_array(luna::$session->user->levels)) ? luna::$session->user->levels : [];
		if (empty($levels)) { return []; }
		$vals = [];
		foreach ($levels as $l) { $vals[] = '"'.intval($l).'"'; }
		$q = 'PREFIX schema: <https://schema.org/> '
		   .'PREFIX luna: <'.self::LUNA_NS.'> '
		   .'SELECT DISTINCT ?pnid ?lid ?active ?lnid ?llid ?lactive ?parentNid WHERE { '
		   .'?page a schema:WebPage ; schema:identifier ?pnid ; schema:name ?lid ; '
		   .'luna:isActive ?active ; luna:level ?level . '
		   .'?level schema:identifier ?lnid ; schema:name ?llid ; luna:isActive ?lactive . '
		   .'OPTIONAL { ?page schema:isPartOf ?parent . ?parent schema:identifier ?parentNid } '
		   .'FILTER ( STR(?lnid) IN ('.implode(', ', $vals).') ) }';
		$rows = lunaGraph::sparql_select($q);
		$nodes = [];
		foreach ($rows as $r) {
			$row = [
				'nid'        => isset($r['pnid']['value']) ? $r['pnid']['value'] : '',
				'type1'      => 'page',
				'lid'        => isset($r['lid']['value']) ? $r['lid']['value'] : '',
				'is_active'  => isset($r['active']['value']) ? $r['active']['value'] : '1',
				'parent_nid' => isset($r['parentNid']['value']) ? $r['parentNid']['value'] : 0,
				'nid2'       => isset($r['lnid']['value']) ? $r['lnid']['value'] : null,
				'lid2'       => isset($r['llid']['value']) ? $r['llid']['value'] : null,
				'is_active2' => isset($r['lactive']['value']) ? $r['lactive']['value'] : '1'
			];
			$nodes = $this->merge_nodes($nodes, $this->load_node($row, 'page', 'level'));
		}
		if (!empty($nodes)) {
			$this->aliases = [];
			if (!$nodes = $this->calculate_aliases($nodes)) { return []; }
		}
		return $nodes;
	}
	// }}}
	// {{{ outbound_index()
	/**
	 * Operator-curated *outbound* links — the statements that connect this site's
	 * resources to the wider web of data (owl:sameAs / schema:sameAs to an external
	 * entity, rdfs:seeAlso / schema:about to a related resource). Without them the
	 * graph is an island: well-formed RDF that links to nothing. They live in
	 * semantic/links.ttl as Turtle with *relative* /id/ subjects (e.g. <root>), so the
	 * file is deployment-independent; here they resolve against the live {site}/id/
	 * base. Parsed once per request. A missing or unparseable file is an empty no-op.
	 *
	 * @return array an ARC2 index keyed by absolute /id/{slug} IRIs
	 */
	public function outbound_index(): array {
		static $cache = null;
		if ($cache !== null) { return $cache; }
		$cache = [];
		if (!defined('LUNAPATH')) { return $cache; }
		$file = dirname(rtrim(LUNAPATH, '/')).'/semantic/links.ttl';
		if (!is_readable($file)) { return $cache; }
		$ttl = @file_get_contents($file);
		if ($ttl === false || trim($ttl) === '') { return $cache; }
		require_once LUNAPATH.'luna.lib/arc/ARC2.php';
		$conf = (isset($this->conf) && is_array($this->conf)) ? $this->conf : [];
		// resolve the file's relative <slug> subjects against {site}/id/
		$base = rtrim(luna::$site_uri, '/').'/id/';
		$parser = ARC2::getTurtleParser($conf);
		$parser->parse($base, $ttl);
		$index = $parser->getSimpleIndex(0);
		$cache = is_array($index) ? $index : [];
		return $cache;
	}
	// }}}
	// {{{ load_messages()
	/**
	 * @param array|false $messages
	 * @return array|false
	 */
	public function load_messages($messages = false): array|false {
		if (!is_array($messages)) { return false; }
		$nodes = [];
		foreach ($messages as $code => $code_messages) {
			foreach ($code_messages as $k => $message) {
				$var_node = $this->load_var([
					'type' => 'message',
					'lid' => $k.md5($message),
					'value' => [
						'value' => "$message",
						'code' => "$code"
					]
				]);
				$nodes = $this->merge_nodes($nodes, $var_node);
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_pager()
	/**
	 * @param int $total_items
	 * @param int $start_item
	 * @param int $per_page
	 * @param int|string $name
	 * @return array|false
	 */
	public function load_pager($total_items = 0, int $start_item = 0, int $per_page = 0, $name = 0): array|false {
		if (empty($total_items)) { return false; }
		if (empty($name)) { $name = 'default'; }
		$total_items = intval($total_items);
		$start_item = intval($start_item);
		$per_page = intval($per_page);
		$per_page = $per_page > 0 ? $per_page : PERPAGE;
		$total_pages = ceil($total_items / $per_page);
		$on_page = floor($start_item / $per_page) + 1;
		$var_node = $this->load_var([
			'type' => 'pager',
			'lid' => "$name",
			'value' => [
				'value' => "$on_page",
				'perpage' => "$per_page",
				'total' => "$total_pages"
			]
		]);
		return $var_node;
	}
	// }}}
	// {{{ load_user()
	/**
	 * @param mixed $user
	 * @param int|bool $is_current
	 * @return mixed
	 */
	public function load_user($user = false, $is_current = false) {
		if (empty($user)) { return false; }
		$nodes = [];
		if (is_array($user) && !isset($user['nid'])) {
			foreach ($user as $k => $u) { $nodes = $this->merge_nodes($nodes, $this->load_user($u)); }
		} else {
			if (is_object($user)) { $user = get_object_vars($user); }
			if (isset($user['is_current'])) { $is_current = $user['is_current'] ? 1 : 0; }
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['foaf'].'Person';
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'nid'][0]['value'] = $user['nid'];
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'ip'][0]['value'] = $user['session_ip'] ?? '';
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'isActive'][0]['value'] = $user['is_active'];
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'is_guest'][0]['value'] = $user['email'] == ANONYMOUS ? '1' : '0';
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'is_current'][0]['value'] = $is_current ? '1' : '0';
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'url'][0]['value'] = $user['last_url'];
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'last-visit'][0]['value'] = lunaTools::get_time_since($user['last_time']);
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'registration-date'][0]['value'] = ($user['regis_time'] == 0 ? '' : lunaTools::format_date($user['regis_time']));
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['foaf'].'name'][0]['value'] = trim(lunaTools::display_string($user['firstname']).' '.lunaTools::display_string($user['lastname']));
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['foaf'].'firstName'][0]['value'] = trim(lunaTools::display_string($user['firstname']));
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['foaf'].'surName'][0]['value'] = trim(lunaTools::display_string($user['lastname']));
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['foaf'].'mbox'][0]['value'] = 'mailto:'.$user['email'];
			$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['foaf'].'mbox'][0]['type'] = 'uri';
			if (isset($user['groups']) && is_array($user['groups'])) {
				foreach ($user['groups'] as $group_nid) {
					$nodes[$this->node_path.'/'.$group_nid][$this->conf['ns']['luna'].'nid'][0]['value'] = $group_nid;
					$nodes[$this->node_path.'/'.$group_nid][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].'group';
					$nodes[$this->node_path.'/'.$group_nid][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
					$needle = [
						'value' => $this->node_path.'/'.$group_nid,
						'type' => 'uri'
					];
					if (!isset($nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'group']) || !in_array($needle, $nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'group'])) {
						$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'group'][] = $needle;
					}
				}
			}
			if (isset($user['levels']) && is_array($user['levels'])) {
				foreach ($user['levels'] as $level_nid) {
					$nodes[$this->node_path.'/'.$level_nid][$this->conf['ns']['luna'].'nid'][0]['value'] = $level_nid;
					$nodes[$this->node_path.'/'.$level_nid][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].'level';
					$nodes[$this->node_path.'/'.$level_nid][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
					$needle = [
						'value' => $this->node_path.'/'.$level_nid,
						'type' => 'uri'
					];
					if (!isset($nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'level']) || !in_array($needle, $nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'level'])) {
						$nodes[$this->node_path.'/'.$user['nid']][$this->conf['ns']['luna'].'level'][] = $needle;
					}
				}
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_users()
	/**
	 * @param int|false $user_nid
	 * @param int|false $group
	 * @return array
	 */
	public function load_users($user_nid = false, $group = false): array|false {
		lunaTools::parse_sort_cookie(luna::$data['lid'] ?? '');
		$nodes = [];
		$user_nid = intval($user_nid);
		if (!empty($user_nid)) {
			$res = lunaDB::query('
				SELECT
					nu.nid,
					nu.is_active,
					u.firstname,
					u.lastname,
					nu.lid as email,
					u.regis_time,
					u.last_time,
					u.last_url,
					u.lang,
					g.nid as group_nid,
					l.nid as level_nid
				FROM
					'.luna::get_ini('DBtables', 'USERS').' u,
					'.luna::get_ini('DBtables', 'NODES').' nu,
					'.luna::get_ini('DBtables', 'NODES').' g,
					'.luna::get_ini('DBtables', 'NODES').' l,
					'.luna::get_ini('DBtables', 'NODES_MAP').' gl,
					'.luna::get_ini('DBtables', 'CLASSES').' tg,
					'.luna::get_ini('DBtables', 'CLASSES').' tu,
					'.luna::get_ini('DBtables', 'NODES_MAP').' ug,
					'.luna::get_ini('DBtables', 'CLASSES').' tl
				WHERE 1= 1
					AND nu.nid = '.lunaDB::quote($user_nid).'
					AND ug.nid1 = u.nid
					AND ug.nid2 = g.nid
					AND u.nid = nu.nid
					AND tu.lid = '.lunaDB::quote('user').'
					AND nu.tid = tu.id
					AND tg.lid = '.lunaDB::quote('group').'
					AND g.tid = tg.id
					AND l.tid = tl.id
					AND tl.lid = '.lunaDB::quote('level').'
					AND gl.nid1 = g.nid
					AND gl.nid2 = l.nid
			');
		} else {
			$cookie['order_by'] = luna::$data['order_by'] = lunaTools::request('order_by', 0, 'last_time');
			if (!empty($group)) { $_POST['group_nid'] = intval($group); }
			$group_nid = lunaTools::request('group_nid');
			$groupsql = !empty($group_nid) ? ' AND g.nid = '.lunaDB::quote(intval($group_nid)).' ' : '';
			$order_dir = lunaTools::request('order_dir', 0, 'DESC');
			$alphastyle = 0;
			// Whitelist the sort column: order_by is request input, so the raw value must
			// never reach a SQL identifier (nor be reflected/persisted unfiltered).
			$order_map = [
				'nid'        => ['nu.nid',       false, 'ASC'],
				'firstname'  => ['u.firstname',  true,  'ASC'],
				'lastname'   => ['u.lastname',   true,  'ASC'],
				'email'      => ['nu.lid',       true,  'ASC'],
				'regis_time' => ['u.regis_time', false, 'DESC'],
				'last_time'  => ['u.last_time',  false, 'DESC'],
			];
			$order_key = isset($order_map[$cookie['order_by']]) ? $cookie['order_by'] : 'last_time';
			$cookie['order_by'] = luna::$data['order_by'] = $order_key;
			$order_by_ok = $order_map[$order_key][0];
			$alphastyle  = $order_map[$order_key][1];
			if (empty($order_dir)) { $order_dir = $order_map[$order_key][2]; }
			$order_dir = ($order_dir == 'DESC') ? 'DESC' : 'ASC';
			luna::$data['order_dir'] = $order_dir;
			$cookie['order_dir'] = luna::$data['order_dir'];
			if (!defined('PERPAGE')) { define('PERPAGE', 20); }
			luna::$data['limit'] = max(1, intval(lunaTools::request('limit', 0, PERPAGE)));
			$start = max(0, intval(lunaTools::request('start', 0, 0)));
			luna::$data['start'] = luna::$data['start'] = $start;
			lunaTools::set_cookie(luna::$data['lid'].'_sort', $cookie);
			switch ($cookie['order_by']) {
				case 'firstname':
				case 'lastname':
				case 'email':
				case 'nid':
				case 'regis_time':
				case 'last_time':
				default:
					$res = lunaDB::query('
						SELECT
							COUNT(DISTINCT nu.nid) as total
						FROM
							'.luna::get_ini('DBtables', 'USERS').' u,
							'.luna::get_ini('DBtables', 'NODES').' nu,
							'.luna::get_ini('DBtables', 'NODES').' g,
							'.luna::get_ini('DBtables', 'NODES_MAP').' ug,
							'.luna::get_ini('DBtables', 'CLASSES').' tu,
							'.luna::get_ini('DBtables', 'CLASSES').' tg
						WHERE 1 = 1
							AND tu.lid = '.lunaDB::quote('user').'
							AND nu.tid = tu.id
							AND u.nid = nu.nid
							AND tg.lid = '.lunaDB::quote('group').'
							AND g.tid = tg.id
							AND ug.nid1 = nu.nid
							AND ug.nid2 = g.nid '.$groupsql.'
						ORDER BY
							'.$order_by_ok.' '.$order_dir.'
					');
					break;
			}
			$row = $res->fetchRow();
			$res->free();
			$total = empty($row) ? 0 : $row->total;
			switch ($cookie['order_by']) {
				case 'firstname':
				case 'lastname':
				case 'email':
				case 'nid':
				case 'regis_time':
				case 'last_time':
				default:
					$res = lunaDB::query('
						SELECT
							nu.nid,
							nu.is_active,
							u.firstname,
							u.lastname,
							nu.lid as email,
							u.regis_time,
							u.last_time,
							u.last_url,
							u.lang,
							g.nid as group_nid,
							l.nid as level_nid
						FROM
							'.luna::get_ini('DBtables', 'USERS').' u,
							'.luna::get_ini('DBtables', 'NODES').' nu,
							'.luna::get_ini('DBtables', 'NODES').' g,
							'.luna::get_ini('DBtables', 'NODES').' l,
							'.luna::get_ini('DBtables', 'NODES_MAP').' gl,
							'.luna::get_ini('DBtables', 'NODES_MAP').' ug,
							'.luna::get_ini('DBtables', 'CLASSES').' tu,
							'.luna::get_ini('DBtables', 'CLASSES').' tg,
							'.luna::get_ini('DBtables', 'CLASSES').' tl
						WHERE 1 = 1
							AND tu.lid = '.lunaDB::quote('user').'
							AND nu.tid = tu.id
							AND tg.lid = '.lunaDB::quote('group').'
							AND l.tid = tl.id
							AND tl.lid = '.lunaDB::quote('level').'
							AND gl.nid1 = g.nid
							AND gl.nid2 = l.nid
							AND g.tid = tg.id
							AND ug.nid1 = nu.nid
							AND ug.nid2 = g.nid '.$groupsql.'
							AND u.nid = nu.nid
						GROUP BY
							nu.nid
						ORDER BY
							'.$order_by_ok.' '.$order_dir.'
						LIMIT
							'.$start.', '.luna::$data['limit'].'
					');
					break;
			}
		}
		$users = [];
		while ($row = $res->fetchRow()) {
			$users[$row->nid]['nid'] = $row->nid;
			$users[$row->nid]['is_active'] = $row->is_active;
			$users[$row->nid]['firstname'] = $row->firstname;
			$users[$row->nid]['lastname'] = $row->lastname;
			$users[$row->nid]['email'] = $row->email;
			$users[$row->nid]['regis_time'] = $row->regis_time;
			$users[$row->nid]['last_time'] = $row->last_time;
			$users[$row->nid]['last_url'] = $row->last_url;
			$users[$row->nid]['lang'] = $row->lang;
			$users[$row->nid]['groups'][$row->group_nid] = $row->group_nid;
			$users[$row->nid]['levels'][$row->level_nid] = $row->level_nid;
			$users[$row->nid]['is_current'] = ($row->nid == luna::$session->user->nid) ? 1 : 0;
		}
		$res->free();
		$nodes = luna::model()->merge_nodes($nodes, luna::model()->load_user($users));
		luna::model()->merge_index(luna::model()->load_pager(($total ?? 0), ($start ?? 0), (luna::$data['limit'] ?? PERPAGE), (luna::$data['lid'] ?? '')));
		return $nodes;
	}
	// }}}
	// {{{ load_text()
	/**
	 * @param mixed $item
	 * @return mixed
	 */
	public function load_text($item = false) {
		if (empty($item)) { return false; }
		$nodes = [];
		if (is_array($item) && !isset($item['nid'])) {
			foreach ($item as $k => $v) { $nodes = $this->merge_nodes($nodes, $this->load_text($v)); }
		} else {
			if (is_object($item)) { $item = get_object_vars($item); }
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].'text';
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'nid'][0]['value'] = $item['nid'];
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'lid'][0]['value'] = $item['lid'];
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['rdfs'].'label'][0]['value'] = $item['title'];
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['rdfs'].'label'][0]['type'] = 'literal';
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['rdfs'].'label'][0]['lang'] = lunaTools::format_language($item['lang']);
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'isActive'][0]['value'] = $item['is_active'];
			$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'save_time'][0]['value'] = ($item['save_time'] == 0 ? '' : lunaTools::format_date($item['save_time']));
			if (isset($item['content']) && !empty($item['content'])) {
				$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'content'][0]['value'] = $item['content'];
				$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'content'][0]['type'] = 'literal';
				$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'content'][0]['lang'] = lunaTools::format_language($item['lang']);
			}
			if (isset($item['user']) && is_array($item['user'])) {
				$nodes[$this->node_path.'/'.$item['user']['nid']][$this->conf['ns']['luna'].'nid'][0]['value'] = $item['user']['nid'];
				$nodes[$this->node_path.'/'.$item['user']['nid']][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['foaf'].'Person';
				$nodes[$this->node_path.'/'.$item['user']['nid']][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
				$needle = [
					'value' => $this->node_path.'/'.$item['user']['nid'],
					'type' => 'uri'
				];
				if (!isset($nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'user']) || !in_array($needle, $nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'user'])) {
					$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'user'][] = $needle;
				}
			}
			if (isset($item['pages'])) {
				foreach ($item['pages'] as $page_nid) {
					$nodes[$this->node_path.'/'.$page_nid][$this->conf['ns']['luna'].'nid'][0]['value'] = $page_nid;
					$nodes[$this->node_path.'/'.$page_nid][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].'page';
					$nodes[$this->node_path.'/'.$page_nid][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
					$needle = [
						'value' => $this->node_path.'/'.$page_nid,
						'type' => 'uri'
					];
					if (!isset($nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'page']) || !in_array($needle, $nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'page'])) {
						$nodes[$this->node_path.'/'.$item['nid']][$this->conf['ns']['luna'].'page'][] = $needle;
					}
				}
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_texts()
	/**
	 * @param int|false $item_nid
	 * @param int|false $page_nid
	 * @return array
	 */
	public function load_texts($item_nid = false, $page_nid = false): array|false {
		lunaTools::parse_sort_cookie(luna::$data['lid'] ?? '');
		$cookie = [];
		$nodes = [];
		$item_nid = intval($item_nid);
		$page_nid = intval($page_nid);
		if (!empty($item_nid)) {
			$res = lunaDB::query('
				SELECT DISTINCT
					t.title,
					t.lang,
					t.content,
					n.nid,
					p.nid as page_nid,
					n.lid,
					p.lid as page_lid,
					n.is_active,
					u.nid as user_nid,
					u.firstname,
					u.lastname,
					a.ntime
				FROM
					'.luna::get_ini('DBtables', 'NODES').' n,
					'.luna::get_ini('DBtables', 'NODES').' p,
					'.luna::get_ini('DBtables', 'NODES_MAP').' map,
					'.luna::get_ini('DBtables', 'TEXTS').' t,
					'.luna::get_ini('DBtables', 'ACTIONS').' a,
					'.luna::get_ini('DBtables', 'USERS').' u
				WHERE
					n.tid = (
						SELECT
							id
						FROM
							'.luna::get_ini('DBtables', 'CLASSES').'
						WHERE
							lid = '.lunaDB::quote('text').'
					)
					AND t.nid = n.nid
					AND a.nid = n.nid
					AND u.nid = a.unid
					AND n.nid = '.lunaDB::quote($item_nid).'
					AND map.nid1 = n.nid
					AND map.nid2 = p.nid
			');
		} elseif (!empty($page_nid)) {
			$res = lunaDB::query('
				SELECT DISTINCT
					t.title,
					t.lang,
					t.content,
					n.nid,
					p.nid as page_nid,
					n.lid,
					p.lid as page_lid,
					n.is_active,
					u.nid as user_nid,
					u.firstname,
					u.lastname,
					a.ntime
				FROM
					'.luna::get_ini('DBtables', 'NODES').' n,
					'.luna::get_ini('DBtables', 'NODES').' p,
					'.luna::get_ini('DBtables', 'NODES_MAP').' map,
					'.luna::get_ini('DBtables', 'TEXTS').' t,
					'.luna::get_ini('DBtables', 'ACTIONS').' a,
					'.luna::get_ini('DBtables', 'USERS').' u
				WHERE
					n.tid = (
						SELECT
							id
						FROM
							'.luna::get_ini('DBtables', 'CLASSES').'
						WHERE
							lid = '.lunaDB::quote('text').'
					)
					AND t.nid = n.nid
					AND a.nid = n.nid
					AND u.nid = a.unid
					AND p.nid = '.lunaDB::quote($page_nid).'
					AND map.nid1 = n.nid
					AND map.nid2 = p.nid
			');
		} else {
			$cookie['order_by'] = luna::$data['order_by'] = lunaTools::request('order_by', 0, 'last_time');
			$order_dir = lunaTools::request('order_dir', 'DESC');
			$alphastyle = 0;
			switch (luna::$data['order_by']) {
				case 'lid':
					$order_by_ok = 'n.'.luna::$data['order_by'];
					$alphastyle = true;
					if (empty($order_dir)) { $order_dir = 'ASC'; }
					break;
				case 'title':
				case 'lang':
					$order_by_ok = 't.'.luna::$data['order_by'];
					$alphastyle = true;
					if (empty($order_dir)) { $order_dir = 'ASC'; }
					break;
				case 'last_time':
				default:
					$order_by_ok = 'a.ntime';
					$alphastyle = false;
					if (empty($order_dir)) { $order_dir = 'DESC'; }
					break;
			}
			$cookie['order_dir'] = luna::$data['order_dir'] = ($order_dir == 'DESC') ? 'DESC' : 'ASC';
			if (!defined('PERPAGE')) { define('PERPAGE', 20); }
			luna::$data['limit'] = max(1, intval(lunaTools::request('limit', 0, PERPAGE)));
			$cookie['limit'] = luna::$data['limit'];
			$start = max(0, intval(lunaTools::request('start', 0, 0)));
			if (empty($start)) { $start = 0; }
			$cookie['start'] = luna::$data['start'] = $start;
			lunaTools::set_cookie(luna::$data['lid'].'_sort', $cookie);
			// Every accepted ordering runs the same query; only $order_by_ok and $order_dir,
			// both validated above, differ. The cases are listed to record what is accepted.
			switch (luna::$data['order_by']) {
				case 'lid':
				case 'title':
				case 'lang':
				case 'last_time':
				default:
					$res = lunaDB::query('
						SELECT
							COUNT(DISTINCT n.nid) as total
						FROM
							'.luna::get_ini('DBtables', 'NODES').' n,
							'.luna::get_ini('DBtables', 'TEXTS').' t,
							'.luna::get_ini('DBtables', 'ACTIONS').' a
						WHERE
							n.tid = (
								SELECT
									id
								FROM
									'.luna::get_ini('DBtables', 'CLASSES').'
								WHERE
									lid = '.lunaDB::quote('text').'
							)
							AND t.nid = n.nid
							AND a.nid = n.nid
						ORDER BY
							'.$order_by_ok.' '.$order_dir.'
					');
					break;
			}
			$row = $res->fetchRow();
			$res->free();
			$total = empty($row) ? 0 : $row->total;
			// Every accepted ordering runs the same query; only $order_by_ok and $order_dir,
			// both validated above, differ. The cases are listed to record what is accepted.
			switch (luna::$data['order_by']) {
				case 'lid':
				case 'title':
				case 'lang':
				case 'last_time':
				default:
					$res = lunaDB::query('
						SELECT
							t.title,
							t.lang,
							n.nid,
							n.lid,
							n.is_active,
							u.nid as user_nid,
							u.firstname,
							u.lastname,
							a.ntime
						FROM
							'.luna::get_ini('DBtables', 'NODES').' n,
							'.luna::get_ini('DBtables', 'TEXTS').' t,
							'.luna::get_ini('DBtables', 'ACTIONS').' a,
							'.luna::get_ini('DBtables', 'USERS').' u
						WHERE
							n.tid = (
								SELECT
									id
								FROM
									'.luna::get_ini('DBtables', 'CLASSES').'
								WHERE
									lid = '.lunaDB::quote('text').'
							)
							AND t.nid = n.nid
							AND a.nid = n.nid
							AND u.nid = a.unid
						ORDER BY
							'.$order_by_ok.' '.$order_dir.'
						LIMIT
							'.$start.', '.luna::$data['limit'].'
					');
					break;
			}
		}
		$texts = [];
		while ($row = $res->fetchRow()) {
			$texts[$row->nid]['nid'] = $row->nid;
			$texts[$row->nid]['lid'] = $row->lid;
			$texts[$row->nid]['title'] = $row->title;
			$texts[$row->nid]['is_active'] = $row->is_active;
			$texts[$row->nid]['user']['nid'] = $row->user_nid;
			$texts[$row->nid]['user']['firstname'] = $row->firstname;
			$texts[$row->nid]['user']['lastname'] = $row->lastname;
			if (isset($row->page_nid)) { $texts[$row->nid]['pages'][$row->page_nid] = $row->page_nid; }
			$texts[$row->nid]['save_time'] = $row->ntime;
			if (isset($row->content)) { $texts[$row->nid]['content'] = $row->content; }
			if (isset($row->lang)) { $texts[$row->nid]['lang'] = $row->lang; }
		}
		$res->free();
		$nodes = luna::model()->merge_nodes($nodes, luna::model()->load_text($texts));
		luna::model()->merge_index(luna::model()->load_pager(($total ?? 0), ($start ?? 0), (luna::$data['limit'] ?? PERPAGE), (luna::$data['lid'] ?? '')));
		return $nodes;
	}
	// }}}
	// {{{ load_data()
	/**
	 * @param array|false $data
	 * @return array|false
	 */
	public function load_data($data = false, $label = 'data'): array|false {
		if (!is_array($data)) { return false; }
		$nodes = [];
		foreach ($data as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $vk => $vv) {
					if ($vk != 'PHPSESSID') {
						$var_node = $this->load_var([
							'type' => $label,
							'lid' => "$k.$vk",
							'value' => "$vv"
						]);
						$nodes = $this->merge_nodes($nodes, $var_node);
					}
				}
			} else {
				if ($k != 'PHPSESSID') {
					$var_node = $this->load_var([
						'type' => $label,
						'lid' => "$k",
						'value' => "$v"
					]);
					$nodes = $this->merge_nodes($nodes, $var_node);
				}
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_request
	/**
	 * @param array|false $data
	 * @param string|false $label
	 * @return bool|array
	 */
	public function load_request($data = false, $label = false): bool|array {
		if (empty($label)) { return false; }
		$nodes = [];
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				if (is_array($v)) {
					$klabel = ($label == 'request') ? "$k" : $label.'.'."$k";
					$nodes = $this->merge_nodes($nodes, $this->load_request($v, $klabel));
				} else {
					if (empty($k)) { $k = "0"; }
					if ($k != 'PHPSESSID') {
						$klabel = ($label == 'request') ? "$k" : $label.'.'."$k";
						$serv = $v;
						// Guard against PHP object injection: only expand serialized
						// arrays/scalars from request input, never objects (O:/C:).
						$unserv = (is_string($v) && !preg_match('/(?:^|;)[OC]:[0-9]+:/', $v)) ? @unserialize($v) : false;
						if (empty($unserv)) {
							$nodes = $this->merge_nodes($nodes, $this->load_request($serv, $klabel));
						} else {
							$nodes = $this->merge_nodes($nodes, $this->load_request($unserv, $klabel));
						}
					}
				}
			}
		} else {
			if ($label != 'PHPSESSID') {
				$var_node = $this->load_var([
					'type' => 'request',
					'lid' => "$label",
					'value' => "$data"
				]);
				$nodes = $this->merge_nodes($nodes, $var_node);
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_vocabulary()
	/**
	 * @param array|false $vocabulary
	 * @return array|false
	 */
	public function load_vocabulary($vocabulary = false): array|false {
		if (!is_array($vocabulary)) { return false; }
		$nodes = [];
		foreach ($vocabulary as $k => $v) {
			$var_node = $this->load_var([
				'type' => 'vocabulary',
				'lid' => "$k",
				'value' => _("$v"),
				'lang' => luna::$lang
			]);
			$nodes = $this->merge_nodes($nodes, $var_node);
		}
		return $nodes;
	}
	// }}}
	// {{{ check_requested_node()
	/**
	 * @param string|false $var
	 * @param string|false $type
	 * @param string $ns
	 * @return int|false
	 */
	public function check_requested_node($var = false, string|false $type = false, $ns = 'luna'): int|false {
		if (empty($var)) { return false; }
		if (empty($ns)) { $ns = 'luna'; }
		$nid = lunaTools::request("$var");
		$node = false;
		if ($nid) { $node = luna::model()->get_node($nid, "$type", "$ns"); }
		if (!empty($node)) {
			$_POST["$var"] = $_REQUEST["$var"] = $nid;
			luna::$data['modify_item_nid'] = $nid;
		} else {
			if (isset(luna::$data['subdir']) && !empty(luna::$data['subdir'])) {
				$nid = luna::model()->get_nid_from_lid(luna::$data['subdir']);
				$node = luna::model()->get_node($nid, "$type");
				if ($node) { $_POST[$var] = $_REQUEST[$var] = $nid; }
				luna::$data['modify_item_nid'] = $nid;
				luna::model()->merge_index(luna::model()->load_users(false, $nid));
			}
		}
		return $nid;
	}
	// }}}
	// {{{ check_if_node_exists()
	/**
	 * @param int|false $nid
	 * @param string|false $type
	 * @return array|false
	 */
	public function check_if_node_exists($nid = false, $type = false): array|false {
		$nid = intval($nid);
		if (empty($nid) || empty($type)) { return false; }
		$item_node = luna::model()->get_node($nid, "$type");
		if (!$item_node) {
			$message = sprintf(_("Unknown $type #%1\$s."), $_POST['modify_item_nid']);
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_WARNING);
			return false;
		}
		return $item_node;
	}
	// }}}
	// {{{ check_if_lid_is_protected()
	/**
	 * @param array|false $node
	 * @param array|false $lids
	 * @return string|false the item's lid when it is NOT protected; false when it is
	 */
	public function check_if_lid_is_protected($node = false, $lids = false): string|false {
		if (empty($node) || !is_array($lids) || empty($lids)) { return false; }
		$item_lid = $this->get_lid($node);
		if (!$item_lid || in_array($item_lid, $lids)) {
			$message = sprintf(_("You cannot modify the item labeled “%1\$s”."), _($item_lid));
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
			return false;
		}
		return $item_lid;
	}
	// }}}
	// {{{ check_if_lid_is_taken()
	/**
	 * @param string|false $lid
	 * @param int|false $nid
	 * @return bool
	 */
	public function check_if_lid_is_taken($lid = false, int|false $nid = false): bool {
		if (empty($lid)) { return false; }
		$nid = intval($nid);
		if ($this->lid_is_taken("$lid", $nid)) {
			$message = sprintf(_("The identifier “%1\$s” is already taken."), "$lid");
			luna::$messages['warning'][] = $message;
			lunaLog::log($message, PEAR_LOG_NOTICE);
			return false;
		}
		return true;
	}
	// }}}
	// {{{ load_var()
	/**
	 * @param array|false $var
	 * @return array|false
	 */
	public function load_var($var = false): array|false {
		if (!is_array($var)) { return false; }
		if (!isset($var['type'])) {
			$nodes = [];
			foreach ($var as $v) {
				$subnodes = $this->load_var($v);
				if (!$subnodes) { return false; }
				$nodes = $this->merge_nodes($nodes, $subnodes);
			}
			return $nodes;
		} else {
			if (!isset($var['lid']) || !isset($var['value'])) { return false; }
			$nodes = [];
			$lid = lunaTools::prepare_lid($var['lid']);
			$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui'].'lid'][0]['value'] = $var['lid'];
			$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui'].'lid'][0]['type'] = 'bnode';
			if (isset($var['lang'])) { $nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui'].'lid'][0]['lang'] = $var['lang']; }
			if (is_array($var['value'])) {
				foreach ($var['value'] as $k => $v) {
					$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui']."$k"][0]['value'] = "$v";
					$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui']."$k"][0]['type'] = 'bnode';
				}
			} else {
				$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui']."value"][0]['value'] = $var['value'];
				$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['ui']."value"][0]['type'] = 'bnode';
			}
			$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['ui'].$var['type'];
			$nodes['_:'.$var['type'].'-'.$lid][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
			return $nodes;
		}
	}
	// }}}
	// {{{ load_node()
	/**
	 * @param mixed $node
	 * @param string|false $type1
	 * @param mixed $type2
	 * @return array|false
	 */
	public function load_node($node = false, $type1 = false, $type2 = false): array|false {
		if (empty($node)) { return false; }
		if (is_object($node)) { $node = get_object_vars($node); }
		if (!isset($node['nid']) || empty($node['nid'])) { return false; }
		if (empty($type1) && isset($node['type1'])) { $type1 = $node['type1']; }
		if (empty($type1)) { return false; }
		$nodes = [];
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].'nid'][0]['value'] = $node['nid'];
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].'lid'][0]['value'] = $node['lid'];
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['rdfs'].'label'][0]['value'] = lunaTools::label($node['lid']);
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['rdfs'].'label'][0]['lang'] = luna::$lang;
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['rdfs'].'label'][0]['type'] = 'literal';
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].'isActive'][0]['value'] = $node['is_active'];
		if (isset($node['parent_nid']) && $node['parent_nid']) {
			$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['schema'].'isPartOf'][0]['value'] = $this->node_path.'/'.$node['parent_nid'];
			$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['schema'].'isPartOf'][0]['type'] = 'uri';
		} elseif (empty($node['parent_nid']) && $type1 == 'page') {
			$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['schema'].'isPartOf'][0]['value'] = $this->node_path.'/'.$node['nid'];
			$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['schema'].'isPartOf'][0]['type'] = 'uri';
		}
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].$type1;
		$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
		if (is_string($type2) && isset($node['nid2'])) {
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['luna'].'nid'][0]['value'] = $node['nid2'];
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['luna'].'lid'][0]['value'] = $node['lid2'];
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['rdfs'].'label'][0]['value'] = lunaTools::label($node['lid2']);
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['rdfs'].'label'][0]['lang'] = luna::$lang;
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['rdfs'].'label'][0]['type'] = 'literal';
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['luna'].'isActive'][0]['value'] = $node['is_active2'];
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].$type2;
			$nodes[$this->node_path.'/'.$node['nid2']][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
			$needle = [
				'value' => $this->node_path.'/'.$node['nid2'],
				'type' => 'uri'
			];
			if (!isset($nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$type2]) || !in_array($needle, $nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$type2])) {
				$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$type2][] = $needle;
			}
		} elseif (is_array($type2)) {
			$i = 1;
			foreach ($type2 as $typex) {
				$nidx = 'nid'.($i + 1);
				$lidx = 'lid'.($i + 1);
				$is_activex = 'is_active'.($i + 1);
				if (isset($node[$nidx])) {
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['luna'].'nid'][0]['value'] = $node[$nidx];
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['luna'].'lid'][0]['value'] = $node[$lidx];
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['rdfs'].'label'][0]['value'] = lunaTools::label($node[$lidx]);
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['rdfs'].'label'][0]['lang'] = luna::$lang;
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['rdfs'].'label'][0]['type'] = 'literal';
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['luna'].'isActive'][0]['value'] = $node[$is_activex];
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['rdf'].'type'][0]['value'] = $this->conf['ns']['luna'].$typex;
					$nodes[$this->node_path.'/'.$node[$nidx]][$this->conf['ns']['rdf'].'type'][0]['type'] = 'uri';
					$needle = [
						'value' => $this->node_path.'/'.$node[$nidx],
						'type' => 'uri'
					];
					if (!isset($nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$typex]) || !in_array($needle, $nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$typex])) {
						$nodes[$this->node_path.'/'.$node['nid']][$this->conf['ns']['luna'].$typex][] = $needle;
					}
				}
				$i++;
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ load_nodes()
	/**
	 * @param string|false $type1
	 * @param mixed $type2
	 * @param int|false $nid
	 * @return mixed
	 */
	public function load_nodes($type1 = false, $type2 = false, int|false $nid = false) {
		if (empty($type1) || !is_string($type1)) { $type1 = false; }
		if (empty($type2) || (!is_string($type2) && !is_array($type2))) { $type2 = false; }
		$nid = intval($nid);
		if ($nid < 1) { $nid = false; }
		$sql = [
			'select' => '',
			'from' => '',
			'where' => ''
		];
		$type1_sql = '';
		if (!empty($type1)) { $type1_sql = ' AND n.tid = (SELECT id FROM '.luna::get_ini('DBtables', 'CLASSES').' WHERE lid = '.lunaDB::quote("$type1").') '; }
		if (is_string($type2)) {
			$i = 1;
			$sql['select'] .=  'n'.($i + 1).'.nid as nid'.($i + 1).', ';
			$sql['select'] .=  'n'.($i + 1).'.lid as lid'.($i + 1).', ';
			$sql['select'] .=  'n'.($i + 1).'.is_active as is_active'.($i + 1).', ';
			$sql['from'] .=  ',
				'.luna::get_ini('DBtables', 'NODES_MAP').' map'.($i + 1).'
			LEFT JOIN
				'.luna::get_ini('DBtables', 'NODES').' n'.($i + 1).'
			ON
				map'.($i + 1).'.nid2 = n'.($i + 1).'.nid
				';
			$sql['where'] .=  'AND map'.($i + 1).'.nid1 = n.nid AND n'.($i + 1).'.tid = (SELECT id FROM '.luna::get_ini('DBtables', 'CLASSES').' WHERE lid = '.lunaDB::quote("$type2").') ';
			if ($type2 == 'level') { $sql['where'] .= 'AND n2.nid IN ('.(implode(',', array_map('intval', (array) luna::$session->user->levels)) ?: '0').')'; }
		} elseif (is_array($type2)) {
			$i = 1;
			foreach ($type2 as $type2x) {
				$sql['select'] .=  'n'.($i + 1).'.nid as nid'.($i + 1).', ';
				$sql['select'] .=  'n'.($i + 1).'.lid as lid'.($i + 1).', ';
				$sql['select'] .=  'n'.($i + 1).'.is_active as is_active'.($i + 1).', ';
				$sql['from'] .=  ', '.luna::get_ini('DBtables', 'NODES_MAP').' map'.($i + 1).' LEFT JOIN '.luna::get_ini('DBtables', 'NODES').' n'.($i + 1).' ON map'.($i + 1).'.nid2 = n'.($i + 1).'.nid ';
				$sql['where'] .=  'AND map'.($i + 1).'.nid1 = n.nid AND n'.($i + 1).'.tid = (SELECT id FROM '.luna::get_ini('DBtables', 'CLASSES').' WHERE lid = '.lunaDB::quote("$type2x").') ';
				if ($type2x == 'level') { $sql['where'] .= 'AND n'.($i + 1).'.nid IN ('.(implode(',', array_map('intval', (array) luna::$session->user->levels)) ?: '0').')'; }
				$i++;
			}
		}
		if ($nid > 0) { $sql['where'] 	.=  ' AND n.nid = '.lunaDB::quote($nid).' '; }
		$query = '
			SELECT
				DISTINCT n.nid,
				t.lid as type1,
				n.lid,
				'.$sql['select'].'
				n.is_active,
				n.parent_nid,
				u.nid as user_nid,
				u.firstname,
				u.lastname,
				a.ntime
			FROM
				'.luna::get_ini('DBtables', 'NODES').' n,
				'.luna::get_ini('DBtables', 'USERS').' u,
				'.luna::get_ini('DBtables', 'CLASSES').' t,
				'.luna::get_ini('DBtables', 'ACTIONS').' a'.$sql['from'].'
			WHERE 1 = 1
				'.$type1_sql.'
				AND a.nid = n.nid
				AND t.id = n.tid
				AND u.nid = a.unid
				'.$sql['where'].'
			ORDER BY
				n.lid,
				a.ntime ASC
		';
		$res = lunaDB::query($query);
		$nodes = [];
		while ($row = $res->fetchRow()) { $nodes = $this->merge_nodes($nodes, $this->load_node($row, $type1, $type2)); }
		$res->free();
		if ($type1 == 'page' && !is_array($type2) && ($type2 == 'level' || empty($type2))) {
			$this->aliases = [];
			if (!$nodes = $this->calculate_aliases($nodes)) { throw new lunaException(_('Error: cannot calculate aliases.'), PEAR_LOG_CRIT); }
		}
		return $nodes;
	}
	// }}}
	// {{{ would_create_cycle()
	/**
	 * Would setting node $nid's parent to $parent_nid make the page tree cyclic — i.e. is
	 * $parent_nid the node itself, or one of its descendants? Walks the parent chain up
	 * from the proposed parent; reaching $nid means the new edge closes a loop. The walk
	 * carries a visited set, so it terminates — and reports a cycle — even if the stored
	 * data already contains one. A cyclic parent_nid makes the tree walk in
	 * calculate_aliases() / get_children_nodes() non-terminating, so it must never be
	 * written, and this is what refuses it: the admin pages form calls it before attempting
	 * the re-parent, so it can show its message rather than corrupt the tree.
	 *
	 * @param int $nid        the node being re-parented
	 * @param int $parent_nid the proposed new parent
	 * @return bool true if the move would create a cycle
	 */
	public function would_create_cycle($nid = 0, int $parent_nid = 0): bool {
		$nid = intval($nid);
		$parent_nid = intval($parent_nid);
		if (!$nid || !$parent_nid) { return false; }
		$seen = [];
		$p = $parent_nid;
		while ($p) {
			if ($p === $nid) { return true; }       // nid is the proposed parent or one of its ancestors → loop
			if (isset($seen[$p])) { return true; }  // pre-existing loop in the stored tree → cyclic (and stop walking)
			$seen[$p] = true;
			$res = lunaDB::query('SELECT parent_nid FROM '.luna::get_ini('DBtables', 'NODES').' WHERE nid = '.lunaDB::quote($p).' LIMIT 1');
			$row = ($res) ? $res->fetchRow() : false;
			if ($res) { $res->free(); }
			$p = ($row && !empty($row->parent_nid)) ? intval($row->parent_nid) : 0;
		}
		return false;
	}
	// }}}
	// {{{ first_child_nid()
	/**
	 * Does any node still name $nid as its parent, and if so which one? Returns the first child's
	 * nid, or 0 when the node has none — the question delete() must ask before it removes a node,
	 * since a surviving child would be left pointing at a parent row that no longer exists.
	 *
	 * Reads SQL at full scope, deliberately, and for the same reason would_create_cycle() does:
	 * whether a delete orphans content is a property of the store, not of the requester's view — a
	 * child page the requester cannot see is orphaned just as thoroughly as one they can.
	 * get_children_nodes() walks $this->index, which is ACL-scoped and holds only what this request
	 * loaded, so it is the wrong instrument for this question.
	 *
	 * @param int $nid the node whose children are being counted
	 * @return int the nid of the first child found, or 0 when there is none
	 */
	public function first_child_nid($nid = 0): int {
		$nid = intval($nid);
		if (!$nid) { return 0; }
		$res = lunaDB::query('
			SELECT
				nid
			FROM
				'.luna::get_ini('DBtables', 'NODES').'
			WHERE
				parent_nid = '.lunaDB::quote($nid).'
			LIMIT 1
		');
		$row = ($res) ? $res->fetchRow() : false;
		if ($res) { $res->free(); }
		return ($row && !empty($row->nid)) ? intval($row->nid) : 0;
	}
	// }}}
	// {{{ insert()
	/**
	 * @param string|false $type1
	 * @param string|false $lid
	 * @param int|bool $is_active
	 * @param int|false $parent_nid
	 * @param int|false $time
	 * @return int|false
	 */
	public function insert($type1 = false, $lid = false, $is_active = false, $parent_nid = false, $time = false): int|false {
		if (empty($type1) || !is_string($type1)) { return false; }
		$time = intval($time);
		if (empty($time)) { $time = NOW; }
		if (empty($lid) || !is_string($lid)) { return false; }
		$is_active = ($is_active == true) ? true : false;
		$parent_nid = intval($parent_nid);
		if (empty($parent_nid)) { $parent_nid = false; }
		$nextID = lunaDB::nextID(luna::get_ini('DBtables', 'NODES'));
		if (empty($nextID)) { throw new lunaException(_('Error: cannot allocate a node id.'), PEAR_LOG_ERR); }
		$res = lunaDB::query('
			INSERT INTO
				'.luna::get_ini('DBtables', 'NODES').'
				(nid, lid, tid, is_active, parent_nid)
			VALUES
				(
					'.lunaDB::quote($nextID).',
					'.lunaDB::quote($lid).',
					(
						SELECT
							id
						FROM
							'.luna::get_ini('DBtables', 'CLASSES').'
						WHERE
							lid = '.lunaDB::quote($type1).'
					),
					'.lunaDB::quote($is_active).',
					'.lunaDB::quote($parent_nid).'
				)
		');
		$this->insert_action($nextID, $time);
		lunaGraph::rdf_sync_node($nextID);
		return $nextID;
	}
	// }}}
	// {{{ update()
	/**
	 * @param int|false $nid
	 * @param string|false $lid
	 * @param int|bool $is_active
	 * @param int|false $parent_nid
	 * @return int|false
	 */
	public function update($nid = false, $lid = false, $is_active = false, $parent_nid = false): int|false {
		if (empty($nid) || !is_integer(intval($nid))) { return false; }
		if (empty($lid) || !is_string($lid)) { return false; }
		// URI policy (roadmap decision #1, "forbid slug edits"): the lid IS the
		// resource's identity — <base/id/{lid}> — and must be frozen. Refuse any
		// change to it rather than silently breaking every link and owl:sameAs;
		// a rename is create-new + delete-old. Applies to every node type (page
		// slugs, user emails, …) since all share the /id/{lid} identity scheme.
		$cur = lunaDB::query('SELECT lid FROM '.luna::get_ini('DBtables', 'NODES').' WHERE nid = '.lunaDB::quote(intval($nid)));
		$curlid = ($cur && ($crow = $cur->fetchRow())) ? $crow->lid : false;
		if ($cur) { $cur->free(); }
		if ($curlid !== false && $curlid !== $lid) {
			lunaLog::log('Refused immutable-slug change on node '.intval($nid).': "'.$curlid.'" -> "'.$lid.'"', PEAR_LOG_WARNING);
			return false;
		}
		$is_active = ($is_active == true) ? true : false;
		$parent_nid = intval($parent_nid);
		if (empty($parent_nid)) { $parent_nid = false; }
		$res = lunaDB::query('
			UPDATE
				'.luna::get_ini('DBtables', 'NODES').'
			SET
				lid = '.lunaDB::quote($lid).',
				is_active = '.lunaDB::quote($is_active).',
				parent_nid = '.lunaDB::quote($parent_nid).'
			WHERE
				nid = '.lunaDB::quote($nid).'
		');
		$this->insert_action($nid);
		lunaGraph::rdf_sync_node($nid);
		return $nid;
	}
	// }}}
	// {{{ delete()
	/**
	 * @param int|false $nid
	 * @return bool
	 */
	public function delete($nid = false): bool {
		if (empty($nid) || !is_integer(intval($nid))) { return false; }
		$nid = intval($nid);
		// Admin-lockout defense-in-depth: never delete a structural node the admin tier
		// depends on (the admin pages/mods, group_admin/level_admin, …), no matter which
		// handler asks. See luna::$protected_lids / luna::lid_is_protected().
		$res = lunaDB::query('SELECT lid FROM '.luna::get_ini('DBtables', 'NODES').' WHERE nid = '.lunaDB::quote($nid).' LIMIT 1');
		$row = $res->fetchRow();
		if (isset($row->lid) && luna::lid_is_protected($row->lid)) {
			lunaLog::log('Refused to delete protected node “'.$row->lid.'” (#'.$nid.').', PEAR_LOG_WARNING);
			return false;
		}
		// Orphan guard: never delete a node another node still files itself under. Nothing here
		// cascades or re-parents, so the children would survive carrying a parent_nid that points
		// at a row that no longer exists — and that state is silent rather than loud: the admin
		// listing still shows those pages (it reads the node rows), while every one of their URLs
		// 404s, because calculate_aliases() builds paths by walking down from the root and never
		// reaches a subtree whose link to the root is broken. A resync does not help; it faithfully
		// reprojects the damage. Re-parent or delete the children first. Checked at full scope (see
		// first_child_nid()) so a child outside the requester's view still counts; the admin pages
		// form pre-checks the same condition for a friendlier message.
		$child_nid = $this->first_child_nid($nid);
		if ($child_nid) {
			lunaLog::log('Refused to delete node #'.$nid.': it would orphan child #'.$child_nid.'.', PEAR_LOG_WARNING);
			return false;
		}
		lunaGraph::rdf_delete_node($nid);
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'NODES').'
			WHERE
				nid = '.lunaDB::quote($nid).'
		');
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'NODES_MAP').'
			WHERE
				nid1 = '.lunaDB::quote($nid).'
				OR nid2 = '.lunaDB::quote($nid).'
		');
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'ACTIONS').'
			WHERE
				nid = '.lunaDB::quote($nid).'
		');
		return true;
	}
	// }}}
	// {{{ link()
	/**
	 * @param int|false $nodeid1
	 * @param mixed $nodeid2
	 * @return bool
	 */
	public function link($nodeid1 = false, $nodeid2 = false): bool {
		// Force both endpoints numeric before they reach the SQL: link() is called with raw $_POST
		// arrays from the admin/editor mods, and the id gets interpolated into INSERT ... VALUES
		// below. The old `is_integer(intval($x))` guard was a no-op (intval() always returns an int),
		// so a crafted string endpoint was an authenticated SQL-injection vector. Cast, don't check.
		$nodeid1 = intval($nodeid1);
		if (empty($nodeid1)) { return false; }
		if (empty($nodeid2)) { return false; }
		$sql = '';
		if (is_array($nodeid2)) {
			foreach ($nodeid2 as $nid) {
				$nid = intval($nid);
				if (empty($nid)) { return false; }
				$sql .= '('.$nid.', '.$nodeid1.'), ('.$nodeid1.', '.$nid.'),';
			}
			$sql = substr($sql, 0, -1);
		} else {
			$nodeid2 = intval($nodeid2);
			if (empty($nodeid2)) { return false; }
			$sql .= '('.$nodeid2.', '.$nodeid1.'), ('.$nodeid1.', '.$nodeid2.')';
		}
		$res = lunaDB::query('
				INSERT INTO
					'.luna::get_ini('DBtables', 'NODES_MAP').'
					(nid1, nid2)
				VALUES
					'.$sql.'
			');
		// RDF write-through: re-project both endpoints of the new edge(s).
		$rdf_sync = [intval($nodeid1)];
		if (is_array($nodeid2)) { foreach ($nodeid2 as $rdf_nid) { $rdf_sync[] = intval($rdf_nid); } } else { $rdf_sync[] = intval($nodeid2); }
		foreach (array_unique($rdf_sync) as $rdf_nid) { lunaGraph::rdf_sync_node($rdf_nid); }
		return true;
	}
	// }}}
	// {{{ unlink()
	/**
	 * @param int|false $nodeid
	 * @param string|false $type1
	 * @return bool
	 */
	public function unlink($nodeid = false, $type1 = false): bool {
		if (empty($nodeid) || !is_integer(intval($nodeid))) { return false; }
		if (empty($type1) || !is_string($type1)) { return false; }
		// the nodes about to be unlinked, captured before the edges go (to re-sync after)
		$rdf_targets = [];
		$rdf_res = lunaDB::query('SELECT DISTINCT n2.nid AS nid FROM '.luna::get_ini('DBtables', 'NODES_MAP').' m JOIN '.luna::get_ini('DBtables', 'NODES').' n2 ON n2.nid = m.nid2 JOIN '.luna::get_ini('DBtables', 'CLASSES').' t2 ON t2.id = n2.tid WHERE m.nid1 = '.lunaDB::quote($nodeid).' AND t2.lid = '.lunaDB::quote($type1));
		if ($rdf_res) { while ($rdf_r = $rdf_res->fetchRow()) { $rdf_targets[] = intval($rdf_r->nid); } $rdf_res->free(); }
		$res = lunaDB::query('
			DELETE FROM
				'.luna::get_ini('DBtables', 'NODES_MAP').'
			WHERE
				(
					nid1 = '.lunaDB::quote($nodeid).'
					AND nid2 IN
						(
							SELECT
								nid
							FROM
								'.luna::get_ini('DBtables', 'NODES').'
							WHERE
								tid =
									(
										SELECT id FROM '.luna::get_ini('DBtables', 'CLASSES').' WHERE lid = '.lunaDB::quote($type1).' LIMIT 1
									)
						)
				)
				OR
				(
					nid2 = '.lunaDB::quote($nodeid).'
					AND nid1 IN
						(
							SELECT
								nid
							FROM
								'.luna::get_ini('DBtables', 'NODES').'
							WHERE
								tid =
									(
										SELECT id FROM '.luna::get_ini('DBtables', 'CLASSES').' WHERE lid = '.lunaDB::quote($type1).' LIMIT 1
									)
						)
				)
		');
		foreach (array_unique($rdf_targets) as $rdf_nid) { lunaGraph::rdf_sync_node($rdf_nid); }
		lunaGraph::rdf_sync_node(intval($nodeid));
		return true;
	}
	// }}}
	// {{{ insert_action()
	/**
	 * @param int $nid
	 * @param int|false $time
	 * @return bool
	 */
	public function insert_action(int $nid, $time = false): bool {
		$nid = intval($nid);
		if ($nid < 1) { return false; }
		$time = intval($time);
		if (empty($time)) { $time = NOW; }
		$res = lunaDB::query('
			INSERT INTO
				'.luna::get_ini('DBtables', 'ACTIONS').'
				(
					nid,
					unid,
					ntime
				)
			VALUES
				(
					'.lunaDB::quote($nid).',
					'.lunaDB::quote(luna::$session->user->nid).',
					'.lunaDB::quote($time).'
				)
		');
		return true;
	}
	// }}}
	// {{{ lid_is_taken()
	/**
	 * @param string|false $lid
	 * @param int|false $nid
	 * @return mixed
	 */
	public function lid_is_taken($lid = false, $nid = false) {
		if (empty($lid)) { return true; }
		$nid = intval($nid);
		$sql = '';
		if (!empty($nid)) { $sql = ' AND nid <> '.lunaDB::quote($nid); }
		$res = lunaDB::query('
			SELECT
				nid
			FROM
				'.luna::get_ini('DBtables', 'NODES').'
			WHERE
				lid = '.lunaDB::quote($lid).$sql.'
			LIMIT
				1
		');
		$row = $res->fetchRow();
		$res->free();
		if (empty($row)) { return false; } else { return $row->nid; }
	}
	// }}}
	// {{{ calculate_aliases()
	/**
	 * @param array|false $nodes
	 * @param int|false $nid
	 * @return array|false
	 */
	public function calculate_aliases($nodes = false, int|false $nid = false): array|false {
		if (empty($nodes) || !is_array($nodes)) { return false; }
		// if no nid is given
		if ($nid < 1) {
			// then walk throught the array and calculate the alias of each node
			foreach ($nodes as $node) {
				if (empty($node) || !is_array($node)) { return false; }
				if (!$nodes = $this->calculate_aliases($nodes, $node[$this->conf['ns']['luna'].'nid'][0]['value'])) { return false; }
			}
		} else {
			// We have a nid, check if the node exists
			if (!isset($nodes[$this->node_path.'/'.$nid])) { return false; }
			// store the node’s uri, we’ll need it later
			$node_uri = $this->node_path.'/'.$nid;
			// do the same for its parent
			$parent_uri = isset($nodes[$node_uri][$this->conf['ns']['schema'].'isPartOf'][0]['value']) ? $nodes[$node_uri][$this->conf['ns']['schema'].'isPartOf'][0]['value'] : '';
			// and also grab its nid, we might need it
			$parent_nid = isset($nodes[$parent_uri][$this->conf['ns']['luna'].'nid'][0]['value']) ? $nodes[$parent_uri][$this->conf['ns']['luna'].'nid'][0]['value'] : '';
			// if the node’s uri is the same as its parent’s, then we just hit the root page.
			if ($parent_uri == $node_uri) {
				// that means: empty alias
				$nodes[$node_uri][$this->conf['ns']['luna'].'alias'][0]['value'] = '';
				// store "root"
				$this->aliases["root"][$this->conf['ns']['luna'].'nid'][0]['value'] = $nid;
				// else, if the parent exists
			} elseif (isset($nodes[$parent_uri])) {
				// grab the literal identifier of the parent
				$parent_lid = $nodes[$parent_uri][$this->conf['ns']['luna'].'lid'][0]['value'];
				// check if the parent is the root page
				if ($parent_lid == 'root') {
					// if yes, then the alias we’re looking for is just the literal identifier of the current node.
					$nodes[$node_uri][$this->conf['ns']['luna'].'alias'][0]['value'] = $nodes[$node_uri][$this->conf['ns']['luna'].'lid'][0]['value'];
					// store it
					$this->aliases[$nodes[$node_uri][$this->conf['ns']['luna'].'lid'][0]['value']][$this->conf['ns']['luna'].'nid'][0]['value'] = $nid;
					// return everything
					return $nodes;
				} else {
					// if the parent is not the root page, then what we need to do first here is calculate the parent’s alias
					if (!$nodes = $this->calculate_aliases($nodes, $parent_nid)) { return false; }
					$parent_alias = $nodes[$parent_uri][$this->conf['ns']['luna'].'alias'][0]['value'];
					if (!empty($parent_alias)) { $parent_alias .= '/'; }
					$nodes[$node_uri][$this->conf['ns']['luna'].'alias'][0]['value'] = $parent_alias.$nodes[$node_uri][$this->conf['ns']['luna'].'lid'][0]['value'];
					$this->aliases[$parent_alias.$nodes[$node_uri][$this->conf['ns']['luna'].'lid'][0]['value']][$this->conf['ns']['luna'].'nid'][0]['value'] = $nid;
				}
			}
		}
		return $nodes;
	}
	// }}}
	// {{{ transform()
	/**
	 * Render the current graph through a stylesheet.
	 *
	 * The pipeline itself lives in lunaRender; the model hands it a snapshot and gets a
	 * document back. Kept here as the entry point so the front controller's call is unchanged.
	 *
	 * @param string|false $xslfile
	 * @return mixed the rendered document, or false when the stylesheet cannot be loaded
	 */
	public function transform(string|false $xslfile = false) {
		$renderer = new lunaRender($this->index, $this->conf, $this->node_path);
		return $renderer->transform($xslfile);
	}
	// }}}
}
// }}}
