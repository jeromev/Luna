# Security & Known Issues

Luna is **alpha-grade software originally from 2006–2010**, revived for study. It
reflects the security practices of its era. The **full Docker stack** (triplestore included)
is for local study only — its host ports bind to `127.0.0.1`, keep it that way. The
**publishing surface** (HTML + content-negotiated RDF, served from MySQL with no triplestore)
*can* be deployed to a public domain by following [going-public.md](going-public.md); the
controls below are what make that defensible. It is still alpha software — deploy it with eyes
open, not as a hardened product.

## Current posture

No remotely-exploitable critical/high issue in the stock configuration (single-admin tier,
all host ports loopback-bound). Controls in place:

- **Passwords** — bcrypt (`password_hash`); any pre-existing MD5 hash is transparently
  re-hashed to bcrypt on the owner's next successful login. Login returns one generic error
  and runs a dummy verify on the unknown/inactive path, so account existence is not
  observable from timing.
- **CSRF** — a per-session synchronizer token is embedded in every state-changing form and
  verified centrally (POST-only `hash_equals`) in the dispatch ([luna.php](../luna/luna.php))
  before any handler runs.
- **Sessions** — cookie-only (`use_trans_sid=0`, `use_only_cookies=1`, `use_strict_mode=1`);
  `session_regenerate_id()` on login; a DB-backed handler implementing `validateId()` that
  rejects and reissues a forged session id; and a mandatory User-Agent + IP binding in
  `get_user_data()` (a mismatch yields zero rows → demotion to anonymous).
- **Authorization** — each admin module re-checks the actor against the *specific* target on
  every `submit_add/modify/delete` (per-target `user_can_access_level()` /
  `user_can_access_page()` / `user_can_access_group()` on the target and on every
  level/group/page being assigned). Admin-lockout guardrails refuse to sever the actor's own
  or the last active admin path, and `lunaModel::delete()` refuses to delete any node in the
  `luna::$protected_lids` set (the admin/default groups, public/admin levels, the admin pages
  and mods). Validated by [`test/delegated_admin.sh`](../test/delegated_admin.sh) and
  [`test/admin_lockout.sh`](../test/admin_lockout.sh).
- **Login throttle** — per-IP back-off in `luna_login_throttle`, counted first with an atomic
  `INSERT … ON DUPLICATE KEY UPDATE`; fires for unknown and known accounts alike (existence
  not observable from timing); a correct password clears the counter, so accounts never lock
  permanently.
- **Input / output** — HTMLPurifier allowlist sanitisation on all input except Markdown page
  content (rendered by league/commonmark with raw HTML escaped and unsafe URI schemes
  dropped); SQL escaped via PDO `lunaDB::quote()`; SPARQL via typed wrappers
  (`rdf_str`/`rdf_uri`/`rdf_int`, audited injection-safe).
