# Why RDF — what going RDF-native unlocks

This is the "so what?" document: what you can *do* with an RDF-native CMS that you
could **not** do with an ordinary PHP/MySQL, RDF-as-an-output-format app — and,
honestly, what is not done yet.

> **How to read the tags.** Each capability is marked **live** (works today),
> **one step away** (a tiny wiring step on top of what exists), or **roadmap**
> (real work — see [roadmap.md](roadmap.md)).

## The shift in one sentence

A vanilla PHP/MySQL CMS **stores** its content in SQL and can **print** it as RDF
on the way out. LunarSystem **keeps its content as a live knowledge graph and runs
itself on top of that graph** — so the things the Semantic Web is actually *for*
(querying, sharing, linking, swapping engines) are "right now," not "someday."

A useful image: in the vanilla design, RDF is the *receipt* the app prints after
doing everything in SQL. Here RDF is the *till* — the thing the app rings
everything through. The design seam is **SPARQL**: the application above it does
not know or care whether a query is answered by a triplestore or by MySQL.

## 1. Ask the content questions — not just fetch rows · **live**

A vanilla PHP/MySQL app can only answer questions a programmer pre-baked into a SQL
query buried in PHP. Here the content is a graph in a real triplestore (Oxigraph),
queryable with **SPARQL**, the standard graph query language, with no new code.

- **One question across every content type at once.** A single query censuses the
  whole site — it returns `WebPage = 13`, `Article = 1`, `Person = 2` from one
  triple pattern (`?s a ?type`):

  ```sparql
  PREFIX schema: <https://schema.org/>
  PREFIX foaf:   <http://xmlns.com/foaf/0.1/>
  SELECT ?type (COUNT(?s) AS ?n) WHERE { ?s a ?type } GROUP BY ?type
  ```

  In MySQL those classes live in different tables with different "name" columns, so
  the same answer needs a hand-written per-table `UNION`; adding a new content type
  means editing that SQL. In the graph a new type just *appears*.
- **Walk relationships without naming a join column.** "Which pages share the same
  access level as `admin`?" → it returns **6** siblings (`admin_groups`,
  `admin_levels`, `admin_mods`, `admin_pages`, `admin_users`, `journal`) by binding
  one shared `?level` node. In SQL that's a self-join with a subselect, written by
  hand for each such question.
- **Whole-tree traversal in one clause.** `?page schema:isPartOf+ ?ancestor` returns
  every page's full ancestry at any depth. The SQL path does this in
  `lunaModel::calculate_aliases()` — a recursive ~48-line PHP function that re-queries
  the DB at each level. The `+` (a SPARQL property path) replaces all of it.
- **Booleans and reshaping are built in.** `ASK { … }` answers yes/no directly;
  `CONSTRUCT`/`DESCRIBE` return a *new, re-shaped RDF document* (rename predicates,
  drop fields, emit clean Turtle) instead of a table — which in a SQL app means
  hand-writing a serialiser.

The deepest part: **the app's own routing and access control are answered by SPARQL
by default.** Visiting `/admin` resolves the page tree and the "is this user
allowed?" check as a SPARQL query scoped to the user's access levels
(`lunaModel::load_nodes_sparql()`), not bespoke SQL. Querying isn't a bolted-on
feature — it *is* the running system. (`?sparql=0` flips that single request back to
the SQL path; see §3.)

## 2. The content is legible to the outside world · **live**

A normal CMS speaks only its own private schema (`luna_nodes.tid`, opaque integer
foreign keys). The content now describes itself in **vocabularies the whole industry
already understands** — schema.org and FOAF.

- **Search engines can read it.** Every HTML page ships a
  `<script type="application/ld+json">` schema.org block in its `<head>` — the exact
  structure Google/Bing use for rich results and knowledge-graph ingestion. (A plain
  app *can* hand-emit JSON-LD, but you hand-sync every field in every template; here
  it falls out of the model.)
- **Machines consume it with no bespoke API.** `?output=jsonld` (and `xml` / `n3` /
  `json`) hand any client standards-based RDF — no custom REST API to design,
  document, version and authenticate before a third party can read the data.
  Off-the-shelf RDF/JSON-LD tooling just works.
- **Identity is stable and meaningful.** Each resource has a permanent URI
  `…/id/{slug}` that is *independent of the database row id* and **immutable**
  (`lunaModel::update()` refuses and logs any slug change). A vanilla app whose
  identity is the autoincrement row id (`/node/35`) breaks references whenever
  content is re-parented or restructured. Here the name *is* the identity — in the
  JSON-LD `@id`, as the subject of every triple, and across both backends.
- **The same standard terms come out of both backends.** `schema:WebPage` /
  `schema:Article` / `foaf:Person` (with `name` / `headline` / `articleBody` /
  `isPartOf` / `hasPart` / `inLanguage` / `identifier`) are served identically whether
  the data comes from the Oxigraph triplestore or live from MySQL via Ontop.

