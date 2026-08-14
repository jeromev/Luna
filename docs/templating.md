# Templating (XSLT)

Luna's view layer is **XSLT 1.0**. The RDF model is serialised to RDF/XML
and a stylesheet transforms it into XHTML (or any text output). There are no PHP
templates and no template engine — the entire presentation layer is XSL.

## Where stylesheets live

| Location | Role |
|---|---|
| [luna/luna.xsl/luna.html.xsl/](../luna/luna.xsl/luna.html.xsl/) | The built-in HTML stylesheets (the default theme) |
| `luna.domains/<domain>/xsl/html.xsl/` | Per-domain overrides (optional; none ship in the repo) |
| `luna.domains/luna.default/xsl/` | Empty — the default domain falls back to the built-ins |

> **Naming:** built-in stylesheets carry a `luna.` prefix (e.g.
> `luna.admin.html.xsl`); per-domain override stylesheets **drop** it (e.g.
> `admin.html.xsl`). This matches the cascade below — the `SITEPATH` (domain)
> branches look for the un-prefixed name, the `XSL_PATH` (built-in) branches for
> the `luna.`-prefixed one.

Each output format has its own directory convention: `<format>.xsl/` (so
`html.xsl/`, and by extension the XML/JSON/N3 paths, though non-HTML formats are
serialised directly by ARC2 rather than via XSLT — see below).

## Stylesheet selection (the cascade)

`luna::transform()` ([luna.php](../luna/luna.php)) picks a stylesheet by
trying paths in order, first hit wins (the cascade itself is
`transform()` in [luna.php](../luna/luna.php)). Roughly, for output format `html` and a
page whose `lid` is `$lid`:

1. `SITEPATH/xsl/html.xsl/<lid>.html.xsl` — domain, page-specific
2. `SITEPATH/xsl/html.xsl/default.html.xsl` — domain, default
3. `SITEPATH/xsl/<lid>.xsl` — domain, flat (no format subdir), page-specific
4. `SITEPATH/xsl/default.xsl` — domain, flat, default
5. `XSL_PATH/luna.html.xsl/luna.<lid>.html.xsl` — built-in, page-specific
6. `XSL_PATH/luna.<lid>.xsl` — built-in, flat, page-specific
7. `XSL_PATH/luna.html.xsl/luna.default.html.xsl` — built-in, default

This is why most pages need no stylesheet of their own: they fall through to
`luna.default.html.xsl`. Admin pages each have a matching
`luna.<lid>.html.xsl`.

## How the built-in stylesheets are organised

The built-ins use a shared base + includes pattern. Sampling
[luna/luna.xsl/luna.html.xsl/](../luna/luna.xsl/luna.html.xsl/):

