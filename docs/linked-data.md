# Linked Data — the Semantic Web layer

> **Where this lives.** This is the `main` line; the untouched archival CMS is
> preserved on the `legacy` branch. The triplestore is authoritative for the
> read path by default: every content write mirrors into Oxigraph through a
> generic write-through in the model's CRUD; routing, ACL and texts are read from
> the triplestore by default (`?sparql=0` falls back to SQL). MySQL remains the
> system of record until the rename/URI decision unblocks P2 (see [roadmap.md](roadmap.md)).

## The design

**SPARQL is the stable seam.** The application above it only ever talks to a
SPARQL endpoint, so the engine behind that endpoint is a configuration choice:

| Layer | What | Storage | Query |
|---|---|---|---|
| **URI + vocabulary** | The decisions that are *forever* (URIs, vocabulary), proven by real output (JSON-LD) | MySQL | existing |
| **Virtual SPARQL** | MySQL exposed as a **virtual RDF graph** via an R2RML mapping + Ontop | MySQL (unchanged) | **SPARQL** (read) |
| **Triplestore** | The same mapping materialised into a triplestore | **Oxigraph** | **SPARQL** (read + write) |

The same **R2RML mapping** that drives the virtual graph is the materialisation
script for the triplestore, so swapping one for the other changes nothing in the
application. The two decisions below are written once and reused unchanged by
both, which is why they have to be right.

## Decision 1 — URI policy (this is forever)

Linked Data's first principle: use **HTTP URIs as names**, and make them
dereferenceable. `nid` autoincrement ints and `#page_9` are the antithesis of
that. We separate the *resource* (the abstract thing) from the *documents* that
describe it (Cool URIs / the `303` pattern):

| Kind | Pattern | Example | Returns |
|---|---|---|---|
| **Resource** (identity) | `{base}/id/{slug}` | `https://site/id/root` | `303 See Other` → a document below, by `Accept` |
| **HTML document** | `{base}/{path}` | `https://site/` | `text/html` |
| **RDF document** | `{base}/data/{slug}` | `https://site/data/root` | `text/turtle`, `application/ld+json`, … |

Invariant rules:

- The **slug** (`luna_nodes.lid`) is the stable local name, *not* the numeric `nid`.
- `/id/{slug}` is the thing's identity everywhere in the graph (subject/object of
  triples), never the `nid`.
- Content negotiation (HTTP `Accept`) is the mechanism (the `?output=` query
  param stays as a convenience/debug alias).

`/id/{slug}` is the identity everywhere a consumer sees — rendered, published, and
in the triplestore. The integer `nid` survives only as the `schema:identifier`
property and as the loaders' internal DB key.

### Dereferencing & content negotiation

The URI policy above is **live** ([`luna::route_linked_data()`](../luna/luna.php),
[`lunaTools::set_output_format()`](../luna/luna.classes/luna.tools.class.php)). The
same canonical URL is HTML for a browser and RDF for a Linked Data client, chosen
by the HTTP `Accept` header:

```bash
curl -H 'Accept: text/html'         https://site/         # the HTML page
curl -H 'Accept: text/turtle'       https://site/         # the SAME page as Turtle
curl -H 'Accept: application/ld+json' https://site/        # …as schema.org JSON-LD
```

`Accept` is the lowest-precedence signal: an explicit `?output=` / path suffix
(debug aliases) wins over it, and a bare `*/*` or a missing header keeps HTML, so
nothing a browser sends is ever surprised into RDF. Only `GET`/`HEAD` negotiate,
never an XHR. Every response carries `Vary: Accept` so caches key on it.

The `/id` and `/data` URIs implement the Cool-URIs `303` split:

```bash
curl -i -H 'Accept: text/html'   https://site/id/root   # 303 -> https://site/         (HTML doc)
curl -i -H 'Accept: text/turtle' https://site/id/root   # 303 -> https://site/data/root (RDF doc)
curl -i                          https://site/data/root # 200  text/turtle (defaults to Turtle)
```

