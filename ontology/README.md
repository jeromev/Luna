# `luna:` ontology

The vocabulary served at the namespace IRI **`https://jeromev.github.io/Luna/ontology#`**.

- [`ontology.ttl`](ontology.ttl) — the formal vocabulary (Turtle).
- [`index.html`](index.html) — human-readable rendering of the same terms.

## Source of truth

The namespace string lives in **one** place in code: the `LUNA_NS` constant in
[`luna/luna.classes/luna.model.class.php`](../luna/luna.classes/luna.model.class.php)
(the `$lunaNameSpace` property and every SPARQL `PREFIX luna:` read from it). Two things
must match it by hand and are **not** auto-derived:

1. the 15 `xmlns:luna="…"` declarations in `luna/luna.xsl/luna.html.xsl/*.xsl`
   (one per stylesheet; they must equal the model's RDF output exactly, or the XSLT
   templates stop matching). Each of those files also declares the sibling
   `xmlns:ui="https://jeromev.github.io/Luna/render#"` render namespace
   (`lunaModel::LUNA_RENDER_NS`), which drives the XSLT chrome and is *not* part of
   this content vocabulary;
2. `semantic/ontop/mapping.ttl` (the R2RML mapping).

After changing the namespace, run **`make resync-triplestore`** so the stored Oxigraph
triples are re-projected under the new IRI.

## Making the IRI dereference (GitHub Pages)

The IRI is a stable *identifier* and the app works whether or not it resolves. To make it
*dereference* (GET the IRI → get this vocabulary), publish this folder with GitHub Pages:

- **Settings → Pages →** deploy from the `main` branch. If you serve from the repo root,
  this folder is reachable at `https://jeromev.github.io/Luna/ontology/`
  (a request for `…/ontology` 301-redirects to `…/ontology/`, so `…/ontology#term` resolves).
- Prefer not to publish the whole repo? Serve Pages from a `/docs` folder or a dedicated
  `gh-pages` branch and copy `ontology/` there instead.

Caveat: GitHub Pages can't content-negotiate (no `Accept`-based Turtle vs HTML, no 303), so
the practical dereference target is the human-readable `index.html`; machine clients can fetch
`ontology.ttl` directly. If you ever need a permanent, content-negotiable IRI (e.g. to publish
the vocabulary for others), register a [w3id.org](https://w3id.org) redirect — then only the
redirect ever changes, never the namespace IRI.
