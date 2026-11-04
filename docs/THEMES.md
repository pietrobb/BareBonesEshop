# Writing a theme

A theme is a folder in `themes/<name>/` (lowercase letters, digits, `-`, `_`). A store uses it with
`"theme": "<name>"` in its entry in `stores.json`. Themes contain markup, CSS, JS and page layouts
only; data comes from the core (view models), and behaviour belongs in an extension
([EXTENSIONS.md](EXTENSIONS.md)). `themes/bookshop/` is a complete child theme to copy from.

## Package

```
themes/acme/
  theme.json        manifest (required)
  layouts.json      page layouts and homepage sections (optional)
  blocks/           .html blocks that replace the parent's (optional)
  assets/           public CSS/JS/images, served as /themes/acme/assets/...
```

```json
{
  "name": "Acme",
  "version": "1.0.0",
  "description": "What the theme is for",
  "parent": "default",
  "blocks": "blocks",
  "assets": { "css": ["acme.css"], "js": [] },
  "sections": { "author-list": "storefront/section-author-list" },
  "performance_budgets": { "css_bytes": 40960, "js_bytes": 1, "block_bytes": 40960 },
  "quality": { "wcag": "2.2 AA", "minimum_target_px": 24, "requires_reduced_motion": true }
}
```

- `name` and `version` are required. `parent` makes it a child theme: everything it does not
  define comes from the parent (in the end from `default`). Start as a child of `default`; checkout,
  cart and account then keep working without copying their blocks.
- `assets` lists files under `assets/`, loaded on every storefront page after the parent's. Their URLs
  carry `?v=<version>`: raise `version` when an asset changes, or browsers keep the old file.
- `sections` maps a homepage section type (used in `layouts.json`) to a block.
- `performance_budgets` are hard limits checked by `php cli/quality-gates.php`; `quality` declares
  the accessibility level the theme keeps (see below).

## Blocks

A block is an HTML file named by its path under `blocks/`, e.g. `storefront/product-card`. A block is
looked up in this order, the first file found wins: `blocks/stores/<store>/` (one store's override),
the active theme, its parents, the `default` theme, then the base `blocks/`. To change one piece of
the page, copy only that block from `themes/default/blocks/` and edit it.

Template syntax (`src/Template.php`):

| Syntax | Meaning |
|---|---|
| `{{product.name}}` | value, HTML-escaped |
| `{{{product.description_html}}}` | raw HTML; only for values the core already sanitized (`*_html`) |
| `{{> storefront/product-card}}` | include another block with the current data |
| `{{#if x}}…{{else}}…{{/if}}`, `{{#unless x}}` | condition; `{{#if count > 1}}` compares |
| `{{#each items}}…{{else}}…{{/each}}` | loop; `this`, `@index`, `@key`, `@first`, `@last`, `../` for the outer context |
| `{{#block name}}default{{/block}}` | slot that a layout fills |

There is no PHP in a theme. URLs and the core's translated texts are already in the view model, so a
block works in every store language; never write store URLs by hand (stores may live under `/cs/`).

## Texts and languages

Every block gets the UI texts as `t`: `{{t.cart}}`, `{{t.add_to_cart}}`, `{{t.search}}` are the core's texts
from `lang/<locale>.php` in the language of the page. Use those where they fit. Do not add keys to `lang/`:
those files belong to the core, and an update replaces them.

Texts of your own go in the theme, one JSON file per language:

```
themes/acme/lang/en.json   {"acme.tagline": "Hand-made since 1990", "acme.newsletter": "Get our news"}
themes/acme/lang/sk.json   {"acme.tagline": "Ručná výroba od roku 1990", "acme.newsletter": "Odoberajte novinky"}
```

A block reads them like core texts: `{{t.acme.tagline}}`. Rules:

- Keys must start with the theme's folder name and a dot (`acme.`); other keys are ignored, so a theme
  cannot change the core's texts (checkout, the order button, legal wording).
- A language without its own file uses `en.json`; a key missing there shows as the key itself.
- A child theme also gets its parents' texts (`bookshop.` keys in a child of `bookshop`).
- A file that is not valid JSON stops the storefront with an error, and `php cli/theme-check.php`
  reports it, so run the check after editing.

The shipped `bookshop` theme predates theme texts and keeps its `bookshop.` keys in the core's `lang/` files.
Texts the merchant edits (homepage headings, banners) belong in the section `content` in `layouts.json` or in
the admin, not in either file.

## Layouts and homepage sections

`layouts.json` files are merged: the root one, then each theme from `default` down to the active one,
then `blocks/stores/<store>/layouts.json`. Objects merge key by key; arrays (a page's `sections`,
a layout's `slots`) are replaced as a whole. A homepage section has an `id`, a `type` (a key of
`sections` in `theme.json`), optional `settings` and `content`, and a `data_source` with one of the
providers `core.latest-products`, `core.category-products`, `core.tagged-products`,
`core.curated-products`, `core.featured-categories`, `core.specification-values` and `core.banner`.
See `themes/bookshop/layouts.json`.

A section `type` must be a key of `sections` in `theme.json` (yours or a parent's). A type that is not
registered there renders nothing on the page, only `<!-- unregistered section: <type> -->` in the HTML
source, and `php cli/theme-check.php` lists it as a `[WARN]`. A typo in `layouts.json` or a new block
without its `sections` entry is the usual cause.

## Checks

```bash
php cli/theme-check.php --theme=acme   # renders checkout with the theme on a fixture database
php cli/quality-gates.php              # byte budgets, WCAG 2.2 AA invariants, asset paths
php cli/test.php                       # the full suite, theme contract tests included
```

Without `--theme` or `--store` the check covers every theme in `themes/` and every store in the
`stores.json` the shop runs on: after `php cli/setup.php install` that is the copy in the data directory
(`private_runtime_dir`), not `stores.example.json`. The output names the file it used (`Stores: …`);
`--stores-file=<path>` checks another one. `[FAIL]` lines make the check fail (exit 1); `[WARN]` lines
(unregistered sections) do not.

The checkout form is a contract: field names, the CSRF token and the consent checkboxes must stay
as in the default theme's `storefront/checkout` block, or the theme check fails. `cli/update.php`
runs the same check against the new core before it applies an update, and refuses the update when
an installed theme would break checkout. Keep visible focus, targets of at least 24 px, the skip
link and `prefers-reduced-motion` handling from the default theme.
