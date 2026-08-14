# Security Policy

## This is a study artifact, not production software

Luna is **alpha-grade software originally from 2006–2010**, revived as an
educational exploration of the Semantic Web. It reflects the security practices of its
era, with the major weaknesses since closed — bcrypt passwords with upgrade-on-login,
CSRF tokens, session-fixation defence, SQLi and header fixes, a per-IP login throttle,
per-target authorization across all admin modules, and HTTP basic auth on the SPARQL
endpoint (via the `sparql-proxy` service). It is still **alpha-grade**. Two-tier posture: the **full
Docker stack** (triplestore included) is meant to be **studied and run on `localhost`**, not deployed;
the **publishing surface** (app + MySQL only, `SPARQL_ENABLED=0`) *is* intended for a public domain, but
only by following the hardening runbook in **[docs/going-public.md](docs/going-public.md)**.

- The Docker stack binds every published port to `127.0.0.1` (loopback). **Do not**
  change that or otherwise expose `8080` (app) / `3307` (MySQL) to a public or
  untrusted network. The semantic-web services (`ontop`, `oxigraph`, `sparql-proxy`)
  publish no host port and are reachable only on the internal compose network.
- The full, honest list of issues and the current security posture lives in
  **[docs/security.md](docs/security.md)**. It is intentionally public:
  the weaknesses are part of what makes this a useful teaching artifact.

## Reporting

Because this is an unmaintained educational project, there is no private security
support or embargo process. If you find an issue **not already documented** in
[docs/security.md](docs/security.md), please open a normal GitHub issue describing
it — that improves the documented record for other learners. Do **not** expect a
patched, deployable release.

If you intend to run any of this code anywhere reachable from a network, treat it
as inherently unsafe and harden it yourself first.