- **`/id/{slug}`** returns no content of its own — it `303 See Other`s to the
  concrete document for the negotiated format (the HTML page, or the RDF
  `/data/{slug}`).
- **`/data/{slug}`** is the RDF document — Turtle / JSON-LD / RDF/XML / … by
  negotiation, **never** HTML; with no RDF preference it defaults to Turtle.
- Both resolve the slug against the **ACL-filtered** graph, so a resource the
  current user can't see is a `404` here exactly as in the HTML view (a guest's
  `/data/admin` is `404`; an admin's is `200`).
- Each response advertises the others with `Link` headers
  (`rel="canonical"` → the identity, `rel="alternate"` → the RDF/HTML twin,
  `rel="describedby"`), so a consumer can follow its nose between representations.

Today `/id` and `/data` cover **pages**; a text/`Article` slug is described inside
its page's `/data` document (standalone text dereferencing is a follow-up).

`/sitemap.xml` (the public page tree, as the anonymous crawler sees it) and `/robots.txt`
round out the crawler-facing surface. All of this — HTML, the negotiated RDF, `/id`, `/data`,
the embedded JSON-LD, the sitemap — is projected from MySQL in PHP, so the **publishing
surface needs no triplestore**: a public PHP/MySQL deploy sets `SPARQL_ENABLED=0` and serves
it all from the relational store. See [going-public.md](going-public.md).

### Linking out — the web of data

Dereferenceable URIs make the graph *readable*; **outbound links** make it part of
the *web of data*. Without them the published graph is well-formed RDF that links to
nothing — an island. [`semantic/links.ttl`](../semantic/links.ttl) is the operator's
curated set of statements that point this site's resources at external entities and
related resources:

```turtle
@prefix owl:    <http://www.w3.org/2002/07/owl#> .
@prefix schema: <https://schema.org/> .

<root>             schema:sameAs <https://github.com/jeromev/Luna> .
<a-concept-page>   owl:sameAs    <https://www.wikidata.org/entity/Q42> .
```

Subjects are **relative `/id/` IRIs** — a content slug — so the file is
deployment-independent: they resolve against the live `{site}/id/` base when
projected ([`lunaModel::outbound_index()`](../luna/luna.classes/luna.model.class.php),
parsed once per request; a missing or unparseable file is a silent no-op). Each
statement is merged into **every** representation of its resource:

- `?output=turtle/xml/json` and the `/data/{slug}` document (via `build_schema_index()`);
- the embedded + `?output=jsonld` JSON-LD (via `to_jsonld()` — schema.org predicates
  compact to bare terms like `sameAs`, other vocabularies keep their full IRI key);
- the **triplestore**, after `make resync-triplestore`
  ([`rdf_load_links()`](../luna/luna.classes/luna.model.class.php) does a SPARQL
  `INSERT DATA`), so a direct SPARQL query sees the same links.

Curate honestly: `owl:sameAs` / `schema:sameAs` assert **identity** (the object *is*
this thing — its Wikidata / ORCID / official URL); `rdfs:seeAlso` / `schema:about` are
the weaker *related to* / *is about*. This is what lets a consumer — or an agent —
follow its nose out of Luna, and lets external data point back in.

## Decision 2 — Vocabulary mapping (reuse, don't invent)

The whole point of the Semantic Web is **shared** vocabularies, so the content
maps to widely-used vocabularies and keeps `luna:` only for genuinely
app-specific things:

