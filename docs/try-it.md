# Try it

A guided tour of what makes Luna unusual: the same content served as a web
page, as machine-readable **data under content negotiation**, and as a **queryable
RDF graph** — with the storage engine swappable underneath. No prior RDF/SPARQL
experience needed.

> Everything here runs on `localhost` only (the stack binds its ports to
> `127.0.0.1`). It's a study artifact — see [security.md](security.md).

## 0. Start the stack (~1 min + ~15 s for MySQL)

```bash
docker-compose up --build -d
```

This starts five services: the **app** (`8080`), **MySQL** (`3307`), **Ontop**
(virtual SPARQL over MySQL), **Oxigraph** (the triplestore) and **sparql-proxy**
(a Caddy reverse proxy that adds the HTTP basic auth Oxigraph lacks). The
semantic-web services have no host port — Oxigraph is reachable only through
`sparql-proxy`, which the app talks to with credentials (`SPARQL_AUTH_USER` /
`SPARQL_AUTH_PASS`, demo defaults `luna` / `luna-sparql-dev`). Query them via
`docker-compose exec -T app`.

Open **http://localhost:8080** — the home page. Log in (top of the site) as
`admin@luna.local` / `luna` to see the admin pages too.

## 1. One URL, many representations

The same address serves HTML to a browser and RDF to a machine — the server picks
the representation from the `Accept` header:

```bash
curl -s -H 'Accept: text/html'           http://localhost:8080/   # the web page
curl -s -H 'Accept: text/turtle'         http://localhost:8080/   # the same page as Turtle
curl -s -H 'Accept: application/ld+json' http://localhost:8080/   # …as schema.org JSON-LD
curl -s -H 'Accept: application/rdf+xml' http://localhost:8080/   # …as RDF/XML
```

The content describes itself with schema.org / FOAF terms, so any RDF-aware tool
reads it with no bespoke API. (A `?output=turtle|jsonld|xml|n3|json` query param
forces a format without an `Accept` header — handy in a browser address bar — and a
JSON-LD block is embedded in every HTML page's `<head>`.)

## 2. Dereferenceable resources: `/id` and `/data`

Every resource has a stable identity URI a tool can look up. `/id/{slug}` is the
*thing*; it `303`-redirects to a concrete document by what you ask for:

```bash
curl -sI -H 'Accept: text/html'   http://localhost:8080/id/root   # 303 → /          (the web page)
curl -sI -H 'Accept: text/turtle' http://localhost:8080/id/root   # 303 → /data/root (the RDF document)
```

`/data/{slug}` is the RDF document. It carries the resource, the things it contains
(a page's `schema:Article` text blocks), and its **outbound links to the wider web**
— curated in [`../semantic/links.ttl`](../semantic/links.ttl):

```bash
curl -s http://localhost:8080/data/root
```

```turtle
# abbreviated
<http://localhost:8080/id/root> a schema:WebPage ;
    schema:name "Home" ;
    schema:hasPart <http://localhost:8080/id/welcome> ;
    schema:sameAs  <https://github.com/jeromev/Luna> ;   # ← links out
    rdfs:seeAlso   <https://github.com/jeromev/Luna> .
```

`/data/{slug}` content-negotiates too (Turtle by default) and `404`s for a resource
the current user can't see — a guest's `/data/admin` is denied exactly as `/admin` is.

## 3. Query the content as a graph

The whole site is in a triplestore you can ask arbitrary questions of. The seed
loads via SQL, so populate the graph from MySQL first (once); this also loads the
outbound links:

```bash
make resync-triplestore
```

Census — every content type counted in one query:

```bash
docker-compose exec -T app sh -c "curl -s -u \"\$SPARQL_AUTH_USER:\$SPARQL_AUTH_PASS\" \
http://sparql-proxy:7878/query -H 'Accept: text/csv' --data-urlencode \
'query=PREFIX schema: <https://schema.org/>
SELECT ?type (COUNT(?s) AS ?n) WHERE { ?s a ?type } GROUP BY ?type'"
```

→ `WebPage 13`, `Article 1`, `Person 2`. More to paste in
[`../examples/queries.sparql`](../examples/queries.sparql): shared-level siblings,
transitive ancestry (`schema:isPartOf+`), `ASK`, `CONSTRUCT`, `DESCRIBE`, and
cross-store federation.

## 4. The app runs *on* the graph — prove it

Routing and access control are answered by SPARQL by default, not SQL. Edit a text
in the admin UI (Edition → edit the "welcome" block on the home page) and save, then
read it back straight from the triplestore:

```bash
docker-compose exec -T app sh -c "curl -s -u \"\$SPARQL_AUTH_USER:\$SPARQL_AUTH_PASS\" \
http://sparql-proxy:7878/query -H 'Accept: text/csv' --data-urlencode \
'query=PREFIX schema: <https://schema.org/>
SELECT ?body WHERE { <http://localhost:8080/id/welcome> schema:articleBody ?body }'"
```

The write went to MySQL **and** mirrored into the graph, and the page renders from
the graph.

## 5. Swap the engine — no code change

The app talks only to a SPARQL endpoint, so you can change what's behind it. Point
the read path at **Ontop** (virtual SPARQL compiled to SQL over the *unchanged*
MySQL) instead of Oxigraph:

```bash
SPARQL_ENDPOINT=http://ontop:8080/sparql docker-compose up -d app
```

Reload http://localhost:8080 — identical site, now served live from MySQL through
SPARQL. Re-run `docker-compose up -d app` with no override to switch back, or append
`?sparql=0` to any URL to bypass SPARQL for one request.

## Where to go next

- **[why-rdf.md](why-rdf.md)** — what all this *unlocks* vs a plain PHP/MySQL app.
- **[linked-data.md](linked-data.md)** — the design: URI policy, vocabularies,
  content negotiation, outbound links, the read/write loop, the Ontop→Oxigraph swap.
- **[../examples/queries.sparql](../examples/queries.sparql)** — more SPARQL,
  including federation joining the triplestore against live MySQL via Ontop.
- **[architecture.md](architecture.md)** — how a request flows end to end.

## Tear down

```bash
docker-compose down          # stop; keep data
docker-compose down -v       # stop and wipe the MySQL + Oxigraph volumes
```
