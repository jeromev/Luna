# Going public — deploy the publishing surface

Luna runs in two shapes:

| Shape | What runs | Where |
|---|---|---|
| **Full stack** | app + MySQL **+ the triplestore** (Oxigraph, Ontop, `sparql-proxy`) | **localhost only** — the Docker study/experiment setup |
| **Publishing surface** | app + MySQL only (no triplestore) | **deployable to a real domain** (e.g. DreamHost shared) |

The **publishing surface** is everything a reader or a machine consumes — HTML pages, the
content-negotiated RDF (`Accept:` → Turtle / JSON-LD / RDF-XML), the dereferenceable
`/id/{slug}` and `/data/{slug}` URIs, the embedded JSON-LD, the outbound links, and
`/sitemap.xml` + `/robots.txt`. It is built **entirely from MySQL in PHP**, so it needs no
triplestore and runs on ordinary shared hosting. This doc is the runbook for putting it on
a public domain.

## Why the publishing surface is safe to expose

- **It's read-only public data plus one hardened form.** Everything public is content the
  graph already serves; the only state-changing entry is the admin login, which has bcrypt
  passwords, CSRF tokens, session rotation, per-target authorization, an admin-lockout
  guardrail and a per-IP login throttle (see [security.md](security.md)). On HTTPS the app
  adds HSTS and `upgrade-insecure-requests`, and marks cookies `Secure`.
- **No SPARQL endpoint is exposed.** With `SPARQL_ENABLED=0` (below) the whole triplestore
  layer — read *and* write-through — is off, so there is no SPARQL attack surface at all; the
  app serves from MySQL only.
- **No secrets in the repo.** `db.ini` is gitignored and was never committed.

## What stays off the public host

The **triplestore + SPARQL stack** (Oxigraph, Ontop, `sparql-proxy`) is Docker-only — it
needs long-running processes and custom ports a shared host won't give you, and an open
SPARQL endpoint is its own hardening project. Leave it local (or, if you ever want a live
query endpoint, put it on a small VPS). With `SPARQL_ENABLED=0` the public app never reaches
for it. (The "Ontop endpoint is unauthenticated" caveat is therefore moot on a public deploy.)

> **Note the tension with roadmap P2.** This MySQL-only profile *authors* content in MySQL. P2 (make
> the triplestore the single source of truth) would move the write there — which this deploy shape
> can't host. So the two are, as written, in conflict: doing P2 as specified makes this profile
> read-only. See the "Tension with the public deploy" note under P2 in [roadmap.md](roadmap.md);
> resolve it (VPS, or keep MySQL as the authoring store) before starting P2.

## Runbook (DreamHost shared, or any PHP 8 + MySQL host)

1. **Preflight the host.** Over SSH on the target:
   ```bash
   php bin/preflight.php
   ```
   It must print **READY** — it checks PHP ≥ 8.1 and the required extensions, notably **`xsl`**
   (the XSLT renderer needs it; some shared hosts omit it). In the DreamHost panel, select
   **PHP 8.1+** for the domain if a check fails.

2. **Get the code onto the domain's web root.** The `vendor/` tree is committed, so there's
   no `composer install` step. (Ideally the document root is a subdirectory and you point the
   vhost at the repo, not the other way round.)

3. **Database.** Create a MySQL database in the panel, then:
   ```bash
   mysql -u <user> -p <database> < luna/luna.sql/luna.mysql.sql   # run the server with sql_mode="" (legacy column defaults)
   ```
   Copy `luna/luna.domains/luna.default/ini/db.example.ini` → `db.ini` and fill in the
   credentials. Make `luna/luna.domains/luna.default/cache/` writable by the web server.

4. **Turn the triplestore off** — in the site's `.htaccess`:
   ```apache
   SetEnv SPARQL_ENABLED 0
   ```
   The app now serves the whole publishing surface from MySQL, with no failed reach for an
   endpoint that isn't there.

5. **Force HTTPS.** Enable the free Let's Encrypt certificate in the DreamHost panel, then
   uncomment the **force-HTTPS** block (and, optionally, the canonical-host block) at the top
   of [`.htaccess`](../.htaccess).

6. **Change the admin credentials — do this first, before announcing the URL.** The seed ships
   `admin@luna.local` / `luna`, which is public knowledge. Log in, then change the
   password (and email) under **Admin → Users**.

7. **Curate your outbound links.** Edit [`semantic/links.ttl`](../semantic/links.ttl) so your
   resources point at real external entities (Wikidata, ORCID, your other sites). On the
   publishing surface these are projected straight into `/data/{slug}` and the JSON-LD from
   the file — no resync needed.

8. **Verify, then announce.** Over `https://yourdomain`:
   - the home page renders, and `curl -H 'Accept: text/turtle' https://yourdomain/` returns RDF;
   - `https://yourdomain/sitemap.xml` lists your public pages and `https://yourdomain/robots.txt`
     points at it;
   - the page `<head>` carries a JSON-LD block.
   Then submit the sitemap to Google Search Console so the data gets discovered.

## Residual posture

This is still alpha software. The public surface is read-only data plus a hardened login, not
a fortress: keep the host's PHP patched, rotate the admin credentials, keep `db.ini` out of
git, and keep the triplestore stack off the public host. See [security.md](security.md) for
the full posture.
