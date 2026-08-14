<?php

/**
 * The graph layer — SPARQL transport and RDF write-through.
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
 *
 * Everything that talks to the triplestore: the authenticated SELECT/UPDATE transport, the
 * per-node write-through that keeps Oxigraph reconciled with MySQL, and the small typed
 * helpers that build RDF terms. MySQL stays the system of record; this is the projection of
 * it, and it is best-effort by design — a failed write-through must not fail a page.
 *
 * Loading is deliberately NOT here. lunaModel::load_texts_sparql() and load_nodes_sparql()
 * call sparql_select() and merge the bindings into the model's own index; they are loaders
 * that happen to read over SPARQL, not transport. Keeping the split at that line is what lets
 * this file be read without knowing how the in-memory model is shaped.
 *
 * Every method is static: none of them needed model state, which is the clearest evidence
 * that they were only ever sharing a file with it.
 */

// {{{
class lunaGraph {
	// {{{ sparql_auth_header()
	/**
	 * Build the HTTP basic-auth header the app presents to the SPARQL proxy.
	 * Empty when no credentials are configured (so a bare, unauthenticated
	 * endpoint still works) — see SPARQL_AUTH_USER/PASS in luna.php.
	 *
	 * @return string a CRLF-terminated Authorization header, or ''
	 */
	private static function sparql_auth_header(): string {
		if (defined('SPARQL_AUTH_USER') && SPARQL_AUTH_USER !== '') {
			return 'Authorization: Basic '.base64_encode(SPARQL_AUTH_USER.':'.(defined('SPARQL_AUTH_PASS') ? SPARQL_AUTH_PASS : ''))."\r\n";
		}
		return '';
	}
	// }}}
	// {{{ sparql_select()
	/**
	 * Run a SPARQL SELECT against the Ontop endpoint (Phase A) and return the
	 * result bindings. The read path goes *through* SPARQL rather than the
	 * hand-written joins — see docs/linked-data.md.
	 *
	 * @param string $query
	 * @return array bindings (each a map of var => {type,value})
	 */
	public static function sparql_select(string $query): array {
		if (defined('SPARQL_ENABLED') && !SPARQL_ENABLED) { return []; }
		if (!defined('SPARQL_ENDPOINT')) { return []; }
		$url = SPARQL_ENDPOINT.'?query='.rawurlencode($query);
		$ctx = stream_context_create(['http' => [
			'method' => 'GET',
			'header' => "Accept: application/sparql-results+json\r\n".self::sparql_auth_header(),
			'timeout' => 5
		]]);
		$json = @file_get_contents($url, false, $ctx);
		if ($json === false) { return []; }
		$data = json_decode($json, true);
		return (isset($data['results']['bindings'])) ? $data['results']['bindings'] : [];
	}
	// }}}
	// {{{ sparql_update()
	/**
	 * Send a SPARQL UPDATE to the triplestore (Oxigraph) — the write counterpart
	 * to sparql_select(). Best-effort: returns false (no exception) if the update
	 * endpoint is unset or unreachable, so a failed mirror never breaks a save.
	 * Pushing content writes into RDF is step 1 toward an RDF-native store; see
	 * docs/linked-data.md.
	 *
	 * @param string $update a SPARQL UPDATE request
	 * @return bool true on a 2xx response
	 */
	public static function sparql_update(string $update): bool {
		if (defined('SPARQL_ENABLED') && !SPARQL_ENABLED) { return false; }
		if (!defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return false; }
		$ctx = stream_context_create(['http' => [
			'method'        => 'POST',
			'header'        => "Content-Type: application/sparql-update\r\n".self::sparql_auth_header(),
			'content'       => $update,
			'timeout'       => 5,
			'ignore_errors' => true
		]]);
		$res = @file_get_contents(SPARQL_UPDATE_ENDPOINT, false, $ctx);
		if (isset($http_response_header)) {
			foreach ($http_response_header as $h) { if (preg_match('#^HTTP/\S+\s+2\d\d#', $h)) { return true; } }
		}
		return false;
	}
	// }}}
	// {{{ sparql_literal()
	/**
	 * Escape a value for use inside a double-quoted SPARQL string literal.
	 * @param string $s
	 * @return string
	 */
	public static function sparql_literal(string $s): string {
		return str_replace(
			['\\', '"', "\n", "\r", "\t"],
			['\\\\', '\\"', '\\n', '\\r', '\\t'],
			(string) $s
		);
	}
	// }}}
	// {{{ rdf_sync_node()
	/**
	 * Project a node's current relational state into the triplestore via a SPARQL
	 * UPDATE — the generic, RDF-native write-through. Replaces the whole description
	 * of <base/id/{lid}> with the triples the R2RML mapping (semantic/ontop/
	 * mapping.ttl) derives for that node, so anything mutated through insert() /
	 * update() / link() / unlink() is mirrored into Oxigraph in real time.
	 * Best-effort: a missing or unreachable endpoint is a no-op, never a failure.
	 * See docs/linked-data.md.
	 *
	 * @param int|false $nid
	 * @return bool
	 */
	public static function rdf_sync_node(int|false $nid = false): bool {
		if (empty($nid) || !defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return false; }
		$nid = intval($nid);
		if ($nid < 1) { return false; }
		// the node, its type slug and its parent slug (mirrors the mapping's joins)
		$res = lunaDB::query('
			SELECT n.lid AS lid, t.lid AS type1, n.is_active AS is_active, pn.lid AS parent_lid
			FROM '.luna::get_ini('DBtables', 'NODES').' n
			JOIN '.luna::get_ini('DBtables', 'CLASSES').' t ON t.id = n.tid
			LEFT JOIN '.luna::get_ini('DBtables', 'NODES').' pn ON pn.nid = n.parent_nid
			WHERE n.nid = '.lunaDB::quote($nid).'
		');
		if (!$res || !($row = $res->fetchRow())) { return false; }
		$res->free();
		$uri = self::rdf_uri($row->lid);
		$po  = [];
		switch ($row->type1) {
			case 'page':
				$po[] = 'a schema:WebPage';
				// luna:lid, NOT schema:name. The slug is a routing key — language-independent,
				// permanent, the segment /id/{slug} is built from — and schema.org's name is a
				// display label. Writing the slug into schema:name made one predicate mean two
				// things depending on which surface you read: the store answered "edit_texts", the
				// published document answered "Edit texts", for the same subject. That is roadmap
				// decision #9, and it is why the representation could not be CONSTRUCT-backed —
				// a CONSTRUCT over the store would have regressed every published page name to its
				// slug. Now the store asserts only what it holds, and asserts it under its own term.
				$po[] = 'luna:lid '.self::rdf_str($row->lid);
				$po[] = 'schema:identifier '.self::rdf_int($nid);
				$po[] = 'luna:isActive '.self::rdf_int($row->is_active);
				if (!empty($row->parent_lid)) { $po[] = 'schema:isPartOf '.self::rdf_uri($row->parent_lid); }
				foreach (self::rdf_edges($nid, ['text', 'level']) as $e) {
					if ($e->type1 == 'text')  { $po[] = 'schema:hasPart '.self::rdf_uri($e->lid); }
					if ($e->type1 == 'level') { $po[] = 'luna:level '.self::rdf_uri($e->lid); }
				}
				break;
			case 'text':
				$po[] = 'a schema:Article';
				$po[] = 'schema:identifier '.self::rdf_int($nid);
				// Every translation, each as a language-tagged literal on the same subject. The
				// graph is no longer a lossy mirror of the text table: what MySQL holds, RDF holds.
				// schema:inLanguage is kept alongside the tags — redundant for a reader that
				// understands tags, but it is what the R2RML mapping and the existing SPARQL read
				// path look for, and dropping it would break them for no gain.
				foreach (self::rdf_text_rows($nid) as $t) {
					$lang = isset($t->lang) ? (string) $t->lang : '';
					$code = lunaTools::content_language($lang);
					$body = lunaTools::markdown_to_text((string) $t->content);
					$po[] = 'schema:headline '.self::rdf_lang_str((string) $t->title, $lang);
					$po[] = 'schema:articleBody '.self::rdf_lang_str($body, $lang);
					$po[] = 'luna:content '.self::rdf_lang_str((string) $t->content, $lang);
					if ($code !== '') { $po[] = 'schema:inLanguage '.self::rdf_str($code); }
				}
				foreach (self::rdf_edges($nid, ['page']) as $e) { $po[] = 'schema:isPartOf '.self::rdf_uri($e->lid); }
				break;
			case 'level':
				$po[] = 'schema:identifier '.self::rdf_int($nid);
				$po[] = 'luna:lid '.self::rdf_str($row->lid);   // routing key, not a display name — see 'page'
				$po[] = 'luna:isActive '.self::rdf_int($row->is_active);
				break;
			case 'user':
				$po[] = 'a foaf:Person';
				if ($u = self::rdf_user_row($nid)) { $po[] = 'foaf:name '.self::rdf_str(trim($u->firstname.' '.$u->lastname)); }
				break;
			default:
				// group, mod, … — a minimal generic projection
				$po[] = 'luna:lid '.self::rdf_str($row->lid);   // routing key, not a display name — see 'page'
				$po[] = 'schema:identifier '.self::rdf_int($nid);
				$po[] = 'luna:isActive '.self::rdf_int($row->is_active);
		}
		$update = self::rdf_prefixes()
			.'DELETE { '.$uri.' ?p ?o } '
			.'INSERT { '.$uri.' '.implode(' ; ', $po).' } '
			.'WHERE  { OPTIONAL { '.$uri.' ?p ?o } }';
		return self::sparql_update($update);
	}
	// }}}
	// {{{ rdf_delete_node()
	/**
	 * Remove a node from the triplestore: drop every triple that mentions its
	 * resource URI, as subject or as object. Call it *before* the relational
	 * delete, while the lid can still be resolved. Best-effort. See rdf_sync_node().
	 *
	 * @param int|false $nid
	 * @return bool
	 */
	public static function rdf_delete_node(int|false $nid = false): bool {
		if (empty($nid) || !defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return false; }
		$nid = intval($nid);
		if ($nid < 1) { return false; }
		$res = lunaDB::query('SELECT lid FROM '.luna::get_ini('DBtables', 'NODES').' WHERE nid = '.lunaDB::quote($nid));
		if (!$res || !($row = $res->fetchRow())) { return false; }
		$res->free();
		$uri = self::rdf_uri($row->lid);
		$update = 'DELETE WHERE { '.$uri.' ?p ?o } ; DELETE WHERE { ?s ?p '.$uri.' }';
		return self::sparql_update($update);
	}
	// }}}
	// {{{ rdf_clear()
	/**
	 * Drop every triple from the triplestore (default graph). The destructive half of
	 * a full rebuild — see rdf_resync_all($prune = true). Best-effort: a missing or
	 * unreachable endpoint is a no-op, never a failure.
	 *
	 * @return bool
	 */
	public static function rdf_clear(): bool {
		if (!defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return false; }
		return self::sparql_update('DELETE WHERE { ?s ?p ?o }');
	}
	// }}}
	// {{{ rdf_resync_all()
	/**
	 * Re-project every node from MySQL into the triplestore — the pure-PHP
	 * bootstrap / repair of the graph, replacing the Ontop "materialise" step.
	 * Reconciles MySQL → graph (every relational node is upserted). With
	 * $prune = false (default) it does not remove graph-only orphans; pass
	 * $prune = true for a full REBUILD that clears the store first (see rdf_clear()),
	 * so orphans are dropped too. Run it to seed Oxigraph, to reconcile after the
	 * best-effort dual-write drifts, or via bin/resync-triplestore.php. See
	 * rdf_sync_node().
	 *
	 * @param bool $prune clear the whole graph first (full rebuild) rather than upsert-only
	 * @return int the number of nodes synced
	 */
	public static function rdf_resync_all($outbound = [], bool $prune = false): int {
		if (!defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return 0; }
		// A pruning resync is a full REBUILD: drop the whole graph first so triples for
		// nodes deleted out-of-band (e.g. a test's raw-SQL teardown) don't survive as
		// orphans. Default ($prune = false) keeps the non-destructive upsert behaviour.
		if ($prune) { self::rdf_clear(); }
		$nids = [];
		$res = lunaDB::query('SELECT nid FROM '.luna::get_ini('DBtables', 'NODES').' ORDER BY nid');
		if ($res) { while ($row = $res->fetchRow()) { $nids[] = intval($row->nid); } $res->free(); }
		$n = 0;
		foreach ($nids as $nid) { if (self::rdf_sync_node($nid)) { $n++; } }
		// Load the operator-curated outbound links (semantic/links.ttl) so the
		// triplestore matches the published /data + JSON-LD projections.
		self::rdf_load_links($outbound);
		return $n;
	}
	// }}}
	// {{{ rdf_load_links()
	/**
	 * Mirror the curated outbound links (outbound_index()) into the triplestore via a
	 * SPARQL `INSERT DATA`, so a direct SPARQL query sees the same owl:sameAs /
	 * rdfs:seeAlso / … the /data + JSON-LD projections carry. Idempotent (RDF set
	 * semantics), best-effort. Called at the end of rdf_resync_all().
	 *
	 * @return bool
	 */
	public static function rdf_load_links($out = [], $conf = []): bool {
		if (!defined('SPARQL_UPDATE_ENDPOINT') || !SPARQL_UPDATE_ENDPOINT) { return false; }
		$out = is_array($out) ? $out : [];
		if (empty($out)) { return false; }
		require_once LUNAPATH.'luna.lib/arc/ARC2.php';
		$conf = is_array($conf) ? $conf : [];
		$ser = ARC2::getNtriplesSerializer($conf);
		$nt = $ser->getSerializedIndex($out);
		if (trim($nt) === '') { return false; }
		return self::sparql_update('INSERT DATA { '.$nt.' }');
	}
	// }}}
	// {{{ rdf_prefixes()
	/**
	 * The SPARQL PREFIX preamble shared by every write-through update.
	 * @return string
	 */
	public static function rdf_prefixes(): string {
		return 'PREFIX schema: <https://schema.org/> '
			.'PREFIX foaf: <http://xmlns.com/foaf/0.1/> '
			.'PREFIX luna: <'.lunaModel::LUNA_NS.'> '
			.'PREFIX xsd: <http://www.w3.org/2001/XMLSchema#> ';
	}
	// }}}
	// {{{ sparql_reads()
	/**
	 * Whether the read path (routing / ACL / texts) should be served from SPARQL.
	 * Defaults to the SPARQL_READS constant; `?sparql=1` forces it on for a single
	 * request and `?sparql=0` forces it off (the SQL path), regardless of default.
	 *
	 * @return bool
	 */
	public static function sparql_reads(): bool {
		// per-request override read straight from $_GET: ?sparql=1 forces SPARQL,
		// ?sparql=0 forces SQL. The (bool) cast copes both with the raw '0' string
		// and with the boolean false that sanitize_inputs() turns '0' into (PHP's
		// empty('0') is true, so lunaTools::request() can't see the opt-out at all).
		if (defined('SPARQL_ENABLED') && !SPARQL_ENABLED) { return false; }
		if (isset($_GET['sparql'])) { return (bool) $_GET['sparql']; }
		return defined('SPARQL_READS') ? (bool) SPARQL_READS : false;
	}
	// }}}
	// {{{ rdf_uri()
	/**
	 * Build a resource URI <base/id/{lid}> — the same identity scheme as the
	 * JSON-LD projection and the R2RML mapping.
	 * @param string $lid
	 * @return string an angle-bracketed absolute IRI
	 */
	public static function rdf_uri(string $lid): string {
		return '<'.rtrim(luna::$site_uri, '/').'/id/'.rawurlencode($lid).'>';
	}
	// }}}
	// {{{ rdf_str()
	/**
	 * A node value as a SPARQL string literal.
	 * @param string $s
	 * @return string
	 */
	public static function rdf_str(string $s): string {
		return '"'.self::sparql_literal($s).'"';
	}
	// }}}
	// {{{ rdf_lang_str()
	/**
	 * A node value as a SPARQL language-tagged string literal — "Bonjour"@fr.
	 *
	 * This is the mechanism RDF already has for exactly the problem this CMS was solving badly.
	 * Before, a text node carried ONE translation plus a `schema:inLanguage "fr"` annotation beside
	 * an untagged literal, which is why the mirrored graph was lossy: the other translations had
	 * nowhere to go. Tagged literals let every translation live on the same subject under the same
	 * predicate, told apart by the tag, which is what makes a language-aware CONSTRUCT possible at
	 * all.
	 *
	 * The tag is the two-letter content code, not the interface locale: we store "fr", so we assert
	 * `@fr` and not `@fr-FR`, because we do not hold a region and must not invent one. An empty or
	 * unusable code degrades to a plain literal rather than emitting the syntactically invalid `@`.
	 *
	 * @param string $s    the literal value
	 * @param string $lang any language string; reduced via lunaTools::content_language()
	 * @return string
	 */
	public static function rdf_lang_str(string $s, string $lang): string {
		$code = lunaTools::content_language($lang);
		if ($code === '' || !preg_match('/^[a-z]{2}$/', $code)) { return self::rdf_str($s); }
		return '"'.self::sparql_literal($s).'"@'.$code;
	}
	// }}}
	// {{{ rdf_int()
	/**
	 * A node value as a typed SPARQL integer literal, matching the datatype the
	 * R2RML mapping infers for numeric columns (nid, is_active).
	 * @param mixed $v
	 * @return string
	 */
	public static function rdf_int($v): string {
		return '"'.intval($v).'"^^xsd:integer';
	}
	// }}}
	// {{{ rdf_edges()
	/**
	 * The slugs and type-slugs of the nodes linked from $nid (as nid1) whose type
	 * is one of $types — the edge rows the mapping turns into hasPart / level /
	 * isPartOf triples.
	 * @param int $nid
	 * @param array $types
	 * @return array rows of ['lid' => ..., 'type1' => ...]
	 */
	public static function rdf_edges(int $nid, array $types): array {
		if (empty($types)) { return []; }
		$in = [];
		foreach ($types as $t) { $in[] = lunaDB::quote($t); }
		$res = lunaDB::query('
			SELECT DISTINCT n2.lid AS lid, t2.lid AS type1
			FROM '.luna::get_ini('DBtables', 'NODES_MAP').' m
			JOIN '.luna::get_ini('DBtables', 'NODES').' n2 ON n2.nid = m.nid2
			JOIN '.luna::get_ini('DBtables', 'CLASSES').' t2 ON t2.id = n2.tid
			WHERE m.nid1 = '.lunaDB::quote($nid).' AND t2.lid IN ('.implode(', ', $in).')
		');
		$out = [];
		if ($res) { while ($r = $res->fetchRow()) { $out[] = $r; } $res->free(); }
		return $out;
	}
	// }}}
	// {{{ rdf_text_rows()
	/**
	 * EVERY luna_texts row for a text node, one per language, ordered by language so the emitted
	 * triples are stable between runs (the render/resync diffs are only evidence if the output is
	 * deterministic).
	 *
	 * This replaces rdf_text_row(), which returned exactly one row and so made the triplestore a
	 * lossy mirror by construction: whichever translation it picked, the others simply did not
	 * exist in RDF. Its pick was worse than arbitrary — it tried to prefer the request language by
	 * testing `$row->lang == luna::$lang`, comparing a stored "fr" against an interface locale
	 * "fr-FR", so the test never once succeeded and the first row always won. Both problems
	 * disappear here: the caller emits all rows as language-tagged literals, and nothing has to
	 * choose.
	 *
	 * @param int $nid
	 * @return array list of rows (title, lang, content); empty when the node has no text
	 */
	public static function rdf_text_rows(int $nid): array {
		$res = lunaDB::query('
			SELECT
				title, lang, content
			FROM
				'.luna::get_ini('DBtables', 'TEXTS').'
			WHERE
				nid = '.lunaDB::quote($nid).'
			ORDER BY
				lang
		');
		$out = [];
		if ($res) { while ($r = $res->fetchRow()) { $out[] = $r; } $res->free(); }
		return $out;
	}
	// }}}
	// {{{ rdf_user_row()
	/**
	 * The firstname/lastname row for a user node, or false.
	 * @param int $nid
	 * @return mixed
	 */
	public static function rdf_user_row(int $nid) {
		$res = lunaDB::query('SELECT firstname, lastname FROM '.luna::get_ini('DBtables', 'USERS').' WHERE nid = '.lunaDB::quote($nid));
		if (!$res) { return false; }
		$r = $res->fetchRow();
		$res->free();
		return $r;
	}
	// }}}
}
// }}}
