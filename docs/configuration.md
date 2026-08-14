# Configuration

Luna is **multi-site**: a single codebase serves many hostnames, each with
its own configuration directory under
[luna/luna.domains/](../luna/luna.domains/). Configuration is split between two
INI files per domain (`luna.ini`, `db.ini`) and the runtime `luna_config` table.

## Domain resolution

At bootstrap, `luna::set_site_path()`
(the Drupal-derived block inside `luna::set_site_path()`, [luna.php](../luna/luna.php))
— logic adapted from **Drupal 5.1's
`conf_path()`** — walks `$_SERVER['HTTP_HOST']` from most- to least-specific,
looking for a directory under `luna.domains/` that contains `ini/luna.ini`. The
first match wins; if none matches it falls back to
`luna.domains/luna.default/`.

For `www.example.org:8080/sub/` it tries, in order:
`8080.www.example.org.sub`, `www.example.org.sub`, `example.org.sub`,
`org.sub`, … `example.org`, `org`, then `luna.default`.

The resolved directory becomes the `SITEPATH` constant.

## Domain directory layout

```
luna.domains/<domain>/
  ini/
    luna.ini          # paths, table names, constants  (required — its presence selects the domain)
    db.ini            # database credentials
    luna.example.ini  # template (default domain only)
    db.example.ini    # template (default domain only)
  cache/              # lunaCache output cache (must be web-server-writable)
  xsl/                # per-domain XSLT overrides (optional; empty falls back to built-ins)
  mods/               # per-domain mods (optional)
```

One domain ships in the repo:

| Domain | Role |
|---|---|
| `luna.default/` | Fallback config, wired for local/Docker development |

## `luna.ini`

Parsed by `luna::load_ini()` ([luna.php](../luna/luna.php)). Every
`[Paths]` value becomes a constant prefixed with the luna root path; every
`[Constantes]` value becomes a bare constant. Example
(`luna.default/ini/luna.ini`):

```ini
[Paths]
MODS_PATH    = "luna.mods/"      ; built-in mods directory
CLASSES_PATH = "luna.classes/"   ; core classes
LOCALE_PATH  = "luna.domains/luna.default/locale/"  ; gettext catalogs (default-domain fallback; the active domain's own locale/ wins if present)
XSL_PATH     = "luna.xsl/"       ; built-in stylesheets

[DBtables]                        ; logical name → physical table
CONFIG    = "luna_config"
LOGS      = "luna_logs"
CLASSES   = "luna_types"
NODES     = "luna_nodes"
NODES_MAP = "luna_nodes_map"
SESSIONS  = "luna_sessions"
ACTIONS   = "luna_actions"
TEXTS     = "luna_texts"
USERS     = "luna_users"
THROTTLE  = "luna_login_throttle"

[Constantes]
ANONYMOUS  = "guest"   ; lid of the anonymous user
PERPAGE    = "20"      ; default pagination size
CACHE      = 0         ; 1 = enable the native file cache (lunaCache) output cache
INCLUDEPATH = ""       ; extra PHP include path(s)
CLEAN_URLS = 1         ; 1 = /path style, 0 = ?path= query style
DEBUG      = 0         ; 1 = display errors + dump logs to admins
```

The `[DBtables]` indirection is why the code never hard-codes table names — it
calls `luna::get_ini('DBtables', 'NODES')` etc. You could repoint the CMS at
differently-named tables without touching code.

## `db.ini` (database credentials)

`lunaDB::prepare()` ([luna.db.class.php](../luna/luna.classes/luna.db.class.php))
resolves the DB credentials and builds the PDO DSN with two sources, in order of
precedence:

1. **`db.ini`** in the domain's `ini/` directory — if present, it wins. Copy
   `db.example.ini` to `db.ini` and fill in:

   ```ini
   driver   = "mysql"
   username = "username"
   password = "password"
   host     = "localhost"
   database = "database"
   ```

2. **Environment variables** `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASS` — used
   when no `db.ini` exists. The Docker stack passes these through
   `docker-compose.yml`, so a clone-and-run install connects with no `db.ini` to
   provision. `host` defaults to `localhost` if empty.

