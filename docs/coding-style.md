# Coding style

LunarSystem follows **[PSR-12](https://www.php-fig.org/psr/psr-12/) (PHP-FIG, Accepted)
with seven named deviations**, plus a small set of house rules that no published
standard covers.

Documentation follows the **[phpDocumentor specification](https://docs.phpdoc.org/guide/references/phpdoc/index.html)**
for syntax and the **PHPStan/Psalm PHPDoc dialect** wherever a tool has to read the tag.

Static analysis is **PHPStan**, and it runs **without a baseline file**.

Run the whole thing:

```bash
make check
```

## Why PSR-12 and not PER Coding Style

PER Coding Style is the PHP-FIG's evolving style spec; it is now at 3.0, having moved
through 2.0 in a fairly short span, and php-cs-fixer already marks the `@PER-CS2.0`
ruleset identifier deprecated. PSR-12 is **Accepted** and frozen: it says the same thing
this year that it said in 2019, and it will say it in 2036.

For a small teaching artifact that is read far more often than it is edited, a spec that
does not move is worth more than a spec that is current. PER-CS is also further from this
code, not closer — it mandates short arrays, trailing commas and cast spacing on top of
everything PSR-12 asks.

Deviating from a published standard, with the deviations written down, is the normal
posture rather than a compromise: Symfony's own standards are "based on PSR-12 and PSR-4"
with a documented exception for the concatenation operator — which is Deviation 4 below.

## The seven deviations

### 1. Indentation is a tab, width 4 (PSR-12 §2.4 says four spaces)

Converting to spaces is roughly 88% of the entire diff of adopting PSR-12, at net zero
lines changed, and it buys no legibility.

It also makes the code *less* consistent, not more. The model and the admin mods carry
several hundred multi-line SQL and SPARQL literals whose continuation lines are indented
with tabs **inside the string**. A formatter converts leading indentation but cannot touch
string content, so converting turns a codebase with zero mixed-indentation lines into one
with several hundred.

A tab also lets each reader choose their own width, which matters more than usual for an
artifact whose whole proposition is "read me".

This decision lives in **two** places and they must be kept in step:
`.editorconfig`'s `[*.php]` block, and `.php-cs-fixer.dist.php`'s `->setIndent("\t")`.
php-cs-fixer does not read `.editorconfig`.

### 2. Braces open on the same line, for classes and methods too (PSR-12 §4.3/§4.4)

Every class and every function in the codebase already agrees on this, with no exceptions.
Moving them costs roughly a 19% increase in line count to fix nothing, and it would
falsify the ```php block in [modules.md](modules.md) that every new module is copied from —
a teaching artifact would start teaching a shape its own code no longer uses.

### 3. One-line bodies are allowed (PSR-12 §5)

`public function __clone() { trigger_error(...); }` stays on one line, as do short
single-statement `if` bodies. Same reasoning as Deviation 2: it is uniform already, and
expanding it inflates the artifact without clarifying anything.

### 4. Concatenation is tight; other binary operators are spaced

`'a'.$b.'c'`, never `'a' . $b . 'c'`. Assignment and comparison keep their spaces.
The codebase is already unanimous on this, and spacing it out would widen hundreds of
lines that are already long. This is exactly Symfony's published exception to PSR-12.

### 5. Method and class names keep their existing casing (PSR-1 §4.1/§4.3)

Methods are `snake_case` and classes are lowercase-first (`luna`, `lunaModel`).

This is not a preference. `luna::load_mods()` resolves module class names **from the
database**, out of the `lid` column, via `call_user_func($lid.'::singleton')` and
`method_exists($lid, 'submit_delete')`. Renaming module classes is a schema migration
wearing a style change's clothes, and it would fail at runtime with no compile-time signal.

### 6. `luna/luna.php` and `bin/*.php` may both declare symbols and cause side effects (PSR-1 §2.3)

`luna.php` is the bootstrap and front controller: it defines constants, sets ini values and
requires the autoloader before it declares `class luna`. `bin/*.php` are executable scripts.
Both are entry points, and the rule exists for library files. See [architecture.md](architecture.md).

### 7. No namespaces, no PSR-4, no first-party autoloader (PSR-1 §3, PSR-4)

Two files declare two classes each (`lunaDB`+`lunaResult`, `lunaException`+`lunaLog`).

Composer's autoloader **is** loaded — `luna.php` requires it for HTMLPurifier and
CommonMark — but first-party code deliberately does not use it, for the same reason as
Deviation 5: class names come from the database, so a namespace breaks module dispatch at
runtime. This is recorded here and in [architecture.md](architecture.md) so that the next
reader does not "fix" it.

## House rules

These are the project's own; no off-the-shelf standard covers them. The ones marked
**(checked)** are enforced by `make lint-house`.

1. **Fold markers are a navigation index, and they are accurate. (checked)**
   Every method is wrapped in `// {{{ name()` / `// }}}`, the label equals the declared
   method name, and the counts balance per file. They are not decoration and they are not
   noise: they are how this codebase has been navigated since 2006. No formatter rule
   touches them.
2. **`luna.mod_example.php` is normative documentation.** It is what every module is copied
   from. It is not seeded as a node, so no render gate exercises it — check it by hand on
   every style change.
3. **A file header states one true sentence about the file**, plus `PHP 8.1+` and
   `@author Jérôme Vogel` (see [AUTHORSHIP.md](../AUTHORSHIP.md)). Version claims that are
   false are worse than absent ones.
4. **Commented-out code is deleted.** Git is the archive.
5. **Superglobals are read, never assigned.** Sanitised values go into named locals, so
   that a later reader can tell from the variable whether a value is raw or clean.
6. **`header()`, `die()` and `exit` belong to `index.php` and `bin/` only.** Model and
   module methods return values; the caller decides how the request ends.
7. **Never suppress `E_DEPRECATED`, and use `@` only where the next line inspects the
   return** — never on a network call or a write.
8. **Prose documentation cites symbols, never line numbers. (checked)** Line numbers rot
   silently; symbol names do not.
9. **Budgets.** Methods: 50 lines soft, 100 hard. Nesting depth: 4. Line length: 120, as a
   warning with a **ratchet** (`test/style.counts`) rather than a hard limit — the count may
   fall, never rise. When a rise is genuinely right — a bug fix that needs two more lines —
   record it deliberately with `LUNA_RATCHET_ACCEPT=1 make lint-house` and commit the changed
   counts alongside the change that caused them. Class size is a *schedule*, not a budget: see below.
10. **The module contract is checked, not assumed. (checked)** One class per file; the class
    name equals the filename minus `luna.` and `.php` — the invariant the database-driven
    dispatcher depends on; `singleton()` and `load()` exist; every method has an explicit
    visibility keyword.
11. **The ontology namespace invariant is checked. (checked)** `lunaModel::LUNA_NS` must
    match the `xmlns:luna` declaration in every XSL stylesheet and in
    `semantic/ontop/mapping.ttl`. [ontology/README.md](../ontology/README.md) records that
    this is maintained by hand and is not auto-derived, which makes it the most fragile
    cross-file invariant in the repository.

## Array syntax

Short: `['a' => 1]`, not `array('a' => 1)`.

PSR-12 is silent on this, so it is not one of the seven deviations — it is a deliberate
modernisation, applied in its own commit rather than folded into a whitespace pass. The
long form was a 2006 necessity; the project targets PHP 8.1, and the short form has been
available since 5.4. `make fmt` enforces it.

## Documentation

**A docblock states only what the signature cannot.** Once a method carries native types,
`@param` and `@return` are deleted wherever the type already says it. What survives:

- one summary sentence for anything whose purpose is not obvious from the name,
- array shapes, units, and IRI/namespace expectations,
- `@throws`,
- rationale — *why*, not *what*. The best comments in this codebase are already of this
  kind; the emptiest are `@access public` over an obvious getter.

`@access` is not used: it was deprecated in phpDocumentor 2 and PHP's own visibility
keyword carries the information.

**There are no generated API docs, deliberately.** The entry road is
[architecture.md](architecture.md), and the answer to "how do I learn the API" is "read the
source". That only works if the source is legible and the prose is true, which is what the
rest of this document is for.

**Division of labour:** `docs/` owns orientation and architecture. A file header owns one
sentence of purpose. A docblock owns what the signature cannot express. Nothing restates
anything else.

## Native types

Return types were promoted from the docblocks mechanically, with php-cs-fixer's
`phpdoc_to_return_type`, once PHPStan was green at a level whose `return.type` rule checks every
documented return against what the code actually returns. Roughly a hundred signatures gained a
type that way.

**It is not a substitute for reading the code, and the run proved it.** Three docblocks were
wrong in a way no analyser here could see, and promoting them turned a wrong comment into a
fatal:

- `load_texts()`, `load_users()` and `load_nodes_sparql()` each end by returning the result of
  `merge_nodes()`, which returns `false` when its second argument is not an array — which
  happens whenever the inner loader finds nothing. All three said `array`. The login page has no
  text blocks, so `/login` fatalled while `/` did not.
- `check_if_lid_is_protected()` said `array|false` and returns the item's **lid**, a string.
  Promoting it broke the privilege-escalation guard — and broke it in the worst way, by making
  the request crash before the denial was rendered, so the test that asserts the refusal saw a
  guard that had "worked" only because nothing survived to disagree.

The analyser missed all four because the call chains run through `luna::$model->…`, a static
whose type it resolves loosely, and because `get_lid()` is documented `mixed`.

So: promotion is a starting point, and every batch is proved with the render baseline **and**
the write-path suites before it is kept. Two rules follow. `@return mixed` is never promoted —
`: mixed` constrains nothing and would only inflate the ratchet. And a promotion that breaks
something is a report about the **docblock**, which gets corrected; the type is never widened
just to make the failure go away.

### Parameter types, and where they stop

Parameter types are promoted **everywhere except `lunaModel`**, and the exception is a fact
about what can be checked here rather than a preference.

A return type constrains one function; a parameter type constrains every caller, so it can only
be promoted safely if every call site can be verified. PHPStan's `argument.type` rule does
exactly that, and it works — it caught 34 call sites whose docblocks were too *narrow*
(`lunaTools::request()` documented `array|false $in` while callers pass `0` and `'DESC'`;
`insert()` and `update()` documented `bool $is_active` while callers pass `0` and `1`). All were
widened to the truth.

It does not work for the model, and the reason is worth recording because the obvious fix was
tried and was not enough.

The first diagnosis was the nullable static: every call into the model went through
`luna::$model->…`, typed `lunaModel|null`, and an analyser will not check argument types on a
call whose receiver it cannot resolve. So `luna::model()` was added — a non-nullable accessor
that throws if the model is read before it is built — and all 311 call sites were repointed to
it. That change is real and it stays: it is behaviour-neutral, it removes a nullable from the
hottest path in the codebase, and it did make PHPStan see call sites it had been blind to (it
immediately found thirteen `insert()`/`update()` calls passing `0` and `1` to a parameter
documented `bool`).

It still was not enough. With the accessor in place and `argument.type` reporting zero, typing
the model's parameters broke `/login` again. Whatever the remaining gap is, the available static
checking does not close it.

**So the model's parameters stay untyped, and this is a bounded, known piece of work rather than
an oversight.** Doing it properly means going method by method, reading every call site by hand,
and typing one signature at a time behind the render baseline and the write-path suites. It is
a few evenings of careful work, not a mechanical pass, and four attempts at the mechanical pass
each ended with the site down.

Two rules came out of the attempt, and both are worth keeping:

- **Never type a parameter whose own body type-guards it.** A method beginning
  `if (!is_array($nodes1) || !is_array($nodes2)) { return false; }` is telling you what it really
  accepts. The guard is the contract; the docblock was the aspiration. Forty parameters matched.
- **A parameter typed from a docblock is a hypothesis until a call-site check confirms it.**
  Where the check cannot run, the hypothesis does not ship.

## PHP version

**The supported floor is 8.1. 8.3 is the tested stack.** `bin/preflight.php` gates on
8.1.0 and [going-public.md](going-public.md) tells deployers to select 8.1+.

So: no PHP 8.2+ or 8.3-only syntax in first-party code. Typed class constants
(`const string FOO`) are a parse error on 8.1. CI lints both versions — `make lint-php`
takes `PHP_LINT_IMAGE`, and the workflow runs it as a matrix — so the floor is checked
rather than merely asserted.

## Class size, and the structural schedule

`lunaModel` and `lunaTools` are far over any reasonable class budget. A budget the code
fails by several times on the day it is written is not a rule, so instead:

- `test/style.counts` records the size of every file over 1,000 lines and **fails if any
  of them grows**. That is the ratchet.
- The target is 800 lines, reached by a scheduled sequence of extractions rather than by
  a rule that is permanently red. [roadmap.md](roadmap.md) P4 already describes part of
  this — "PHP shrinks to negotiate + construct + serialise".

Each extraction is a **pure move**, no logic change, proved byte-neutral with
`make render-check` plus whichever suite covers the subsystem being moved. The schedule is
complete; `lunaModel` went from 3,176 lines to under 2,400, and what remains in it is loading
and projection — it no longer authorises, renders, serialises to the network, or ends a request.

The schedule, in order of provability:

| | Move | Status |
|---|---|---|
| **S1** | The six access-control methods out of `lunaTools` into `lunaAuthz` | **done** — `lunaTools` fell below 1,000 lines and left the ratchet |
| **S2** | The HTTP emitters and the XSLT pipeline out of `lunaModel`; `header()`/`die()` moved to the caller, per house rule 6 | **done** — the model now contains no `header()`, `die()` or `exit` |
| **S3** | The SPARQL client and RDF write-through into `lunaGraph` | **done** — ahead of roadmap P2, which would otherwise have rewritten it in place |

## Out of scope, said explicitly

**XSLT is not covered by this document.** `luna/luna.xsl/` holds more lines than the
largest PHP class, and it is where every byte of HTML this CMS emits is actually written.
It is disciplined in the same unwritten way the PHP was. Three rules apply to it now, and
a full pass is its own project:

1. Every named template gets a comment.
2. `luna.common.html.xsl` is the shared library; a template used by more than one page
   lives there and nowhere else.
3. The `xmlns:luna` namespace is gated against `lunaModel::LUNA_NS` by `make lint-house`.

**Vendored code is never touched.** `luna/luna.lib/arc` (ARC2) and `vendor/` (Composer)
are excluded from every linter, formatter and analyser here.

## Tooling

Dev tooling lives in `tools/` (its dependencies gitignored, its manifest committed) and is installed with `make tools`.

It is deliberately **not** in `composer.json`. The committed `vendor/` tree *is* the
deployment — [going-public.md](going-public.md) notes there is no `composer install`
step — and `.github/workflows/deps-audit.yml` runs `composer audit --locked` **without**
`--no-dev`. A dev dependency would therefore enlarge the weekly advisory surface of the
shipped artifact, and the only way to quiet it would be to weaken that audit.

| Command | What it holds |
|---|---|
| `make lint-php` | `php -l` across every first-party file |
| `make fmt` / `make fmt-check` | The layout rules, via php-cs-fixer |
| `make analyse` | PHPStan, no baseline |
| `make lint-house` | The house rules marked **(checked)** above |
| `make check` | All of the above; needs no running stack |
| `make render-check` | Byte-identical render against the committed baseline |

### The pre-commit hook

```bash
sh tools/install-hooks.sh
```

Appends a delimited stanza to `.git/hooks/pre-commit` that runs `make fmt-check` when the commit
touches PHP. It never rewrites what is already there, and it **refuses** rather than appending
when the existing hook is not a shell script or has a path that exits before the end — an
appended stanza that can never be reached is worse than no hook, because it looks installed.
In that case it prints the one line to add near the top of the hook by hand.

The check covers the whole tree, not only the staged files: php-cs-fixer has no staged-only mode.
Bypass a single commit with `LUNA_SKIP_STYLE=1` or `git commit --no-verify`; uninstall by deleting
the block between the two `LUNA_STYLE_HOOK` markers.

### Proving a change is safe

`make render-check` is what makes a sweeping change safe here: it re-renders every page
type and compares against `test/render-baseline/`. Any change claiming to be
output-neutral should be proved with it, not asserted.

For a change that is meant to be *purely* cosmetic — a docblock pass, a reformat —
`make token-diff` is stronger still: it compares the PHP token stream against a revision with
whitespace and comments dropped, so an "identical" verdict does not depend on what the test
suite happens to reach. Commits proved that way are listed in `.git-blame-ignore-revs`.
