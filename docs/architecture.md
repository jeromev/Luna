# Architecture

This document traces a single HTTP request end-to-end and describes the core
classes that handle it.

## The entry point

All requests are rewritten to [`index.php`](../index.php) by the root
[`.htaccess`](../.htaccess) (clean-URL rewriting). The entry point is tiny:

```php
require_once './luna/luna.php';
if ($luna = luna::singleton()) {
    if ($luna->load_mods()) {
        die($luna->transform());
    }
}
```

Three phases, in order:

1. **`luna::singleton()`** — bootstrap (constructor).
2. **`load_mods()`** — find, instantiate, and run the page's modules.
3. **`transform()`** — serialise the model to RDF/XML and apply XSLT.

The whole system is built from **singletons**: `luna`, `lunaModel`,
`lunaSession`, and every mod. Cloning is explicitly forbidden (`__clone()`
triggers a fatal error).

## Core classes

| Class | File | Responsibility |
|---|---|---|
| `luna` | [luna/luna.php](../luna/luna.php) | Bootstrap, routing, mod loading, render orchestration |
| `lunaModel` | [luna/luna.classes/luna.model.class.php](../luna/luna.classes/luna.model.class.php) | The in-memory RDF triple store; SQL→RDF projection and serialisation |
| `lunaDB` | [luna/luna.classes/luna.db.class.php](../luna/luna.classes/luna.db.class.php) | PDO (`pdo_mysql`) database wrapper |
| `lunaSession` | [luna/luna.classes/luna.session.class.php](../luna/luna.classes/luna.session.class.php) | DB-backed session handler; builds the `$user` object |
| `lunaTools` | [luna/luna.classes/luna.tools.class.php](../luna/luna.classes/luna.tools.class.php) | Sanitisation, caching, i18n, URL building, error pages |
| `lunaAuthz` | [luna/luna.classes/luna.authz.class.php](../luna/luna.classes/luna.authz.class.php) | Access control: may this user reach this level, page, group or text |
| `lunaRender` | [luna/luna.classes/luna.render.class.php](../luna/luna.classes/luna.render.class.php) | The XSLT pipeline: projects the graph to slug identity and applies a stylesheet |
| `lunaGraph` | [luna/luna.classes/luna.graph.class.php](../luna/luna.classes/luna.graph.class.php) | SPARQL transport and the RDF write-through that keeps the triplestore reconciled with MySQL |
| `lunaLog` / `lunaException` | [luna/luna.classes/luna.log.class.php](../luna/luna.classes/luna.log.class.php) | Exception type and DB error logging (direct PDO INSERT) |

## Phase 1 — Bootstrap

The `luna::__construct()` method ([luna.php](../luna/luna.php)) runs a
fixed sequence wrapped in a `try`/`catch (lunaException)`. Most steps throw a
`lunaException` on failure (caught, logged, then `die()`); the earliest steps
(site path, ini load, core-class includes) `die()` or `trigger_error()` directly
with a message instead:

> The `luna.php:NNN` line numbers below are indicative and drift as the file changes — navigate by the
> **named method** (`__construct()`, `set_site_path()`, `load_ini()`, `load_mods()`, `transform()`, …),
> which is the stable anchor.

1. **Environment setup** — `define('NOW', time())`,
   set a default timezone (honour an existing `php.ini` `date.timezone`, else UTC),
   disable `register_globals`/`display_errors`, force cookie-only sessions (`use_trans_sid=0`, `use_only_cookies=1`, `use_strict_mode=1`), set
   and `error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT)`. (`display_errors` is
   turned back **on** for admins later when `DEBUG` is set.)
2. **Resolve the site path** (`set_site_path()`, [luna.php](../luna/luna.php)) —
   walks `HTTP_HOST` segment by segment looking for a matching directory under
   `luna/luna.domains/`, falling back to `luna.default/`. Defines the `SITEPATH` constant
   and sets the `site_uri` / `site_relative_url` static properties.
   (`set_requested_path()` in [luna.php](../luna/luna.php) is a *different*
   method — it normalises the requested URL path; see step 9.)
3. **Load the ini** (`load_ini()`, [luna.php](../luna/luna.php)) —
   parses `<domain>/ini/luna.ini` and turns `[Paths]` and `[Constantes]` entries
   into PHP constants. See [configuration.md](configuration.md).
