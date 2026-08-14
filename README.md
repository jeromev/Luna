# LunarSystem

A PHP/MySQL CMS that models all content as **RDF triples** and renders pages through **XSLT transformations**.

> ⚠️ **Study / experiment artifact — run it on `localhost` only.** This is alpha-grade
> 2006–2010 code revived for learning. It has bcrypt passwords, CSRF tokens,
> session-fixation defence, SQLi fixes, security headers, per-target admin authorization,
> an authenticated SPARQL proxy and a per-IP login throttle — but it ships with demo
> credentials and is not hardened for any networked deployment, so keep it on `localhost`
> and off the public internet. See [docs/security.md](docs/security.md).
> The Docker stack binds every host port to `127.0.0.1` (and keeps the semantic-web
> services off the host entirely); **do not change that or otherwise expose `8080` /
> `3307` to a public or untrusted network.** It is not hardened for any networked or
> production deployment. See [docs/security.md](docs/security.md).
>
> **Deploying for real?** That warning is about the *full Docker stack* (triplestore
> included), which stays on `localhost`. The **publishing surface** — the HTML +
> content-negotiated RDF/JSON-LD pages, served from MySQL with no triplestore — *is* built
> to go on a public domain (e.g. DreamHost shared); follow the hardening runbook in
> **[docs/going-public.md](docs/going-public.md)**.

