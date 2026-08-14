# Installation

> Luna runs on **PHP 8.3 / MySQL 8.0** via PDO / `pdo_mysql`. The Docker
> stack below builds it; a modern PHP install also works directly. See
> [security.md](security.md).

## Requirements

| Component | Version / notes |
|---|---|
| PHP | **8.3** |
| PHP extensions | `pdo_mysql`, `xsl`, `mbstring`, `gettext`, `json`, `libxml` (run `php bin/preflight.php` to check a host) |
| Web server | Apache 2 with `mod_rewrite` and `AllowOverride All` |
| Database | MySQL 8.0 (run with `sql_mode=""` for the legacy MyISAM column defaults; the Docker stack sets this) |
| Bundled libs | semsol/arc2 (locally PHP-8/UTF-8 patched) — vendored in `luna/luna.lib/arc/` |

## Option A — Docker (recommended)

The repo ships a working Docker stack: PHP 8.3 + Apache, and MySQL 8.0.

```bash
docker-compose up --build -d
```

Then wait ~15 seconds for MySQL to initialise and open
**http://localhost:8080**.

Log in as **`admin@luna.local`** / password **`luna`**.

What the stack does (see [docker-compose.yml](../docker-compose.yml) and
[Dockerfile](../Dockerfile)):

- **app** — builds `php:8.3-apache`, installs the `pdo_mysql`, `xsl`, `gettext`, and
  `mbstring` extensions, enables `mod_rewrite`, and sets `AllowOverride All`.
  Mounts the repo at `/var/www/html` and publishes port **8080 → 80** (bound to
  `127.0.0.1` only).
- **db** — `mysql:8.0` started with `--sql_mode=""` (so the legacy column
  defaults import cleanly), seeded automatically by mounting
  [luna.mysql.sql](../luna/luna.sql/luna.mysql.sql) into
  `/docker-entrypoint-initdb.d/`. Database `lunadb`, user/pass `luna`/`luna`.
  Published on host port **3307** (to avoid clashing with a local MySQL on 3306).

> **Semantic-web services (optional).** Beyond the CMS itself, `docker-compose.yml`
> defines three more services — **ontop** (a virtual SPARQL endpoint over the
> unchanged MySQL), **oxigraph** (a triplestore), and **sparql-proxy** (a Caddy
> reverse proxy that adds HTTP basic auth in front of Oxigraph). None publish a host
> port. Oxigraph has no native auth, so it sits on an internal-only `triplestore`
> network reachable solely by the proxy; the app talks to **sparql-proxy** with
> credentials, never to Oxigraph directly. The app gains `SPARQL_ENDPOINT` /
> `SPARQL_UPDATE_ENDPOINT` env vars (defaulting through the proxy) plus
> `SPARQL_AUTH_USER` / `SPARQL_AUTH_PASS` (demo defaults `luna` / `luna-sparql-dev`;
> override `SPARQL_AUTH_PASS` via a gitignored `.env` — see [.env.example](../.env.example)).
> They are **not needed** to run the CMS itself: `docker-compose up -d` will start
> them, but the site runs entirely on **app + db**. To run only the core stack, use
> `docker-compose up -d app db`. See [linked-data.md](linked-data.md) for what the
> extra services do and how to use them.

No database config editing is required: the stack passes `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS`
(defaulting to the `db` service / `lunadb` / `luna`) to the app, and the app uses them when no
`db.ini` is present. Drop a `db.ini` into `luna/luna.domains/luna.default/ini/` (or override the
`DB_*` vars in a gitignored `.env`) to point elsewhere.

### Reset the database

The seed import only runs on first init (empty data volume). To re-seed:

```bash
docker-compose down -v   # drops the db_data volume
docker-compose up --build -d
```

## Option B — Manual setup

1. Install Apache 2 + PHP 8.3 with the `pdo_mysql`, `xsl`, `mbstring`, and
   `gettext` extensions; enable `mod_rewrite` and set `AllowOverride All` for the
   document root.
2. Create a MySQL database and import the schema + seed data:
   ```bash
   mysql -u <user> -p <database> < luna/luna.sql/luna.mysql.sql
   ```
   On MySQL 5.7/8, run the server with `sql_mode=""` so the legacy zero-date /
   column defaults in the dump import without strict-mode errors.
3. Configure the database connection: copy
   `luna/luna.domains/luna.default/ini/db.example.ini` to `db.ini` and fill in
   `driver`, `username`, `password`, `host`, `database`. See
   [configuration.md](configuration.md).
4. Make `luna/luna.domains/<your-domain>/cache/` writable by the web server.
5. Point a vhost at the repo root. The root [`.htaccess`](../.htaccess) routes
   every non-file request through `index.php?path=<alias>` — confirm
   `AllowOverride All` so it takes effect.
6. Open the site. It resolves the active domain from `HTTP_HOST`; if no matching
   `luna.domains/<host>/` exists it uses `luna.default/`.

## Option C — Public shared hosting (the publishing surface)

To put Luna on a real domain (e.g. DreamHost shared), deploy the **publishing
surface** — app + MySQL, no triplestore. It's Option B plus three things: run
`php bin/preflight.php` to confirm the host (notably the `xsl` extension), set
`SetEnv SPARQL_ENABLED 0` in `.htaccess` so the app serves entirely from MySQL, and turn on
HTTPS + change the demo admin password. The full step-by-step is in
**[going-public.md](going-public.md)**.

## Adding a site-specific domain

To serve a real hostname with its own settings, create:

```
luna/luna.domains/<hostname>/ini/luna.ini
luna/luna.domains/<hostname>/ini/db.ini
luna/luna.domains/<hostname>/cache/     (writable)
luna/luna.domains/<hostname>/xsl/        (optional theme override)
```

Domain resolution is automatic — the presence of `ini/luna.ini` under a
directory matching the host is what selects it. See
[configuration.md](configuration.md#domain-resolution).

## Output formats

Any page URL also serves the raw model — by HTTP `Accept` content negotiation, or by
appending `?output=jsonld`, `?output=turtle`, `?output=xml`, `?output=json`, or `?output=n3`
to receive schema.org JSON-LD, Turtle, RDF/XML, RDF/JSON, or N-Triples instead of HTML.
Resources are dereferenceable at `/id/{slug}` and `/data/{slug}`. See
[linked-data.md](linked-data.md) and [templating.md](templating.md#non-html-output).

## First steps after install

1. Log in as the seeded admin (`admin@luna.local` / `luna`) and **change
   the password** immediately.
2. Edit site settings under **Admin** (`mod_admin`): site name, description,
   languages, timezone.
3. Create pages and content under **Admin → Pages** and **Edit Texts**.