4. **Require the core classes** — log, tools, db, session, model.
5. **Sanitise inputs** — `lunaTools::sanitize_inputs()` cleans the superglobals.
6. **Cache + AJAX detection** — `lunaTools::check_cache()`; define `AJAX` from
   the `X-Requested-With` header.
7. **Database** — `lunaDB::prepare()` then `lunaDB::connect()` (PDO).
8. **Config** — `lunaTools::load_config()` reads the `luna_config` table.
9. **Routing** — `set_requested_path()` normalises the requested URL path.
10. **Session** — `lunaSession::singleton()->start()` (DB-backed; builds `$user`).
11. **Language** — `lunaTools::set_language()` negotiates locale (gettext).
12. **Output format** — `lunaTools::set_output_format()` (`html`/`xml`/`turtle`/`json`/`n3`/`jsonld`),
    also chosen by HTTP `Accept` content negotiation.
13. **Model** — `lunaModel::singleton()` builds the triple store.
14. **Admin cache bypass** — if the user holds `level_admin`, caching is disabled
    and `IS_ADMIN` is defined true.
15. **Resolve the page** — `get_page_node_from_alias($path)` maps the URL to a
    page node; a miss raises a **404**. Defines `PAGENID` and `PAGELID`.
16. **Authorise** — `check_privileges()`; unauthorised users are redirected to
    `login`.
17. **Load page texts** — by default `load_texts_sparql(PAGENID)` fetches the
    page's content blocks from the triplestore; `?sparql=0` (or an empty graph
    result) falls back to `load_texts(0, PAGENID)`, the SQL path. Both merge the
    same blocks into the model — see [linked-data.md](linked-data.md).
18. **Collect page metadata** — site name, description, version, author, language,
    etc. into `luna::$data`.

After the constructor, the model holds the page node, its texts, the current
user, and site metadata.

## Phase 2 — Load mods

`load_mods()` ([luna.php](../luna/luna.php)) is where a page gets its
content. A single SQL query joins the triple tables to find every mod that is
**(a)** linked to the current page **and (b)** linked to an access level the
current user holds:

```
pm.nid1 = PAGENID AND pm.nid2 = m.nid     -- page → mod
AND ml.nid1 = m.nid AND ml.nid2 = l.nid   -- mod → level
AND tm.lid = 'mod'  AND m.tid = tm.id
AND tl.lid = 'level' AND l.tid = tl.id
AND m.is_active = 1 AND l.is_active = 1
```

For each matching mod (`load_mods()` in [luna.php](../luna/luna.php)):

1. Skip it unless the user actually holds the mod's level
   (`$session->user->levels[$level_nid]`).
2. Locate the mod's PHP file via a cascading lookup (domain `mods/` dir first,
   then the built-in `luna.mods/`).
3. `require_once` it and call its `singleton()`.
4. On form submission (`submit`/`batch_submit` present), dispatch to the mod's
   `submit()` and — based on `$_POST['mode']` — `submit_add()`,
   `submit_modify()`, or `submit_delete()`.
5. Call the mod's `load()` to inject its data into the model.
6. Mark the mod node `is_loaded` in the model.

Finally it merges any flash messages into the model. The mod system is detailed
in [modules.md](modules.md).

> **AJAX short-circuit:** at the start of `transform()`
> ([luna.php](../luna/luna.php)), if `AJAX` is true the request `die()`s
> — XSLT is skipped and only the model-population side effects of `load_mods()`
> persist. `load_mods()` itself only special-cases AJAX to skip the post-submit
> table optimisation (`lunaDB::optimise()`, gated by `!AJAX` at
> `load_mods()`).

## Phase 3 — Transform

`transform()` ([luna.php](../luna/luna.php)) assembles the remaining
view data and renders:

1. Merge in the current user, available languages, available output formats, the
   `luna::$data` metadata, the i18n vocabulary, and the raw `$_REQUEST`. These
   render-scaffolding nodes are built by `lunaModel::load_var()` as blank nodes
   in the UI render namespace `ui:` (`https://jeromev.github.io/LunarSystem/render#`);
   they drive the XSLT chrome only and never reach the published RDF graph.