| File | Role |
|---|---|
| `luna.header.html.xsl` | The XHTML skeleton (`<html>`/`<head>`/`<body>`), namespace declarations, global variables (`$site_uri`, `$lang`, `$masternodelid`, `$masternodenid`, `$cleanurls`, …), and client-side JS bootstrap vars. Matches the `/rdf:RDF` root. |
| `luna.common.html.xsl` | Shared utility templates: string truncation (`cutstring`), form-input rendering (`forminput`, renders inputs/selects/textareas), message rendering (matches `ui:message`), pagination URL building (`buildSortURL`), loops. |
| `luna.common_admin.html.xsl` | Admin UI helpers: list renderers (`groupslist`, `levelslist`), `online_users`. |
| `luna.default.html.xsl` | The fallback content page — iterates `schema:Article` nodes whose `schema:isPartOf` matches the current page and emits `<div class="box text">` with the title (`schema:name`) and the rendered `ui:content` (the article's `luna:content` Markdown converted to HTML — see below). |
| `luna.<page>.html.xsl` | One per admin page: `admin`, `admin_groups`, `admin_levels`, `admin_mods`, `admin_pages`, `admin_users`, `edit_texts`, `journal`, `login`, `logout`, `root`. Each sets a `$mod_lid` and includes the header/common templates. |

A page-specific stylesheet typically declares the `$mod_lid` it renders, includes
`luna.header.html.xsl`, and provides `xsl:template match=…` rules for the nodes
that mod merges into the model.

## The input XML

Templates consume the RDF/XML serialisation of the model. The serialiser is fed
`lunaModel::project_to_schema($this->index)` ([luna.model.class.php](../luna/luna.classes/luna.model.class.php)),
so the XSLT renders from the **schema.org + `/id/{slug}`** graph: content nodes are
`<schema:WebPage>` (pages) / `<schema:Article>` (text blocks) / `<foaf:Person>` (users),
the app-specific UI render-model is in the `ui:` namespace
(`https://jeromev.github.io/Luna/render#`), and the `luna:` namespace carries
only the genuinely app-specific *content* terms that survive the projection (`luna:lid`,
`luna:content`, `luna:isActive`, `luna:alias`, `luna:level`, plus runtime flags like
`luna:is_current`/`luna:is_guest`). `luna:content` holds the **Markdown source**; for the
HTML view `project_to_schema()` adds a transient `ui:content` literal carrying that Markdown
rendered to safe HTML (raw HTML escaped, unsafe links dropped), which the `default`/`root`
templates emit with `disable-output-escaping`. The published RDF (`?output=*`) carries only
the Markdown `luna:content` — `ui:content` never leaves the render path. A representative
fragment:

```xml
<rdf:RDF
    xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
    xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
    xmlns:schema="https://schema.org/"
    xmlns:luna="https://jeromev.github.io/Luna/ontology#"
    xmlns:ui="https://jeromev.github.io/Luna/render#"
    xmlns:foaf="http://xmlns.com/foaf/0.1/">

  <ui:data ui:lid="clean_urls"><ui:value>1</ui:value></ui:data>
  <ui:data ui:lid="lang"><ui:value>en</ui:value></ui:data>

  <schema:WebPage rdf:about="https://example.org/id/root">
    <schema:identifier>9</schema:identifier>
    <luna:lid>root</luna:lid>
    <schema:name xml:lang="en">Home</schema:name>
  </schema:WebPage>

  <schema:Article>
    <schema:isPartOf rdf:resource="https://example.org/id/root"/>
    <schema:name xml:lang="en">Welcome</schema:name>
    <luna:content xml:lang="en">## Hello</luna:content>
    <ui:content xml:lang="en"><![CDATA[<h2>Hello</h2>]]></ui:content>
  </schema:Article>

  <foaf:Person luna:is_current="1">
    <luna:is_guest>0</luna:is_guest>
  </foaf:Person>

  <ui:message>
    <ui:code>warning</ui:code>
    <ui:value>Something went wrong.</ui:value>
  </ui:message>
</rdf:RDF>
```

Stylesheets match on these element/attribute patterns — e.g. the default page
template selects `schema:Article` whose `schema:isPartOf/@rdf:resource` equals the
current page's IRI, and `luna.common.html.xsl` matches `ui:message` to render flash
messages.

## Non-HTML output

Appending `?output=xml|json|n3` bypasses XSLT entirely:
`luna::transform()` calls `lunaModel::dump($format)`, which serialises the model
with **ARC2** (RDF/XML, RDF/JSON, or N-Triples) and exits with the appropriate
content type. Both `dump()` and the HTML `transform()` serialise the
schema.org + `/id/{slug}` projection of the model, so any page is also a
machine-readable RDF endpoint over the same clean graph. See [rdf-model.md](rdf-model.md).

> **Linked Data:** a fifth format `?output=jsonld`
> is registered in `luna::$output_formats` and serialised by
> `lunaModel::to_jsonld()` (a schema.org JSON-LD projection), *not* by ARC2. The
> same JSON-LD is also embedded in every HTML page: after the XSLT transform,
> `luna::transform()` injects a `<script type="application/ld+json">` block before
> `</head>` (`luna::transform()`, [luna.php](../luna/luna.php)) — done in PHP post-processing,
> not in the stylesheets, so `luna.header.html.xsl` is unchanged. See
> [linked-data.md](linked-data.md).

## Caching

Transformed HTML is cached via **lunaCache**
([luna/luna.classes/luna.cache.class.php](../luna/luna.classes/luna.cache.class.php)),
the native file cache, keyed on a hash of the serialised
model. Admin users bypass the cache (the `IS_ADMIN` path in bootstrap disables
it), so edits are seen immediately. Caching can be toggled per domain with the
`CACHE` constant in `luna.ini` and flushed via `lunaTools::purge_cache()`.