## 3. Storage became a swappable part, not the foundation · **live**

Because the app only ever talks to a **SPARQL endpoint**, the thing behind that
endpoint is now a configuration choice.

- **Swap the whole backing store with one environment variable, zero code change.**
  `SPARQL_ENDPOINT` points at Oxigraph (the materialised triplestore) by default;
  point it at **Ontop** and the *same queries* run live over the unchanged MySQL —
  one identical query returns byte-identical rows from both engines.
- **A SQL safety valve per request.** `?sparql=0` (or `SPARQL_READS=0`) flips a
  request back to the hand-written SQL path, and the loaders fall back to SQL
  automatically if the graph is empty/unreachable — so the graph can be authoritative
  *and* the site can't be bricked by it.
- **Point any standard RDF tool at it.** Oxigraph speaks the unextended W3C
  SPARQL 1.1 protocol over HTTP with content negotiation (SPARQL-results JSON, CSV,
  N-Triples …), so external tooling needs zero Luna-specific code — just standard HTTP
  basic auth, since Oxigraph sits on an internal-only network behind an authenticating
  reverse proxy (`sparql-proxy`, Caddy) that the app reaches with `SPARQL_AUTH_USER` /
  `SPARQL_AUTH_PASS`. A vanilla app exposes the proprietary MySQL wire protocol plus
  whatever bespoke JSON a developer hand-built.
- **Swap Oxigraph for Jena / GraphDB / Blazegraph later** — an *endpoint swap*, not a
  data-layer rewrite, because nothing in the app is coupled to a SQL dialect or driver
  anymore.
- **Rebuild or repair the graph from MySQL** with `lunaModel::rdf_resync_all()`
  (runnable as `make resync-triplestore`), which re-projects every node — the
  pure-PHP reconciliation that keeps the mirror honest and bootstraps the graph.

## 4. Now a small step away

The RDF substrate turns these from "rewrite" into "wire it up":

- **Cross-store federation already works · live.** A single SPARQL query in Oxigraph
  can reach across to Ontop's live-MySQL view via `SERVICE` and join the two on the
  shared `…/id/{slug}` identity — *no ETL, no shared schema, no app code*.
- **Data-integrity rules as portable checks · one step away.** "No duplicate slugs",
  "no page points at a missing parent" — expressible as SPARQL `ASK` / `SELECT` that
  run from anything, rather than as MySQL constraints locked inside the engine. (The
  `ASK` pre-checks the roadmap schedules for P2.)
- **Dereferenceable URIs + `owl:sameAs` links · live.** `/id/{slug}` resolves (303s to the
  negotiated document), `/data/{slug}` serves the RDF, and resources carry outbound
  `owl:sameAs`/`schema:sameAs` links — shipped in 0.8.54–0.8.55. Five-star Linked Data, joining
  the content to the global web of data.
- **A public read-only SPARQL *endpoint* · roadmap.** Content negotiation and the per-resource
  `/data` documents already ship; what remains is exposing a queryable SPARQL endpoint publicly —
  the engine already speaks the protocol, so this is exposing it, not building it.
- **RDFS/OWL inference and SHACL validation · roadmap (P3).** Deriving facts and
  validating content against shapes — concepts that *don't exist* in SQL — become an
  endpoint/tooling choice here, because the content is already typed with
  formal-semantics vocabularies.

## 5. The honest flip side

So none of the above is over-read:

- **MySQL is still the system of record.** The graph is a synchronised mirror, written
  **best-effort** (a failed mirror never blocks a save), and reconciled by
  `rdf_resync_all()` if it drifts. Making the triplestore the *single* source of truth
  is **P2** (not done — see [roadmap.md](roadmap.md)).
- It now runs on **PHP 8.3 / MySQL 8.0** (PDO) and is still **XSLT-rendered** server-side.
- The inference, validation, and public SPARQL-endpoint items in §4 are **not built yet** — they are
  within reach, not done. (Dereferenceable URIs, `/data`, and `owl:sameAs` links *are* built, as of
  0.8.54–0.8.55.)

What changed is the **foundation and the ceiling**: the content is now a real,
standards-described, queryable graph that the application runs on — which is exactly
the part a classic PHP/MySQL CMS can never reach without becoming a different
application.

## Where to go next

- **[linked-data.md](linked-data.md)** — the design (URI policy, vocabulary
  mapping, the read/write loop, the Ontop/Oxigraph engines).
- **[roadmap.md](roadmap.md)** — what remains: P2 (single source of truth), P3
  (semantics), P4 (data-first server); and why client-side XSLT (P5) was dropped.
- **[architecture.md](architecture.md)** — how a request flows through routing, ACL,
  mods and rendering.
