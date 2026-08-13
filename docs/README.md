# LunarSystem Documentation

Developer and operator documentation for **LunarSystem**, a PHP/MySQL CMS that models all content as **RDF triples** and renders pages through **XSLT transformations**.

This `docs/` folder is the canonical technical reference. The top-level
[README.md](../README.md) is the quick-start; everything deeper lives here.

> **Semantic Web layer.** This is an RDF-native Semantic
> Web system — a JSON-LD projection, a generic SPARQL write-through that mirrors
> every content write into a triplestore (Oxigraph), and a read path served from
> that triplestore by default (with a SQL fallback). See [linked-data.md](linked-data.md)
> for the design, and [why-rdf.md](why-rdf.md) for what it unlocks in plain English.
> The untouched original CMS is preserved on the `legacy` branch (`v0.2.14-alpha`).

## Contents

| Document | What it covers |
|---|---|
| [overview.md](overview.md) | What the project is, its core ideas, and a glossary |
| [try-it.md](try-it.md) | A ~10-minute hands-on lab: data views, SPARQL, edit-and-read-back, swap the engine |
| [architecture.md](architecture.md) | The request lifecycle, core classes, and how they fit together |
| [rdf-model.md](rdf-model.md) | The in-memory triple store, namespaces, and SQL→RDF projection |
| [database-schema.md](database-schema.md) | Every table, column, and the seed data |
| [modules.md](modules.md) | The mod system and a mod-authoring guide |
| [templating.md](templating.md) | XSLT rendering, stylesheet lookup, and output formats |
| [configuration.md](configuration.md) | Domains, `luna.ini`, `db.ini`, and constants |
| [installation.md](installation.md) | Docker and manual setup, requirements |
| [security.md](security.md) | Known issues and hardening notes |
| [linked-data.md](linked-data.md) | The semantic-web layer: URI policy, vocabulary mapping, JSON-LD, and the SPARQL read path (Ontop → Oxigraph) |
| [why-rdf.md](why-rdf.md) | The payoff in plain English: what you can do now that a vanilla PHP/MySQL app can't — queries, interop, engine-swap — and what's still roadmap |
| [roadmap.md](roadmap.md) | Where the project is headed: finishing the RDF-native transition, then a data-first server (and why client-side/native-browser XSLT is a dead end, so P5 was dropped) |
| [going-public.md](going-public.md) | Safety posture and deliberate scope for the public repo |

## Reading order

**New here? Start with [try-it.md](try-it.md)** for a hands-on tour, then read on.

If you are new to the codebase, read in this order:

1. **[overview.md](overview.md)** — the big idea and vocabulary.
2. **[architecture.md](architecture.md)** — trace one request end-to-end.
3. **[rdf-model.md](rdf-model.md)** + **[database-schema.md](database-schema.md)** — how data is stored and shaped.
4. **[modules.md](modules.md)** + **[templating.md](templating.md)** — how a page is assembled and rendered.
5. **[configuration.md](configuration.md)** + **[installation.md](installation.md)** — how to run it.
6. **[linked-data.md](linked-data.md)** — the semantic-web layer built on top.
7. **[why-rdf.md](why-rdf.md)** — what that layer unlocks, and what's still to come.
8. **[roadmap.md](roadmap.md)** — where it's headed next.

## Status

This is an RDF-native Semantic Web CMS on `main` (`0.8.63-alpha`) — see
[linked-data.md](linked-data.md). The original, preserved on the `legacy` branch
as `v0.2.14-alpha`, is the unmodified starting point. It runs on **PHP 8.3 /
MySQL 8.0** via PDO; see [security.md](security.md) before exposing it publicly.