| Concept | Standard term | Vocabulary |
|---|---|---|
| page | `schema:WebPage` (`foaf:Document`) | schema.org / FOAF |
| text | `schema:Article` / `sioc:Item` | schema.org / SIOC |
| user | `foaf:Person` | FOAF |
| title / name | `schema:name` / `schema:headline` | schema.org |
| content | `schema:text` / `schema:articleBody` / `sioc:content` | schema.org / SIOC |
| parent | `schema:isPartOf` / `dcterms:isPartOf` | schema.org / DC Terms |
| children | `schema:hasPart` | schema.org |
| node lid as email | `foaf:mbox` | FOAF |
| audit (`luna_actions`) | `prov:Activity` / `prov:wasGeneratedBy` / `prov:Agent` | PROV-O |
| language (`luna_texts.lang`) | **the literal's language tag** (`"Bonjour"@fr`), plus `schema:inLanguage` | RDF 1.1 / schema.org |
| taxonomy (`luna_types`) | `rdf:type` against an **RDFS/OWL + SHACL** schema | RDFS / OWL / SHACL |
| access level | `luna:level` (or Web Access Control, `acl:`) | luna: / WAC |

**Translations are language-tagged literals, and this is the one place the graph stopped being a
lossy mirror.** A text node holds one row per language, and every one of them is projected onto the
same subject under the same predicate, told apart by its tag:

```turtle
</id/welcome> a schema:Article ;
    schema:headline    "Welcome"@en, "Bienvenue"@fr ;
    schema:articleBody "…"@en, "…"@fr ;
    schema:inLanguage  "en", "fr" .
```

Before `0.8.67-alpha` the projection carried exactly one translation — untagged, with the language
beside it as a plain `schema:inLanguage` annotation — so the others existed nowhere in RDF and the
mirror could not represent what MySQL held. Tags fix that, and they are what makes a language-aware
`CONSTRUCT` possible at all: a query can ask for the French rendering. `schema:inLanguage` is still
emitted for the R2RML mapping's benefit, but the tag is what the read path now trusts, because a tag
travels attached to the value it labels while a separate annotation does not.

Anything querying these must correlate the tags — a text carries a headline *and* a body *and* a
`luna:content` per language, so an uncorrelated join returns the cross-product and can hand back an
English headline attached to a French body. `lunaModel::load_texts_sparql()` filters on
`lang(?title) = lang(?body)` for exactly this reason.

Namespaces in play: `schema:` `https://schema.org/`, `dcterms:`
`http://purl.org/dc/terms/`, `sioc:` `http://rdfs.org/sioc/ns#`, `foaf:`
`http://xmlns.com/foaf/0.1/`, `prov:` `http://www.w3.org/ns/prov#`, `skos:`
`http://www.w3.org/2004/02/skos/core#`. A small `luna:` survives for what nobody
else models.

The `luna:` namespace IRI is **`https://jeromev.github.io/LunarSystem/ontology#`**, defined
once as `lunaModel::LUNA_NS` and documented in [`ontology/`](../ontology/) (its README covers
publishing it via GitHub Pages so the IRI dereferences). Three URI roles stay distinct: this
**vocabulary** namespace (project-level, stable); **resource/instance** IRIs (`/id/{slug}`,
which follow the deployment host via `luna::$site_uri`); and the project **homepage**
(`https://github.com/jeromev/Luna`). Only the vocabulary namespace is published RDF
identity — pointing it at the bare code repo would conflate it with the homepage and wouldn't
dereference to a vocabulary.

## The JSON-LD projection

A **JSON-LD projection** of the current page, generated from the same in-memory
model that drives the HTML view — the "one model, many representations" idea, the
representation being correct, standards-based Linked Data:

- `?output=jsonld` on any page → compact **schema.org JSON-LD**
  (`application/ld+json`).
- The same block is embedded as `<script type="application/ld+json">` in every
  HTML page's `<head>` — the part of the Semantic Web that actually *won*
  (Google/Bing rich results, knowledge-graph ingestion).