- **HTTP headers** — strict CSP on HTML pages (`script-src`/`style-src 'self'`, no
  `unsafe-inline`), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy`, COOP; no `X-Powered-By`. (The machine-readable `?output=` responses send
  a relaxed, data-appropriate CSP so the browser's built-in XML/JSON viewer renders.) Over
  HTTPS the app adds **HSTS** (`Strict-Transport-Security`, 1 year + `includeSubDomains`) and
  `upgrade-insecure-requests`, and marks cookies `Secure` — HTTPS is detected from
  `$_SERVER['HTTPS']` / port 443, or `X-Forwarded-Proto` when `TRUST_PROXY=1` (a proxy header
  ignored by default so it can't be spoofed on a direct host).
- **Source / secret disclosure** — `.htaccess` 403s `/.git`, `*.ini`/`*.sql`, the docker
  files, and `docs/`/`scss/`/`semantic/`/`examples/` (case-insensitively); `DEBUG=0` by
  default.
- **Triplestore** — an authenticating proxy fronts the internal-only Oxigraph; see
  [Triplestore / SPARQL surface](#triplestore--sparql-surface).

This remains an archival app on PHP 8.3 with a flat group→level authz model. Two-tier posture: keep
the **full Docker stack** (triplestore included) on `localhost` — behind web-server auth / a VPN and
**off the public internet**. Only the **publishing surface** (app + MySQL, `SPARQL_ENABLED=0`) is
intended for a public domain, and only by following the hardening runbook in
[going-public.md](going-public.md).

## Residual (documented, not exploitable as shipped)

- **Login throttle is per-IP only** — it resists parallel/sequential brute force from one IP
  but is bypassable by IP rotation (one free first guess per fresh IP), and there is no
  per-account lockout. A deliberate trade-off: a per-account counter would re-introduce the
  account-enumeration timing leak.
- **Session hijack guard is weak** — it is bound to the client-controlled `User-Agent` (a
  fixed binding) and breaks users behind rotating IPs. It uses `REMOTE_ADDR` (not
  `X-Forwarded-For`), so it cannot be IP-spoofed. The IP is packed via `encode_ip()`, which now maps
  IPv6 (and any non-IPv4) address to a distinct, stable token; previously all IPv6 clients collapsed to
  one value, so on a dual-stack host they shared a single throttle bucket and a single session-IP
  binding (a login-DoS and a hijack-widening). Fixed in the ship-gate pass.
- **Legacy MD5 password hashes** — any surviving `luna_users.password` MD5 row is trivially
  crackable until its owner next logs in, which upgrades it to bcrypt
  (`password_is_legacy()` / `password_needs_rehash()`). Log every legacy admin in once to
  force the upgrade; never reuse a real password.
- **Weak default admin** — the seed admin is `admin@luna.local` / `luna`. Change it
  immediately after install.
- **Markdown content is emitted with `disable-output-escaping`** — safety rests on the
  CommonMark converter (`lunaTools::markdown()`: `html_input=escape`,
  `allow_unsafe_links=false`), so `<script>`, `on*` handlers and `javascript:`/`data:` URIs
  cannot reach the output by construction. The Markdown source itself is never
  disable-output-escaped and is XML-escaped when echoed back into the edit textarea; it is the
  one input exempt from the HTMLPurifier pass (purifying would corrupt the source).
- **`register_globals`-era assumptions** — the code explicitly disables `register_globals` and
  guards `magic_quotes`, but its design assumptions are dated.

This is a code-reading review, not a penetration test, and not exhaustive — do not treat
absence from this list as evidence of safety.

## Hard compatibility limits

| Issue | Impact | Detail |
|---|---|---|
| **Runtime** | — | Runs on **PHP 8.3 / MySQL 8.0** via PDO (`pdo_mysql`); no `mysql_*` extension or PEAR MDB2. |
| **MyISAM storage engine** | Compatibility | The schema uses `ENGINE=MyISAM`. MySQL 8.0 needs `sql_mode=""` for the legacy column defaults — the Docker stack sets this. |
| **Vendored lib** | Maintenance | The one in-tree vendored library is **semsol/arc2 3.1.0** (RDF/SPARQL), kept vendored on purpose: it carries local PHP-8 patches the upstream lacks (stock 3.1.0 fatals on `?output=n3`), so it is not a drop-in Composer package — see [`luna.lib/arc/VENDOR.txt`](../luna/luna.lib/arc/VENDOR.txt). The Composer dependencies are HTMLPurifier (input sanitiser) and league/commonmark (Markdown→HTML), both committed under `vendor/`. |

## Dependency posture

The Composer dependencies are audited **on every push and PR, and on a weekly schedule**
(`.github/workflows/deps-audit.yml` → `composer audit --locked`). The schedule is the part that
matters: this project's intended resting state is dormant, so a push-triggered check alone would
fall silent exactly while advisory risk accumulates.

**Audit as of 2026-08-02: clean, and current with upstream.** No published advisory affects any of
the nine locked packages. Worth recording how thin the margin had been: before 0.8.61 the tree was
pinned at `league/commonmark` **2.8.2**, one patch release the safe side of CVE-2026-33347
(embed-extension `allowed_domains` bypass, affecting `>=2.3.0,<=2.8.1`) — clean, but by accident of
when the lock was taken rather than by anyone checking.

Since 0.8.61 the three packages that trailed upstream are current: `league/commonmark` 2.8.3,
`nette/utils` v4.1.5, `symfony/deprecation-contracts` v3.7.1. None carried an advisory; the update
was hygiene, taken so the margin is deliberate rather than incidental.

**Two gaps this gate does not close — do not read a green audit as full coverage:**

- **The vendored RDF library is invisible to it.** `luna/luna.lib/arc` (semsol/arc2 3.1.0 with
  local PHP-8 patches) is not a Composer package, so **no advisory feed covers it and no tool
  here will ever report on it**. It is also *not* confined to the triplestore profile, as its
  placement under "Triplestore / SPARQL surface" might suggest: `lunaModel::dump()` calls its
  RDF/XML, Turtle, N-Triples and RDF-JSON **serialisers** on the ordinary content-negotiation
  output path, which is live on the public MySQL-only profile with `SPARQL_ENABLED=0`. The
  exposure shape is serialisation of author-written content into RDF syntax — an escaping defect
  there would mean triple injection into the Linked Data representation (integrity/spoofing on
  the data surface), not code execution. Its Turtle **parser** is fed only `semantic/links.ttl`,
  a curated file on disk, never remote input.
- **GitHub disables scheduled workflows after ~60 days of repository inactivity** (it emails
  first, and a click re-enables them). For a deliberately dormant repo that is a real failure
  mode: the weekly audit is dormancy insurance that is itself subject to dormancy. If the repo
  goes quiet for two months, confirm the schedule is still armed.

## Triplestore / SPARQL surface

The RDF-native read/write loop (see [linked-data.md](linked-data.md)) adds a network surface
the original CMS did not have. It is hardened with an authenticating proxy, an internal-only
network, and no host port.

- **SPARQL endpoint — authenticated and network-isolated.** The app reaches Oxigraph through
  an authenticating reverse proxy (`sparql-proxy`, Caddy) that demands HTTP basic auth on
  **every** request — reads and writes alike — before forwarding (`SPARQL_ENDPOINT`,
  `SPARQL_UPDATE_ENDPOINT`, `SPARQL_AUTH_USER`/`SPARQL_AUTH_PASS` in `luna.php`;
  `sparql_auth_header()` in the model adds the `Authorization` header). Two layered
  mitigations:
  - The SPARQL services have **no host port** — never reachable from the host or a browser,
    which closes the CSRF-to-localhost write vector.
  - Oxigraph has no native auth and accepts unauthenticated writes on `/update` + `/store`, so
    it sits on an **internal-only** compose network whose only other member is the proxy — the
    app cannot even reach `oxigraph:7878` directly. An unauthenticated request to the proxy
    gets `401`; the bcrypt hash is generated at container start from the plaintext
    `SPARQL_AUTH_PASS`, so **no password hash is committed**. Ontop (virtual, read-only) has no
    host port and stays on the default compose network (reachable by the app, never the host).

  Keep the host bindings on `127.0.0.1`, change `SPARQL_AUTH_PASS` from its demo default (via
  `.env`) for any real use, and never publish Oxigraph or the proxy.
- **Public deploy — turn the SPARQL surface off entirely.** `SPARQL_ENABLED=0` (e.g.
  `SetEnv SPARQL_ENABLED 0` in `.htaccess`) disables the whole triplestore layer — both the
  read path and the write-through — so a public publishing-surface deploy has **no SPARQL
  surface at all** and serves entirely from MySQL. The triplestore stack stays local (or on a
  separate VPS). See [going-public.md](going-public.md).
- **Hand-rolled SPARQL string assembly — injection-safe.** The write-through (`rdf_sync_node`
  / `rdf_delete_node` / `rdf_resync_all`) and the read builders (`load_nodes_sparql` /
  `load_texts_sparql`) assemble SPARQL by interpolation, but every user-controlled value
  passes through a typed wrapper around an otherwise static query: `rdf_str()` wraps
  `sparql_literal()` (escapes `\`, `"`, `\n`, `\r`, `\t`, backslash first); `rdf_uri()`
  `rawurlencode`s the lid so every IRIREF-breaking character is percent-encoded; `rdf_int()`
  `intval`s. User content reaches RDF only through these escapers.
