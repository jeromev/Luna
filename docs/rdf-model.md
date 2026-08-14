# The RDF Model

The heart of LunarSystem is
[`lunaModel`](../luna/luna.classes/luna.model.class.php) — a singleton that holds
an **in-memory RDF triple store** for the duration of a request, projects
relational rows into it, and serialises it (to RDF/XML for XSLT, or to
XML/JSON/N-Triples for raw output).

> Within a request, the in-memory RDF graph is a *representation*, not a storage
> engine: it is rebuilt and discarded each request. Persistence is RDF-native:
> every content write also persists into the Oxigraph triplestore (via
> `rdf_sync_node()`), which is the default read source. See the "Semantic-web
> layer" note at the end of this file and [linked-data.md](linked-data.md).

## The in-memory structure

The store is a nested PHP array, `lunaModel::$index`, shaped like:

```
$index[ subject_uri ][ predicate_uri ][ n ] = array(
    'value' => <string|uri>,
    'type'  => 'uri' | 'literal' | 'bnode',
    'lang'  => <optional language tag>,
);
```

So a single "node" is one `subject_uri` key whose value is a map of predicates,
each holding an ordered list of value objects. A second array,
`lunaModel::$aliases`, maps URL paths to subject IRIs for routing.

Conceptually, a page node looks like:

```
/id/root
  schema:identifier   → "9"            (literal)
  luna:lid            → "root"         (literal)
  rdf:type            → schema:WebPage (uri)
  schema:name         → "Home" @en     (literal, lang-tagged)
  schema:isPartOf     → /id/root       (uri)
```

The integer `nid` survives only as the `schema:identifier` literal and as the
loaders' internal DB key; every subject a consumer sees is an `/id/{slug}` IRI.

## Namespaces

Configured in the constructor. The vocabulary mixes standard ontologies with one
custom namespace:

| Prefix | URI | Used for |
|---|---|---|
| `rdf` | `http://www.w3.org/1999/02/22-rdf-syntax-ns#` | `rdf:type`, descriptions |
| `rdfs` | `http://www.w3.org/2000/01/rdf-schema#` | internal `rdfs:label`, projected to `schema:name` |
| `schema` | `https://schema.org/` | content classes/predicates: `schema:WebPage`, `schema:Article`, `schema:name`, `schema:identifier`, `schema:isPartOf`/`hasPart`, `schema:headline`, `schema:articleBody`, `schema:inLanguage` |
| `foaf` | `http://xmlns.com/foaf/0.1/` | users → `foaf:Person`, `foaf:name`, `foaf:firstName`, `foaf:surName`, `foaf:mbox` |
| `owl` | `http://www.w3.org/2002/07/owl#` | declared, but no `owl:` term is used by the content model |
| `dc` | `http://purl.org/dc/elements/1.1/` | Dublin Core (metadata) |
| `dcterms` | `http://purl.org/dc/terms/` | Dublin Core terms |
| **`luna`** | **`https://jeromev.github.io/LunarSystem/ontology#`** | **app-specific CONTENT predicates** with no standard equivalent: `lid`, `isActive`, `content`, `level`, `alias`, `url`, plus user metadata (`ip`, `last-visit`, `registration-date`) and the `luna:group`/`luna:level`/`luna:mod` classes |
| **`ui`** | **`https://jeromev.github.io/LunarSystem/render#`** | **UI render-model** (chrome only, never published): `ui:vocabulary`/`data`/`request`/`pager`/`message`/`log`/`lang`/`config` + `ui:value`/`lid`/`perpage`/`total`/`link`/`selected`/`code`/… |

The `luna:` namespace string is exposed as `lunaModel::$lunaNameSpace`; the `ui:`
render namespace as the `lunaModel::LUNA_RENDER_NS` constant. Both are registered
in the constructor's `$conf['ns']` map.

> There is effectively **one** content model. At the `transform()` serialisation
> boundary, [`project_to_schema()`](../luna/luna.classes/luna.model.class.php)
> re-keys every node to an `/id/{slug}` IRI and maps the content vocabulary to
> schema.org (`luna:page`→`schema:WebPage`, `luna:text`→`schema:Article`,
> `luna:nid`→`schema:identifier`, `rdfs:label`→`schema:name`,
> `luna:page`-edge→`schema:isPartOf`), so the XSLT renders from the
> schema.org/slug graph. The **published** RDF (`?output=xml/n3/json/jsonld` and
> the triplestore, via [`build_schema_index()`](../luna/luna.classes/luna.model.class.php)
> / [`to_jsonld()`](../luna/luna.classes/luna.model.class.php)) is the same
> vocabulary, additionally rendering the `luna:content` Markdown to HTML and
> stripping it to the plain-text `schema:articleBody` projection (`luna:content`
> itself stays Markdown source). The `ui:` render-model bnodes (chrome) — plus the
> transient `ui:content` carrying the rendered HTML for the view — are the only
> triples that stay XSLT-internal and never reach the published graph. See
> [linked-data.md](linked-data.md).

## SQL → RDF projection

The relational schema (see [database-schema.md](database-schema.md)) is a generic
graph, and the model's job is to read it back as triples. The key mapping:

