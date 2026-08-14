# Overview

## What Luna is

Luna is a small, self-contained PHP content-management system written
between **2006 and 2010** by Odradek. It was released on
SourceForge as alpha software and reached version **0.2.7-alpha** in 2010; this
archival revival carries the version forward to **0.9.3-alpha**.

Functionally it is a Drupal-like CMS: a tree of pages, a pluggable module
system, users/groups/access-levels, editable text content, and i18n. What makes
it unusual for its era is its **data philosophy**:

> Every entity in the system — users, groups, access levels, pages, modules,
> text blocks, even transient request variables — is represented as a node in an
> **RDF graph**. Pages are produced by serialising that graph to **RDF/XML** and
> applying an **XSLT** stylesheet.

So the same model that drives the HTML page can be emitted verbatim as RDF/XML,
JSON-LD, RDF/JSON or N-Triples by appending `?output=xml|jsonld|json|n3` to any URL.
The CMS is, in effect, a semantic-web front end over a generic graph stored in MySQL.

> **Where this is heading.** The project extends this RDF-flavoured CMS toward
> an RDF-native one — a JSON-LD projection and a real SPARQL read path backed by
> Ontop and Oxigraph. See [linked-data.md](linked-data.md). The sections below
> describe the relational baseline; the semantic-web layer is built on top of it.

## The core ideas

1. **A generic node/edge graph in MySQL.** Rather than a table per content
   type, there is one `luna_nodes` table for *all* entities and one
   `luna_nodes_map` edge table for *all* relationships. Types live in
   `luna_types`. See [database-schema.md](database-schema.md).

2. **Projection to RDF at request time.** On each request the relevant rows are
   read and projected into an in-memory triple store (`lunaModel`). See
   [rdf-model.md](rdf-model.md).

3. **XSLT as the view layer.** The triple store is serialised to RDF/XML and
   transformed by an XSLT stylesheet chosen by page identity. See
   [templating.md](templating.md).

4. **Modules compose a page.** A page is just a node; what renders on it are the
   *mods* linked to it (subject to access control). See [modules.md](modules.md).

5. **Graph-based access control.** A user belongs to groups, groups grant access
   levels, and content/mods require a level. Authorisation is a graph walk.

6. **Multi-site by hostname.** Configuration is resolved per domain by walking
   `HTTP_HOST`, Drupal-style. See [configuration.md](configuration.md).

## Glossary

| Term | Meaning |
|---|---|
| **node** | A vertex in the graph. A row in `luna_nodes`. Has a `nid`, an `lid`, a type, and an optional parent. Represents a user, group, level, page, mod, or text. |
| **nid** | Numeric node ID (`luna_nodes.nid`), the primary key. |
| **lid** | *Literal identifier* — a unique human-readable slug (`luna_nodes.lid`), e.g. `admin`, `root`, `login`. Used in code and URLs. |
| **tid / type** | The node's class, via `luna_nodes.tid` → `luna_types`. One of: `user`, `group`, `level`, `page`, `mod`, `text`. |
| **edge / mapping** | A row in `luna_nodes_map` linking `nid1`→`nid2`. Used for user→group, group→level, page→mod, mod→level, etc. |
| **mod** | A pluggable page module (a PHP class under `luna/luna.mods/`). |
| **level** | An access level. A user holds a set of levels; content gated by a level is visible only to holders. |
| **text** | A content block (`luna_texts`), one row per language, attached to a page. |
| **domain** | A per-host configuration directory under `luna/luna.domains/`. |
| **model** | The `lunaModel` singleton — the in-memory RDF triple store for the current request. |
| **the `luna:` namespace** | The custom RDF ontology, `https://jeromev.github.io/Luna/ontology#`, used for the genuinely app-specific content predicates (`lid`, `isActive`, `content`, `level`, …) — standard terms like `schema:identifier` (the numeric nid) and `schema:name` are used where one exists. A separate `ui:` render namespace (`https://jeromev.github.io/Luna/render#`) carries the transient UI render-model. |

## What it is *not*

- The relational baseline is **not** a triplestore database. RDF lives only in
  memory during a request; relational persistence is plain MySQL. *(The
  semantic-web layer adds an Oxigraph triplestore that every content write
  mirrors into, and that the read path is served from by default — see
  [linked-data.md](linked-data.md).)*
- The relational baseline does **not** use a SPARQL engine; its graph queries are
  hand-written SQL joins. *(Routing, access control and texts run through SPARQL
  by default — Oxigraph, or Ontop over MySQL — with `?sparql=0` to fall back to
  the SQL joins — see [linked-data.md](linked-data.md).)*
- It runs on **PHP 8.3 / MySQL 8.0** via PDO. See [security.md](security.md) and
  [installation.md](installation.md).