2. If `output_format != html`, call `lunaModel::dump($format)` ([luna.model.class.php](../luna/luna.classes/luna.model.class.php)),
   which serialises and exits. `dump()` first builds the clean, published graph
   via `build_schema_index()` — slug IRIs (`/id/{slug}`), schema.org classes
   (`schema:WebPage`/`schema:Article`) and predicates — then emits RDF/XML, RDF/JSON,
   or N-Triples through **ARC2**; `jsonld` routes through `to_jsonld()` for the
   same schema.org shape.
3. Otherwise pick an XSLT stylesheet by a **cascading file lookup** keyed on the
   page `lid` and output format (`transform()` in [luna.php](../luna/luna.php)):
   domain override → built-in, page-specific → `default`.
4. Call `lunaModel::transform($XSLfile)`, which first re-keys the working
   (nid-keyed) in-memory model to slug identity and schema.org via
   `project_to_schema()` — `/node/{nid}` subjects become `/id/{slug}`,
   `luna:page`/`luna:text` become `schema:WebPage`/`schema:Article`, and
   `luna:nid`/`rdfs:label`/`luna:page` map to `schema:identifier`/`schema:name`/`schema:isPartOf`
   — then serialises that graph to RDF/XML via ARC2 and runs PHP's
   `XSLTProcessor`, so the stylesheets render from the same schema.org/`/id/{slug}`
   graph as the triplestore. The result is cached (lunaCache, the native file
   cache) keyed on a hash of the model.

The returned string is the HTTP response. See [templating.md](templating.md) for
the stylesheet lookup order and the schema.org/`/id/{slug}` RDF/XML (plus the
`ui:` render-model nodes) the templates expect.

> **Semantic-web layer:** the model additionally exposes a JSON-LD projection — `?output=jsonld` routes through
> `lunaModel::to_jsonld()`, and the HTML `<head>` carries an embedded
> `<script type="application/ld+json">` block. The read path flows through a
> SPARQL endpoint **by default** (routing, level-based ACL, and page content),
> with `?sparql=0` to fall back to the SQL path described above, and
> every content write mirrors into the triplestore. See [linked-data.md](linked-data.md).

## Request lifecycle diagram

```
HTTP request
   │  (.htaccess rewrite → index.php?path=…)
   ▼
luna::singleton()  ── bootstrap ──────────────────────────────┐
   │  • pick domain (HTTP_HOST → luna.domains/…)               │
   │  • load luna.ini → constants                              │
   │  • connect MySQL (PDO)                                    │
   │  • start DB-backed session, build $user (groups/levels)   │
   │  • negotiate language + output format                     │
   │  • build lunaModel (triple store)                         │
   │  • resolve URL → page node (404 if none)                  │
   │  • check_privileges (→ login if unauthorised)             │
   │  • load page texts                                        │
   ▼                                                           │
load_mods()                                                    │
   │  • SQL: mods linked to page ∩ user's levels               │
   │  • for each mod: require, singleton, submit*?, load()     │
   ▼                                                           │
transform()                                                    │
   │  • merge user / langs / formats / data / request          │
   │  • output=xml|turtle|n3|json|jsonld → dump() & exit       │
   │  • else: pick XSLT stylesheet (cascading lookup)          │
   │  • lunaModel: model → RDF/XML → XSLTProcessor → HTML      │
   ▼                                                           │
HTTP response  ◄───────────────────────────────────────────────┘
```

## Error handling

Errors are exceptions of type `lunaException`. Each phase wraps its body in a
`try`/`catch (lunaException)` that passes the exception to `lunaLog::log()`
([luna.log.class.php](../luna/luna.classes/luna.log.class.php)). `log()`
writes a JSON record to the `luna_logs` table via a direct PDO `INSERT` (it
stores JSON, never `serialize()`, so the journal reader never has to
`unserialize()` attacker-influenceable data). The record carries a minimal
session summary (acting user's name/email) and a small `$_SERVER` whitelist
(`REMOTE_ADDR`, `REQUEST_METHOD`, `REQUEST_URI`, `SERVER_NAME`, `HTTP_HOST`,
`HTTP_USER_AGENT`, `HTTP_REFERER`, `REQUEST_TIME`) — not the full `$_SERVER`,
which carries the cookie/session id and auth headers; see [security.md](security.md).
A logging failure is swallowed (never re-thrown, to avoid recursing through the
exception path). HTTP error pages (404, etc.) are produced by
`lunaTools::raise_error_page()`.
