# Third-party notices

Luna itself is licensed under the **GNU GPL v2** (see [LICENSE](LICENSE)).
It bundles third-party code two ways: one component vendored in-tree under
`luna/luna.lib/arc/` (committed, no installer), and a set of **Composer** dependencies under
`vendor/` (committed too, so the app stays clone-and-run). Each retains its own license
and copyright; the per-file headers are authoritative.

ARC2 is the only vendored in-tree library, and stays vendored on purpose: it carries
local PHP-8 patches the upstream 3.1.0 lacks (stock 3.1.0 fatals on `?output=n3`), so it
is not a drop-in Composer package — see
[`luna.lib/arc/VENDOR.txt`](luna/luna.lib/arc/VENDOR.txt). HTMLPurifier and
league/commonmark, by contrast, are unmodified, actively-maintained Composer
dependencies (commonmark pulls a handful of small transitive deps, listed below).

| Component | Location | Upstream | License (per file headers / upstream) |
|---|---|---|---|
| **ARC2** (RDF/SPARQL library) | `luna/luna.lib/arc/` | github.com/semsol/arc2 | semsol/arc2 3.1.0 — GPL-2.0-or-later / W3C Software License. **Locally PHP-8/UTF-8 patched** (N-Triples serializer); see [`VENDOR.txt`](luna/luna.lib/arc/VENDOR.txt). |
| **HTMLPurifier** (input sanitiser) | `vendor/ezyang/htmlpurifier/` (Composer) | github.com/ezyang/htmlpurifier | ezyang/htmlpurifier 4.19.0 — LGPL-2.1-or-later (© Edward Z. Yang) |
| **league/commonmark** (Markdown→HTML renderer) | `vendor/league/commonmark/` (Composer) | github.com/thephpleague/commonmark | league/commonmark 2.8.2 — BSD-3-Clause (© Colin O'Dell) |
| **commonmark transitive deps** | `vendor/` (Composer) | league/config, dflydev/dot-access-data, nette/schema, nette/utils, psr/event-dispatcher, symfony/deprecation-contracts, symfony/polyfill-php80 | BSD-3-Clause / MIT (per package; nette also offers GPL-2.0/3.0) |

The front-end is dependency-free vanilla JavaScript ([js/luna.js](js/luna.js)) — no CDN,
no framework. The admin editor is a plain `<textarea>` holding **Markdown** source (no
bundled rich-text editor); Markdown is rendered to HTML server-side by league/commonmark.

**Build-time (SCSS):** `scss/vendor/_baselinegrid.scss` is the
[baselinegrid.scss](https://github.com/jeromev/baselinegrid.scss) toolkit (v3.0.1,
MIT © Jérôme Vogel), vendored to compile the baseline-grid typography into
`css/luna.css`. Build-time only — not shipped to the browser.

## Inlined third-party functions

A few small utility functions were copied — with attribution in their
docblocks — directly into the app code rather than vendored as packages. All are
GPL-compatible with Luna's own GPL v2:

| Function(s) | File | Origin | License |
|---|---|---|---|
| `encode_ip()` / `decode_ip()` | [luna.tools.class.php](luna/luna.classes/luna.tools.class.php) | phpBB 2.0.5 | GPL v2 (© 2001 The phpBB Group) |
| `remove_accents()` | [luna.tools.class.php](luna/luna.classes/luna.tools.class.php) | WordPress 2.0.4 | GPL v2 (© 2006 WordPress) |
| `set_site_path()` (`conf_path`/`conf_init`) | [luna.php](luna/luna.php) | Drupal 5.1 `bootstrap.inc` | GPL v2 |
| `array_to_object()` | [luna.tools.class.php](luna/luna.classes/luna.tools.class.php) | lost-in-code.com (2008 blog post) | no license stated by the original author |

## Notes

- The project license (GPL v2) governs Luna's own code. The bundled
  components above are independent works, aggregated for convenience; their
  permissive/LGPL/BSD/MIT terms are compatible with redistribution here. If you
  repackage or relicense, consult each component's own license text and headers.
- Where a license column cites the upstream project rather than an inline header,
  the vendored copy did not carry a license header inline; verify against the
  upstream project if it matters to you.