> **An RDF-native Semantic Web CMS.** Beyond the archival CMS core, it is a *real* Semantic Web CMS: a frozen URI policy and a vocabulary mapping onto schema.org / Dublin Core / SIOC / FOAF / PROV-O, a JSON-LD projection, and a **triplestore-backed read/write loop**. Every content write mirrors into **Oxigraph** via SPARQL `UPDATE` (a generic write-through in the model's CRUD), and the read path (routing, access control, texts) is served **from the triplestore by default**, with MySQL as the system of record and an automatic SQL fallback (`?sparql=0` to bypass). The same SPARQL can also be served by **Ontop** (a virtual endpoint over the unchanged MySQL) with no app change. The untouched archival CMS is preserved on the **`legacy`** branch. See **[docs/linked-data.md](docs/linked-data.md)** for the full design and **[docs/roadmap.md](docs/roadmap.md)** for what remains.

## Quick start (Docker)

```bash
docker-compose up --build -d
```

Wait ~15 seconds for MySQL to initialise, then open **http://localhost:8080**.

> The Docker stack also starts a **triplestore** (Oxigraph) behind an authenticating reverse proxy (`sparql-proxy`) and a virtual **SPARQL endpoint** (Ontop), all on the internal compose network (no host port). The app reads and writes the triplestore through the proxy with credentials; the read path is served from it by default — append `?sparql=0` to any URL to read from MySQL instead, or set `SPARQL_ENDPOINT=http://ontop:8080/sparql` to read live through Ontop. See [docs/linked-data.md](docs/linked-data.md).

Log in as **`admin@lunarsystem.local`** with password **`luna`**. (These are demo credentials shipped in the seed data — change them before exposing the app anywhere.)

> **New here?** Follow the ~10-minute hands-on tour in **[docs/try-it.md](docs/try-it.md)**.

> MySQL is exposed on host port `3307` to avoid conflicts with a local MySQL on `3306`.

## Manual setup

Requirements: Apache 2 + `mod_rewrite`, **PHP 8.3** (the tested stack), MySQL 8.0, PHP extensions: `pdo_mysql`, `xsl`, `mbstring`, `gettext`.

1. Copy `luna/luna.domains/luna.default/ini/db.example.ini` → `db.ini` and fill in your credentials.  
   *(Docker users can skip this — the stack passes `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` to the app, so no `db.ini` is needed.)*
2. Import `luna/luna.sql/luna.mysql.sql` into your MySQL database.
3. Ensure the `luna/luna.domains/<your-domain>/cache/` directory is writable by the web server.
4. The root `.htaccess` handles clean URL rewriting — confirm `AllowOverride All` is set in Apache.
5. Open the site in a browser.

### Domain configuration

The CMS detects the active domain by walking `$_SERVER['HTTP_HOST']` and looking for a matching directory under `luna/luna.domains/`. If none is found it falls back to `luna/luna.domains/luna.default/`.

To add a site-specific config: create `luna/luna.domains/<hostname>/ini/luna.ini` and `db.ini`.

## Output formats & Linked Data

The same URL serves HTML to a browser and RDF to a machine by **HTTP `Accept`** content
negotiation (`text/turtle`, `application/ld+json`, `application/rdf+xml`, …). Every resource
also has a dereferenceable identity URI — `/id/{slug}` (303s to the right document) and
`/data/{slug}` (the RDF document). `?output=xml|turtle|json|n3|jsonld` forces a format without
an `Accept` header, a JSON-LD block is embedded in every page `<head>`, and `/sitemap.xml` +
`/robots.txt` are served for crawlers. See [docs/linked-data.md](docs/linked-data.md).

## Project structure

```
index.php                      Entry point
.htaccess                      Clean URL rewrite rules
luna/
  luna.php                     Main luna class (bootstrap, routing, XSLT rendering)
  luna.classes/
    luna.model.class.php       RDF model (in-memory triple store, ARC2, XSLT)
    luna.db.class.php          Database wrapper (PDO / pdo_mysql)
    luna.session.class.php     DB-backed session handler
    luna.tools.class.php       Utilities (sanitisation, URL building, i18n, ACL)
    luna.log.class.php         Error logging (direct PDO INSERT)
    luna.cache.class.php       Native file cache (lunaCache)
  luna.mods/                   Pluggable page modules (admin, journal, node, …)
  luna.xsl/luna.html.xsl/      Built-in XSLT templates (HTML output)
  luna.lib/arc/                Vendored library: semsol/arc2 (RDF/SPARQL), locally PHP-8/UTF-8 patched
  luna.domains/
    luna.default/              Fallback site config + per-domain data (ini, cache, locale)
      locale/                  gettext catalogs (en_US, fr_FR): engine 'luna' + 'local' overrides
  luna.sql/luna.mysql.sql      Database schema + seed data
vendor/                        Composer dependencies (HTMLPurifier sanitiser + league/commonmark Markdown renderer); committed for clone-and-run
css/                           Stylesheets
js/                            luna.js (admin UI behaviours; dependency-free vanilla JS)
semantic/                      Semantic-web layer (Ontop virtual SPARQL + Oxigraph triplestore)
  ontop/                       R2RML mapping + Ontop image (virtual SPARQL); Oxigraph dump
  sparql-proxy/                Caddyfile: authenticating reverse proxy in front of Oxigraph
```

## Known issues

The major security issues are closed; an adversarial review graded the result
*ship-with-low-risk*. See [docs/security.md](docs/security.md) for the current posture and
verdict. The residual, by-design limitations:

| Issue | Impact | Notes |
|---|---|---|
| **Per-IP login throttle** | Security | Per-IP only (no per-account lockout, to avoid account enumeration); bypassable by IP rotation |
| **Ontop SPARQL is unauthenticated** | Security | The virtual (read-only) Ontop endpoint has no auth; it has no host port and stays on the internal compose network. Oxigraph's write endpoint is authenticated via `sparql-proxy` |
| **Legacy model / hardening residue** | Design | Unsalted MD5 hashes upgrade to bcrypt transparently on next login; flat group→level authz model |

The Docker stack boots cleanly on **PHP 8.3 + MySQL 8.0** (PDO). See the [changelog](CHANGELOG.md) and [docs/installation.md](docs/installation.md).

## Documentation

Full technical documentation lives in [docs/](docs/):

- [Overview](docs/overview.md) — the big idea and glossary
- [Try it](docs/try-it.md) — a ~10-minute hands-on Semantic-Web lab
- [Architecture](docs/architecture.md) — request lifecycle and core classes
- [RDF model](docs/rdf-model.md) — the in-memory triple store and SQL→RDF projection
- [Database schema](docs/database-schema.md) — every table and the seed data
- [Modules](docs/modules.md) — the mod system and a mod-authoring guide
- [Templating](docs/templating.md) — XSLT rendering and output formats
- [Configuration](docs/configuration.md) — domains, `luna.ini`, `db.ini`
- [Installation](docs/installation.md) — Docker and manual setup
- [Security](docs/security.md) — known issues and hardening
- [Linked Data](docs/linked-data.md) — the Semantic Web layer (URI policy, vocabularies, JSON-LD, SPARQL via Ontop & Oxigraph)
- [Why RDF](docs/why-rdf.md) — what going RDF-native unlocks, in plain English (and what's still roadmap)
- [Roadmap](docs/roadmap.md) — what's next: single source of truth, semantics, a data-first server
- [Going public](docs/going-public.md) — safety posture and deliberate scope for the public repo

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). The `LICENSE` file is the GPL v2 text; the per-file source
headers grant version 2 "or (at your option) any later version," so the effective license is v2-or-later.