- **Best-effort by design.** The write-through never blocks a save on a SPARQL failure, so
  MySQL stays the source of truth; a failed mirror means the graph can lag — reconcile with
  `rdf_resync_all()` (`make resync-triplestore`).

## Repository hygiene

| Item | Status / note |
|---|---|
| `db.ini` with real credentials | Gitignored and never committed — only the working-tree copy holds credentials, and under Docker the app runs from the `DB_*` env defaults with no `db.ini` at all. Rotate any real credentials; there is nothing to untrack. |
| `.svn/` directories | May be present on disk from the original Subversion checkout. Not tracked by git — working-copy cruft, safe to delete (`find . -name .svn -type d -prune -exec rm -rf {} +`). |
| `.DS_Store` files | Not tracked; already ignored. |
| Cache directories | `luna.domains/*/cache/` must be writable but its *contents* are not committed (only `.gitkeep` is tracked). |

## Deploying the publishing surface

The supported public deployment is the **publishing surface** — app + MySQL, no triplestore —
following the runbook in [going-public.md](going-public.md). The security essentials:

1. **Run `php bin/preflight.php`** on the host first (PHP 8.1+, required extensions).
2. **Serve over HTTPS only** — uncomment the force-HTTPS block in [`.htaccess`](../.htaccess);
   HSTS and secure cookies then apply automatically.
3. **`SPARQL_ENABLED=0`** so there is no triplestore / SPARQL surface; the stack stays local.
4. **Change the admin password and email**, and rotate all DB credentials, before announcing
   the URL (the seed `admin@luna.local` / `luna` is public knowledge).
5. Restrict the MySQL user to the minimum required grants; never expose MySQL publicly.
6. Keep `DEBUG = 0` so errors aren't displayed, and keep `db.ini` out of git.
7. Keep cookie-only sessions (the defaults already enforce this).
