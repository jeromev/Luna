# Modules (Mods)

A **mod** is a pluggable page module: a PHP class that, when its page is
requested, injects data into the RDF model (and optionally handles form
submissions). A page renders the union of the mods linked to it that the current
user is allowed to see.

Built-in mods live in [luna/luna.mods/](../luna/luna.mods/). A domain can ship
its own under `luna.domains/<domain>/mods/`.

## How mods are linked to pages

There is no per-mod config file — wiring is data in the graph. As covered in
[architecture.md](architecture.md), `load_mods()` runs one SQL query that finds
mods where:

- the mod is linked to the current page (`luna_nodes_map`: page → mod), **and**
- the mod is linked to an access level (`luna_nodes_map`: mod → level), **and**
- the current user holds that level.

These links are created/edited from the admin UI (`mod_admin_pages`), which calls
`lunaModel::link()` / `unlink()` to write `luna_nodes_map` rows. A mod must be
linked to at least one page and one level to ever run.

## The mod contract

There is **no base class**. A mod is a class whose name equals its `lid`, that
follows the singleton convention, and that implements whichever lifecycle hooks
it needs. The reference implementation is
[luna.mod_example.php](../luna/luna.mods/luna.mod_example.php).

### Required shape

```php
class mod_example {
    private static $instance;

    // Singleton — required; load_mods() calls <lid>::singleton().
    public static function singleton() {
        if (!isset(self::$instance)) {
            $c = __CLASS__;
            self::$instance = new $c;
        }
        return self::$instance;
    }

    // Cloning is forbidden by convention.
    public function __clone() {
        trigger_error('clones are not allowed', E_USER_ERROR);
    }

    // Private constructor — register translatable UI strings here.
    private function __construct() {
        lunaTools::add_vocabulary(array('My label', 'Another label'));
    }

    // Called on every render to inject this mod's data into the model.
    public function load() {
        luna::$model->merge_index(
            luna::$model->load_data($someArray, 'example')
        );
    }
}
```

### Lifecycle hooks

All hooks are optional except `singleton()`. `load_mods()` checks for each with
`method_exists()` before calling it.

| Hook | When it runs | Typical job |
|---|---|---|
| `__construct()` | On instantiation | Register vocabulary (`lunaTools::add_vocabulary`) |
| `submit()` | A form was posted (`submit` or `batch_submit` present) | Generic submit handling |
| `submit_add()` | Posted with `$_POST['mode'] == 'add'` | Validate + insert a new record |
| `submit_modify()` | `mode == 'modify'` (and submit ≠ "Delete") | Validate + update a record |
| `submit_delete()` | `mode == 'delete'`, or `modify` + submit == "Delete" | Remove a record |
| `load()` | Every render (after any submit) | Merge this mod's nodes into the model |

On a submit the dispatcher calls the generic `submit()` **first** (if defined),
*then* the mode-specific hook selected by the `$_POST['mode']` switch — they are
not alternatives, both fire (`load_mods()` in [luna.php](../luna/luna.php)).

After **any** submit (when the request is not AJAX), `load_mods()` runs
`lunaDB::optimise()` (an `OPTIMIZE TABLE`) on the nodes/map/actions tables —
regardless of whether the submit actually succeeded (`load_mods()` in [luna.php](../luna/luna.php)).

### Injecting data into the model

A mod reaches the singleton model at `luna::$model` and merges nodes in. The
common calls (see [rdf-model.md](rdf-model.md)):

- `luna::$model->merge_index(...)` — merge nodes into the store.
- `luna::$model->load_data($array, $label)` — flatten a PHP array into
  render-model variable nodes (blank nodes in the `ui:` namespace,
  `https://jeromev.github.io/Luna/render#`).
- `luna::$model->load_var($spec)` — create a single `ui:` render-model blank
  node (the i18n/form/list scaffolding the XSLT chrome reads; it never reaches
  the published content graph).
- `luna::$model->load_nodes($type1, $type2, $nid)` — pull typed nodes + edges
  from the DB.
- `luna::$model->set_property($node, $lid, $value)` — set a property.
- `insert()` / `link()` / `update()` / `unlink()` — graph mutation that writes
  through to MySQL (the system of record) and, best-effort, mirrors the affected
  node(s) into the SPARQL triplestore (Oxigraph) via `rdf_sync_node()` (used by the
  admin CRUD mods).

The XSLT stylesheet for the page then reads these nodes out of the RDF/XML — see
[templating.md](templating.md).

## The built-in mods

| Mod | Purpose |
|---|---|
| [`mod_example`](../luna/luna.mods/luna.mod_example.php) | Reference/template mod; does nothing useful |
| [`mod_admin`](../luna/luna.mods/luna.mod_admin.php) | Site config editor (sitename, timezone, session length, keywords, cache). Its `load()` only merges the `luna_config` values into the model; the groups/levels/online-users widgets on the admin page come from *other* mods linked to that page, not from `mod_admin` itself |
| [`mod_admin_groups`](../luna/luna.mods/luna.mod_admin_groups.php) | CRUD for user groups |
| [`mod_admin_levels`](../luna/luna.mods/luna.mod_admin_levels.php) | CRUD for access levels |
| [`mod_admin_users`](../luna/luna.mods/luna.mod_admin_users.php) | CRUD for users (add/modify/delete) |
| [`mod_admin_pages`](../luna/luna.mods/luna.mod_admin_pages.php) | CRUD for pages; links mods + a level to each page |
| [`mod_admin_mods`](../luna/luna.mods/luna.mod_admin_mods.php) | Register/enable/disable available modules |
| [`mod_edit_texts`](../luna/luna.mods/luna.mod_edit_texts.php) | Edit page text blocks per language (Markdown source in a plain textarea) |
| [`mod_journal`](../luna/luna.mods/luna.mod_journal.php) | Journal/activity view over the `luna_logs` table (it queries `LOGS`, **not** `luna_actions`); also handles the `purgelogs` action that wipes the log |
| [`mod_log`](../luna/luna.mods/luna.mod_log.php) | Login/logout + authentication handling |
| [`mod_online_users`](../luna/luna.mods/luna.mod_online_users.php) | Lists currently active sessions (IP, lang, last URL) |

Most of these are the admin backend. The set of protected, system-critical lids
(`admin`, `admin_*`, `login`, `logout`) is guarded in `luna::$protected_lids` so
they cannot be renamed or deleted.

## Writing a new mod

1. Create `luna/luna.mods/luna.mod_myfeature.php` (or
   `luna.domains/<domain>/mods/myfeature.php`) defining `class mod_myfeature`
   following the shape above.
2. In the admin UI, register it via **Admin → Mods** (`mod_admin_mods`) so it
   becomes a node of type `mod` with `lid = mod_myfeature`.
3. Link it to a page and an access level via **Admin → Pages**
   (`mod_admin_pages`).
4. Add an XSLT stylesheet (or rely on `default`) that reads the nodes your
   `load()` merges. See [templating.md](templating.md).