- `?output=xml/turtle/n3/json` serve the **same clean projection**
  ([`lunaModel::build_schema_index()`](../luna/luna.classes/luna.model.class.php)) — slug
  IRIs, `schema:WebPage`/`Article`, the `luna:` terms — so the whole public RDF
  surface matches the triplestore. There is effectively **one model**: the loaders
  build an `nid`-keyed in-memory graph, but
  [`lunaModel::project_to_schema()`](../luna/luna.classes/luna.model.class.php)
  re-keys it to `/id/{slug}` and maps the content vocabulary to schema.org at the
  `transform()` serialisation boundary, so **the XSLT renders from the schema.org /
  `/id/{slug}` graph too**. Hierarchy is `schema:isPartOf`, the active flag is
  `luna:isActive`, and the `nid` survives only as the `schema:identifier` property
  and the loaders' internal DB key.

Implementation: [`lunaModel::to_jsonld()`](../luna/luna.classes/luna.model.class.php)
applies the Decision-2 mapping to the page node + its text blocks and emits a
`schema:WebPage` with `schema:hasPart` `schema:Article`s, `schema:isPartOf` for
the parent, and `/id/{slug}` resource URIs from Decision 1. It is a *projection
over the existing model* — the very same shape comes from a **SPARQL `CONSTRUCT`**
over the R2RML-mapped graph.

```jsonc
{
  "@context": "https://schema.org/",
  "@type": "WebPage",
  "@id": "https://site/id/root",        // resource (Decision 1)
  "url": "https://site/",                // the HTML document
  "name": "Home",
  "inLanguage": "en",
  "hasPart": [
    { "@type": "Article", "headline": "Welcome", "articleBody": "…", "inLanguage": "en" }
  ]
}
```

## The virtual SPARQL endpoint (Ontop)