`db.ini` is gitignored and never shipped; if neither source supplies a database
and username, `prepare()` throws. The connection charset is always `utf8mb4`.

## Deployment settings (environment / `SetEnv`)

The app reads these deployment settings as constants in [luna.php](../luna/luna.php), each via
`luna_env()` — which checks the real environment **and** `$_SERVER` (so a shared host with no shell/env
control can still set them from `.htaccess` with `SetEnv`) — and wired through `docker-compose.yml`:

- **`SITE_URI`** — the canonical identity base (e.g. `https://example.org`, or with a subdirectory
  `https://example.org/luna`). When set it pins the absolute URIs the app mints — RDF `/id/{slug}` IRIs,
  JSON-LD `@id`, `canonical`/`Link` headers, the sitemap — so identity does **not** depend on the request
  `Host` header (the "freeze the URIs" rule; see [roadmap.md](roadmap.md)). Leave empty on localhost /
  single-host dev to derive it from the request. **Set this before any triplestore-backed public deploy**,
  and keep it matching `bin/resync-triplestore.php`'s `SITE_URI` or the graph's URIs will diverge.
- **`SPARQL_ENABLED`** — the master switch for the whole triplestore layer (read path *and*
  write-through). Default `1`; set `SPARQL_ENABLED=0` for a pure PHP/MySQL deploy with no triplestore —
  the publishing surface (HTML + content-negotiated RDF/JSON-LD + `/id` + `/data` + sitemap) then runs
  entirely from MySQL and nothing reaches for an endpoint that isn't there. This is the profile
  [going-public.md](going-public.md) deploys.
- **`TRUST_PROXY`** — set to `1` only behind a known reverse proxy, so `X-Forwarded-Proto` is honoured
  for the HTTPS/HSTS/secure-cookie decisions; off by default so the header can't be spoofed on a
  directly-served host.
- **`SPARQL_ENDPOINT`** — the read endpoint, defaulting to the Oxigraph
  triplestore fronted by the authenticating proxy
  (`http://sparql-proxy:7878/query`); set it to `http://ontop:8080/sparql`
  to read live over MySQL through Ontop instead (no code change).
- **`SPARQL_UPDATE_ENDPOINT`** — where content writes mirror to
  (`http://sparql-proxy:7878/update`, best-effort).
- **`SPARQL_AUTH_USER`** / **`SPARQL_AUTH_PASS`** — basic-auth credentials the
  app presents to the proxy (demo defaults `luna` / `luna-sparql-dev`). Oxigraph
  has no native auth, so it lives only on an internal compose network reachable
  solely by `sparql-proxy` (Caddy), which demands HTTP basic auth on every
  request before forwarding to `oxigraph:7878`. The model adds the
  `Authorization` header via `sparql_auth_header()` on both reads and writes.
  Override `SPARQL_AUTH_PASS` via `.env` (gitignored) for any real use.
- **`SPARQL_READS`** — whether the read path uses SPARQL at all (default `1`);
  `SPARQL_READS=0`, or `?sparql=0` on any URL, forces the SQL read path.

None of these are required for the core CMS to run — see [linked-data.md](linked-data.md).

## `luna_config` (runtime config)

Editable site settings live in the `luna_config` table (see
[database-schema.md](database-schema.md)), loaded at bootstrap by
`lunaTools::load_config()` and editable from **Admin** (`mod_admin`). These are
*content-level* settings (site name, description, author, email, keywords,
timezone, languages, session length, cache timeout) as opposed to the
*infrastructure-level* settings in the INI files.

## Where settings live — summary

| Setting kind | Lives in | Edited by |
|---|---|---|
| Domain selection | directory name under `luna.domains/` | filesystem |
| Paths & table mapping | `luna.ini` `[Paths]` / `[DBtables]` | hand-edit |
| Behaviour flags (cache, debug, clean URLs) | `luna.ini` `[Constantes]` | hand-edit |
| DB credentials | `db.ini`, else `DB_*` env vars | hand-edit / environment |
| Site name, languages, timezone, etc. | `luna_config` table | admin UI |
| Theme | `luna.domains/<domain>/xsl/` or built-in `luna.xsl/` | XSLT files |
