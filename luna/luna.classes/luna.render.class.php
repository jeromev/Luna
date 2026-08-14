<?php

/**
 * Rendering — the XSLT pipeline that turns the model's graph into a document.
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
 *
 * The model holds the graph; this turns one into HTML. Keeping them apart matters here more
 * than usual, because the projection this class performs is deliberately NOT the published
 * one: project_to_schema() renders luna:content Markdown into a transient ui: literal for the
 * view, which must never reach the published graph. serialize(), to_jsonld() and
 * rdf_sync_node() all project from the model's own index instead, which is why ?output=*
 * stays Markdown. Two projections living in one class is how that distinction gets lost.
 *
 * Constructed with a snapshot of the model's state rather than a reference to the model, so
 * rendering cannot write back into the graph it is rendering.
 */

// {{{
class lunaRender {
	/**
	 * The graph to render, ARC2-indexed by node URI.
	 * @var array
	 */
	private $index = [];
	/**
	 * Namespace and serialiser configuration, as the model built it.
	 * @var array
	 */
	private $conf = [];
	/**
	 * URI prefix the working (nid-keyed) subjects carry.
	 * @var string
	 */
	private $node_path = '';
	/**
	 * The compiled stylesheet.
	 * @var DomDocument|null
	 */
	private $xsl = null;
	/**
	 * The serialised graph the stylesheet is applied to.
	 * @var DomDocument|null
	 */
	private $dom = null;
	/**
	 * @var XsltProcessor|null
	 */
	private $xslprocessor = null;
	// {{{ constructor
	/**
	 * @param array $index
	 * @param array $conf
	 * @param string $node_path
	 */
	public function __construct($index = [], $conf = [], string $node_path = '') {
		$this->index = is_array($index) ? $index : [];
		$this->conf = is_array($conf) ? $conf : [];
		$this->node_path = (string) $node_path;
	}
	// }}}
	// {{{ load_xsl
	/**
	 * @param string|false $file
	 * @return bool
	 */
	private function load_xsl($file = false): bool {
		if (empty($file) || !is_string($file) || !file_exists($file)) { return false; }
		$this->xsl = new DomDocument();
		$this->xsl->load($file);
		$this->xsl->preserveWhiteSpace = false;
		return true;
	}
	// }}}
	// {{{ project_to_schema()
	/**
	 * Project the working (nid-keyed) in-memory model to slug identity before it is
	 * serialised for the XSLT: every <base/node/{nid}> subject and every node-valued
	 * @rdf:resource becomes <base/id/{lid}>, so the renderer consumes the same /id/{slug}
	 * graph as the triplestore and ?output=*. The loaders keep building in nid-space (the
	 * relational FKs are nid-based); nid survives as the luna:nid property the admin forms
	 * post. The UI render-model and any non-node subjects are left untouched.
	 *
	 * @param array $index an ARC2 index keyed by node URI
	 * @return array the same graph, re-keyed on /id/{slug}
	 */
	private function project_to_schema(array $index): array {
		$luna   = $this->conf['ns']['luna'];
		$schema = $this->conf['ns']['schema'];
		$rdfs   = $this->conf['ns']['rdfs'];
		// reuse standard schema.org predicates where one exists (luna: kept only for the
		// genuinely app-specific: lid/slug, isActive, level, alias).
		$rdf    = $this->conf['ns']['rdf'];
		$ui     = $this->conf['ns']['ui'];
		// type-value remap: pages/articles get the standard schema.org class; group/level/mod keep
		// their luna: type (no standard equivalent); users are already foaf:Person.
		$typemap = [$luna.'page' => $schema.'WebPage', $luna.'text' => $schema.'Article'];
		$predmap = [$luna.'nid' => $schema.'identifier', $rdfs.'label' => $schema.'name', $luna.'page' => $schema.'isPartOf'];
		$base   = rtrim(luna::$site_uri, '/').'/id/';
		$prefix = $this->node_path.'/';
		$map = [];
		foreach ($index as $uri => $node) {
			if (strpos($uri, $prefix) === 0 && isset($node[$luna.'lid'][0]['value']) && $node[$luna.'lid'][0]['value'] !== '') {
				$map[$uri] = $base.rawurlencode($node[$luna.'lid'][0]['value']);
			}
		}
		if (empty($map)) { return $index; }
		$out = [];
		foreach ($index as $uri => $node) {
			$nuri = isset($map[$uri]) ? $map[$uri] : $uri;
			foreach ($node as $pred => $vals) {
				$npred = isset($predmap[$pred]) ? $predmap[$pred] : $pred;
				foreach ($vals as $v) {
					if ($pred === $rdf.'type' && isset($typemap[$v['value']])) { $v['value'] = $typemap[$v['value']]; }
					if (isset($v['type'], $v['value']) && $v['type'] === 'uri' && isset($map[$v['value']])) { $v['value'] = $map[$v['value']]; }
					$out[$nuri][$npred][] = $v;
					// luna:content holds the Markdown source; render it to HTML for the view
					// only (a transient ui: render-model literal the default template emits).
					// This never reaches the published graph — dump()/to_jsonld()/rdf_sync_node()
					// project from $this->index, not from here — so ?output=* stays Markdown.
					if ($pred === $luna.'content' && isset($v['value'])) {
						$rendered = ['value' => lunaTools::markdown($v['value']), 'type' => 'literal'];
						if (isset($v['lang'])) { $rendered['lang'] = $v['lang']; }
						$out[$nuri][$ui.'content'][] = $rendered;
					}
				}
			}
		}
		return $out;
	}
	// }}}
	// {{{ transform()
	/**
	 * @param string|false $xslfile
	 * @return mixed
	 */
	public function transform(string|false $xslfile = false) {
		$code_str = md5(serialize([$this->conf, $this->index]));
		$use_cache = (bool) luna::$cache;
		$cache_obj = $use_cache ? new lunaCache(['cacheDir' => CACHE_PATH, 'lifetime' => luna::$cache_timeout]) : null;
		if ($cache_obj !== null && ($cache_str = $cache_obj->get($code_str))) {
			$res = unserialize($cache_str, ['allowed_classes' => false]);
		} else {
			if (!$this->load_xsl($xslfile)) { return false; }
			include_once LUNAPATH.'luna.lib/arc/ARC2.php';
			$ser = ARC2::getRDFXMLSerializer($this->conf);
			$this->dom = new DomDocument();
			$this->dom->loadXML($ser->getSerializedIndex($this->project_to_schema($this->index)));
			$this->xslprocessor = new XsltProcessor();
			$this->xslprocessor->importStyleSheet($this->xsl);
			$res = $this->xslprocessor->transformToXML($this->dom);
			if ($cache_obj !== null) { $cache_obj->save(serialize($res)); }
		}
		return $res;
	}
	// }}}
}
// }}}