| SQL | RDF |
|---|---|
| `luna_nodes` row | a subject node with `schema:identifier`, `luna:lid`, `schema:name`, `luna:isActive`, `schema:isPartOf` (parent) |
| `luna_nodes.tid` → `luna_types.lid` | `rdf:type` (e.g. `schema:WebPage`, `schema:Article`) |
| `luna_nodes_map (nid1, nid2)` | a typed predicate edge between two nodes (e.g. mod→level, user→group) |
| `luna_texts` row | a `luna:content` property (lang-tagged Markdown source) on the text node |
| `luna_users` row | a `foaf:Person` with `foaf:name`, `foaf:mbox` (`mailto:`), `luna:ip`, `luna:last-visit` |
| `luna_actions` (joined) | author/timestamp metadata on content nodes |

The trick that makes a single edge table work as RDF: `load_nodes()` reconstructs
*typed* n-ary relationships by joining `luna_nodes_map` back through
`luna_nodes`/`luna_types`, so an edge `nid1→nid2` becomes
`subject luna:<type-of-nid2> object`.

## Method groups

### Building / merging the index
- `merge_index($nodes)` — merge a set of nodes into `$index`.
- `merge_nodes($a, $b)` — deep-merge two node arrays, de-duplicating by value.
- `purge_index()` — clear and rebuild.
- `calculate_aliases($nodes, $nid)` — recursively build the path→nid alias table.

### Accessors (read the graph)
- `get_node($nid, $type, $ns)`, `get_node_from_alias($alias, …)`,
  `get_page_node_from_alias($path)` — fetch nodes.
- `get_nid($node)`, `get_lid($node)`, `get_type($node)` — extract identity.
- `get_parent_node()`, `get_children_nodes()` — traverse the page hierarchy
  (via `schema:isPartOf`).
- `get_level_node($node)` — the access level a node requires.
- `get_nid_from_lid($lid)` — direct DB lookup, slug → id.

### Mutators (write the graph + DB)
- `set_property($node, $lid, $value, $ns)` — add/update a property on a node.
- `insert()`, `update()`, `delete()`, `link()`, `unlink()` — node and edge CRUD
  that writes through to `luna_nodes` / `luna_nodes_map` (used by the admin mods).

### Loaders (project SQL/PHP data into the model)
- `load_node()` / `load_nodes()` — DB rows → typed nodes + relationships.
- `load_user()` / `load_users()` — users → `foaf:Person` + groups/levels.
- `load_text()` / `load_texts()` — content blocks (lang-tagged `luna:content`).
- `load_data()`, `load_var()`, `load_request()`, `load_vocabulary()` — flatten
  PHP arrays (config, `$_REQUEST`, i18n strings) into blank-node variables under
  the `ui:` render namespace (`ui:data`/`request`/`vocabulary`/…), so the view
  layer can reach them.
- `load_messages()`, `load_pager()` — flash messages (`ui:message`) and
  pagination metadata (`ui:pager`), also built via `load_var()` under the `ui:`
  render namespace.

### Validation helpers
- `check_requested_node()`, `check_if_node_exists()`,
  `check_if_lid_is_protected()`, `check_if_lid_is_taken()` — guard rails used by
  mods before mutating the graph.

## Serialisation and rendering

Two output paths, both backed by the bundled **ARC2** RDF library
([luna/luna.lib/arc/](../luna/luna.lib/arc/)):

### `dump($flavor, $return, $node)` — raw output
Serialises `$index` to one of:
- **xml** → `ARC2::getRDFXMLSerializer()` (served as `application/xml` so browsers render it inline)
- **json** → `ARC2::getRDFJSONSerializer()` (`application/rdf+json`)
- **n3** → `ARC2::getNtriplesSerializer()` (`application/rdf+n3`)
- **jsonld** → `to_jsonld()` (compact schema.org JSON-LD; see [linked-data.md](linked-data.md))

Triggered by `?output=xml|json|n3|jsonld` on any URL. Sets the content-type header
and exits.

### `transform($xslfile)` — HTML output
1. Check the native file cache (lunaCache), keyed on `md5(serialize([$conf, $index]))`.
2. On a miss: serialise `$index` to RDF/XML (ARC2), load it into a `DOMDocument`,
   load the XSLT stylesheet, and run PHP's built-in `XSLTProcessor`
   (`transformToXML`).
3. Cache and return the resulting HTML string.

See [templating.md](templating.md) for how the stylesheet is chosen and the
RDF/XML shape the templates consume.

> **Semantic-web layer:** beyond the ARC2 flavours
> above, `lunaModel` also projects the current page to compact schema.org
> JSON-LD via `to_jsonld()` (reached by `?output=jsonld` and embedded in every
> HTML `<head>`), and sources the graph from a SPARQL endpoint **by default** —
> `sparql_select()`, `load_nodes_sparql()`, and `load_texts_sparql()` (with
> `?sparql=0` to fall back to the relational MySQL readers).
> Content writes also mirror into the triplestore via `rdf_sync_node()`. Both the
> read (`sparql_select()`) and write (`sparql_update()`) calls go through an
> authenticating reverse proxy (`sparql-proxy`) and attach an HTTP basic-auth
> header via `sparql_auth_header()`, using the `SPARQL_AUTH_USER` /
> `SPARQL_AUTH_PASS` credentials. None of this changes the serialisers described
> above. See [linked-data.md](linked-data.md).
