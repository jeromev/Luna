# Contributing to LunarSystem

Thanks for your interest! LunarSystem is a **small, deliberately-minimal teaching
artifact** — a 2006–2010 PHP/MySQL CMS revived as a hands-on way to explore the
Semantic Web (RDF, SPARQL, JSON-LD, virtual vs. materialised triplestores). It is
**not** production software (see [docs/security.md](docs/security.md)).

Contributions are welcome in that spirit: things that make it clearer, smaller,
safer to run locally, or better as a learning tool.

## Good contributions

- **Docs & learning material** — improvements to the [lab](docs/try-it.md),
  more worked [example queries](examples/queries.sparql), clearer explanations.
- **Correctness fixes** that keep the code faithful to its era but less buggy.
- **Safety for local use** — anything that keeps the "localhost-only" posture solid.
- **The roadmap** — see [docs/roadmap.md](docs/roadmap.md): P2 (retire the MySQL
  content write), P3 (inference / SHACL / named graphs), P4 (data-first server).

## Please avoid

- Turning it into production software, adding heavy frameworks, or large new deps.
  The value is that it's tiny and readable. New bundled libraries are unlikely to
  be accepted.
- Reverting the runtime — the project targets **PHP 8.3 / MySQL 8.0** (PDO);
  re-introducing the old `mysql_*` / PEAR MDB2 stack or a PHP-5-only port would be a
  *different* project.
- Adding bulk — e.g. a WYSIWYG editor. The editor is intentionally a plain Markdown
  `<textarea>`; keep the project tiny and readable.

## Coding standard

The house standard is **PSR-12 with seven named deviations** — most visibly, indentation is
a **tab**, and braces stay on the same line. All of it, with the reasoning, is in
[docs/coding-style.md](docs/coding-style.md).

You do not have to memorise it:

```bash
make tools    # once — installs php-cs-fixer + PHPStan under tools/ (never the root composer.json)
make check    # lint + style + static analysis + the project's own invariants
```

`make fmt` applies the layout rules. Two optional one-time setup steps:

```bash
sh tools/install-hooks.sh                                   # check the style before each commit
git config blame.ignoreRevsFile .git-blame-ignore-revs      # let git blame see through reformats
```

## Development setup

```bash
docker-compose up --build -d      # app :8080, MySQL :3307 (loopback only); Ontop, Oxigraph + sparql-proxy on the internal network (no host port)
```

See the top-level [README](README.md) and [docs/](docs/) — start with
[docs/try-it.md](docs/try-it.md), then [docs/architecture.md](docs/architecture.md).

## Before you open a PR

- **Lint the PHP** (8.1 is the supported floor, 8.3 the tested stack):
  ```bash
  make lint-php
  ```
  (CI runs the same check plus `docker compose config`.)
- **Smoke-test on Docker** — the site should render and `?output=jsonld` should
  return valid JSON-LD.
- **Update the docs** you touch, **bump the version** (`luna/luna.php`
  `$lunaVersion`, the version strings in the READMEs, and add a
  [CHANGELOG](CHANGELOG.md) entry), and keep changes small and focused.
- **Never commit secrets.** Real `db.ini` files are gitignored; keep it that way.

## License

By contributing, you agree your contributions are licensed under the project's
**GPL v2** (see [LICENSE](LICENSE)).