MySQL is queryable as RDF with **SPARQL**, with **no schema change and no data
migration**, via [Ontop](https://ontop-vkg.org/) + an R2RML mapping — RDF as a
query surface, not an output veneer.

Files (all under [`semantic/ontop/`](../semantic/ontop/)):

| File | Role |
|---|---|
| `mapping.ttl` | R2RML: `luna_nodes`/`luna_nodes_map`/`luna_texts`/`luna_users` → schema.org/FOAF (incl. access **levels** → `luna:level`, which drive the SPARQL routing/ACL path), using the **same `/id/{lid}` URIs** as the JSON-LD |
| `ontop.properties` | JDBC connection to the `db` service |
| `Dockerfile` | `ontop/ontop` + the MySQL JDBC driver |

Run it (the `db` service must be up):

```bash
docker-compose up --build -d ontop      # SPARQL endpoint at http://ontop:8080/sparql (internal compose network only)
```

Example — pages and their article headlines, straight out of the relational
tables:

```sparql
PREFIX schema: <https://schema.org/>
SELECT ?page ?title WHERE {
  ?page a schema:WebPage ; schema:hasPart [ schema:headline ?title ] .
}
# → http://localhost:8080/id/root  "Welcome"
```

```bash
docker-compose exec -T app curl -s http://ontop:8080/sparql -H 'Accept: application/sparql-results+json' \
  --data-urlencode 'query=PREFIX schema: <https://schema.org/>
    SELECT ?child ?parent WHERE { ?child schema:isPartOf ?parent }'
# → the whole page tree as a graph (admin isPartOf root, …)
```

**Why the engine swap is clean:** the URI a SPARQL query returns
(`http://localhost:8080/id/root`) is byte-for-byte the `@id` the JSON-LD emits.
The virtual graph and the HTML/JSON-LD views describe the *same resources* — so
when the mapping is materialised into a triplestore, every URI (and every
external `owl:sameAs` to it) keeps working.

> Hard-coded `luna_types` ids (`user=1 text=4 page=5`) keep the mapping SQL simple
> for Ontop's parser; `ontop.inferDefaultDatatype=true` defaults the string
> columns. Ontop is read-only (SPARQL `SELECT`/`CONSTRUCT`/`DESCRIBE`); the
> triplestore handles writes.

### Reading *through* SPARQL

The app populates its model **from the SPARQL endpoint** instead of the
hand-written joins. [`lunaModel::sparql_select()`](../luna/luna.classes/luna.model.class.php)
queries the endpoint, and `load_texts_sparql()` rebuilds a page's text blocks
through the *same* `load_text()` index builder the SQL path uses. **This is the
default** (`lunaModel::sparql_reads()`, constant
`SPARQL_READS`); `?sparql=0` forces the SQL path for one request:

```text
/?output=jsonld            # page texts from the SPARQL endpoint (default)
/?output=jsonld&sparql=0   # page texts from the hand-written SQL joins
```

Both produce a **byte-for-byte identical model**. The mapping carries a
`schema:identifier` (the `nid`) so the SPARQL loader can rebuild the exact
`/node/{nid}` index the XSLT expects — `nid` as a *property*, not as identity.

**Routing and access control go through SPARQL too.** `load_nodes_sparql()`
loads the whole page tree — scoped to the levels the current user holds — and
rebuilds it through the same `load_node()` + `calculate_aliases()` the SQL path
uses, so URL→page resolution *and* the level-based ACL are driven by the graph.
As a guest, `/` renders the public home but `/admin` is **404** (admin pages
aren't in a guest's level-filtered alias table); an admin's levels resolve every
protected page from the graph. The page's **routing and content** are built from
SPARQL; the only remaining SQL read on the page path is the mod list
(`load_mods`) — infrastructure, not content.

Both the routing loader and the text loader keep an **automatic SQL fallback**:
if the SPARQL path is off (`?sparql=0` / `SPARQL_READS=0`) or returns nothing,
they fall back to the hand-written joins, so a cold or unreachable endpoint can
never brick routing. (A *partial* mirror gap is not caught by the empty-result
fallback — reconcile with `rdf_resync_all()`; see below.)

This is what makes SPARQL the **read boundary**: with the loaders reading this
way, swapping Ontop for a triplestore changes nothing in the application above
the endpoint.

### Writing *through* SPARQL

**Every** content write flows into the triplestore, through a generic
write-through wired into the model's CRUD.
[`lunaModel::sparql_update()`](../luna/luna.classes/luna.model.class.php) POSTs a
SPARQL `UPDATE` to `SPARQL_UPDATE_ENDPOINT` (the authenticating `sparql-proxy` in
front of Oxigraph — the same HTTP basic auth `sparql_select()` sends on reads,
added by `sparql_auth_header()` from `SPARQL_AUTH_USER`/`SPARQL_AUTH_PASS`;
best-effort, so a failed mirror never breaks a save). On top of it:

- **`rdf_sync_node($nid)`** re-projects a node's *whole* description into the
  graph — `DELETE { <uri> ?p ?o } INSERT { …the triples the R2RML mapping derives… }`
  — typing the resource (`page`→`schema:WebPage`, `text`→`schema:Article`,
  `user`→`foaf:Person`; level/group/mod stay untyped, carrying only
  `schema:name`/`schema:identifier`/`luna:isActive`) and emitting its edges
  (`schema:isPartOf`/`hasPart`, `luna:level`) as `/id/{lid}` resource URIs, plus
  scalar properties (`schema:identifier`, `luna:isActive`) typed `xsd:integer` to
  match the materialisation. `insert`/`update`/`link`/`unlink` all call it;
  `mod_edit_texts` calls it after writing a text body.
- **`rdf_delete_node($nid)`** drops every triple mentioning the resource — as
  subject *and* as object — and runs inside `delete()` before the rows go.
- **`rdf_resync_all($prune = false)`** re-projects *every* node from MySQL: the
  pure-PHP bootstrap/repair of the store. Run it to seed Oxigraph or to reconcile
  after any out-of-band change — runnable as **`make resync-triplestore`** (→
  `bin/resync-triplestore.php`). The default upserts MySQL→graph and leaves
  graph-only orphans in place; `$prune = true` (what the make target passes)
  first clears the whole store via `rdf_clear()`, for a full rebuild that drops
  orphans too. This pure-PHP projection is *fuller* than the Ontop materialise —
  it types every text `schema:Article` (not just the page-linked ones) and
  projects the level/group/mod nodes — so a rebuilt store holds ~140 triples to
  the materialise's 98.

So any content change in the admin UI **dual-writes**: the existing SQL to MySQL,
plus a SPARQL `DELETE`/`INSERT` to the graph for `<base/id/{lid}>`. Dual-write
keeps the two stores in sync while the write path migrates; once the MySQL write
retires (P2), the graph is the single source of truth and the drift window closes.

## Seeding the triplestore

**Swap the engine, not the application.** The `mapping.ttl` that drives the
virtual graph doubles as the materialisation script for the triplestore. The
default and recommended way to seed and reconcile Oxigraph is the pure-PHP
`rdf_resync_all()` (see *Writing through SPARQL*), run with
**`make resync-triplestore`**. The Ontop materialise is an alternative route:

```bash
# 1. materialise the SAME mapping into a static RDF dump (98 triples)
docker exec luna-ontop-1 /opt/ontop/ontop materialize \
  -m /opt/ontop/input/mapping.ttl -p /opt/ontop/input/ontop.properties \
  -o /opt/ontop/input/dump.nt -f ntriples

# 2. load it into the triplestore THROUGH the authenticating proxy. Oxigraph sits on an
#    internal-only network and is never reachable directly, so the load presents the
#    proxy credentials (the app container has them in its env).
docker-compose up -d oxigraph sparql-proxy
docker-compose exec -T app sh -c 'curl -X POST -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" \
  "http://sparql-proxy:7878/store?default" \
  -H "Content-Type: application/n-triples" --data-binary @semantic/ontop/dump.nt'
```

The app reads from the triplestore by default (`SPARQL_ENDPOINT` defaults to the
proxy in front of Oxigraph) with **no code change**. To read live from MySQL
through Ontop instead, override the endpoint:

```bash
SPARQL_ENDPOINT=http://ontop:8080/sparql docker-compose up -d app   # read via Ontop
```

Routing, access control, and content are all served by the triplestore, with the
PHP unchanged — stopping Ontop entirely leaves the default read path working:

```text
docker stop luna-ontop-1
guest  /              -> 200   (home renders from the triplestore)
guest  /admin         -> 404   (level-based ACL preserved in the graph)
admin  /admin/journal -> 200   (deep alias resolved from the graph)
```

`mapping.ttl` → `dump.nt` is the only moving part. The dump is generated
(gitignored); regenerate it with the command above.

## Roadmap

- **Virtual SPARQL:** R2RML + Ontop virtual SPARQL endpoint over the existing
  MySQL; the page read path (routing, ACL, text content) flows through SPARQL.
  Ontop is the *opt-in* override (`SPARQL_ENDPOINT=…/sparql`).
- **Triplestore:** `mapping.ttl` materialised into Oxigraph, the default endpoint.
- **Read/write loop:** *every* content write mirrors to the graph via the generic
  `rdf_sync_node` / `rdf_delete_node` write-through (see *Writing through SPARQL*),
  and reads default to the graph with a SQL fallback (see *Reading through
  SPARQL*). MySQL is still the system of record.

What remains is tracked in **[roadmap.md](roadmap.md)**: **P2** — retire the MySQL
content write so the triplestore is the *single* source of truth (blocked on the
rename/URI decision; needs must-succeed writes + an outbox since there's no 2PC),
minting URIs from slugs not `nid` sequences; and the optional **P3** — RDFS/OWL
inference, SHACL validation, and named graphs for drafts/versions. Still-SQL
holdouts: the mod list (`load_mods`) and the `luna_actions` audit trail (PROV-O).
**Boundary:** sessions and cache stay relational/native — a triplestore is the
wrong tool for ephemeral, high-churn data.

The cardinal rule throughout: **freeze the URIs.** The same `/id/{slug}` across
the JSON-LD, the virtual graph, and the triplestore, so every external link and
`owl:sameAs` keeps working.
