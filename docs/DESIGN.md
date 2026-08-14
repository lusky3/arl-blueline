# DESIGN.md — `blueline`

The visual system for the ARL theme. Derived from the 2026 brand mark; every ratio here is
asserted by `themes/blueline/tools/check-contrast.mjs`, which fails the build on drift.

## Visual theme

**"Blue Line."** Navy chrome (header, hero bands, footer) over a paper-white content body.
Dark where drama helps, light where dense SportsPress tables have to stay readable.

The mark is a **blue** maple leaf carrying a diagonal banner: "BURLINGTON" in small caps above
"A.R.L." in heavy italic varsity lettering. Four devices come out of it and nothing else does.

## Color palette

Ratios are against the paper ground `#F7FBFC`.

| Token | Hex | On paper | Permitted use |
|---|---|---|---|
| `--bl-ink` | `#132343` | **14.94** | body text, dark bands, footer |
| `--bl-ink-deep` | `#0D1729` | — | hero / footer ground |
| `--bl-ink-mid` | `#2E4A74` | 8.59 | secondary text |
| `--bl-accent-text` | `#3F6E9D` | **5.13** | links, accent text on light |
| `--bl-steel` | `#5188B7` | 3.63 | borders, large text, UI strokes only |
| `--bl-ice` | `#74C0E1` | **1.94** | **fill only — never text on light** |
| `--bl-pale` | `#9ACDE7` | 1.64 | decorative; text only on dark (9.09 on ink) |
| `--bl-paper` | `#F7FBFC` | — | page ground |

**Two rules that fall out of the arithmetic and are not negotiable:**

- Ice blue is a fill. `--bl-ink` on `--bl-ice` is 7.69:1, so the skewed Register button is fine.
  Ice as text on light is 1.94:1 and fails everything.
- White on `--bl-steel` is 3.78:1 and fails AA for nav-sized text. Active nav is `--bl-paper` on
  `--bl-ink` with an `--bl-ice` underline.

**Color strategy: committed.** Navy carries the chrome; ice is the single accent, used as fill.

**Team colors (SportsPress).** Each team stores `sp_colors` (`primary`, `background`, `text`,
`heading`, `link`). These are league-entered and frequently fail contrast on their own (white
headings on near-white backgrounds, etc). Team color is expressed as an **accent only**, scoped
to that team's pages, with foregrounds derived for contrast rather than trusted.

## Typography

| Role | Face | Notes |
|---|---|---|
| Display | Barlow Condensed 800 italic | Closest free match to the mark's varsity lettering |
| Body / UI / tables | Inter (variable, 400–700) | Tabular figures so standings columns align |

Both self-hosted. Fluid scale via `clamp()`.

**Hard rule:** the display face is for headings, eyebrows and buttons only. **Never body copy,
never table cells.** Heavy italic caps in running text is the failure mode of this direction.

## The four signature devices

1. **The skew** — −11°, matching the banner. On ribbons, primary buttons, active nav, eyebrows.
   Text is counter-skewed so glyphs stay upright; the focus ring is drawn on the **un-skewed
   parent** so keyboard focus and hit targets stay honest.
2. **Blue-line bands** — a paired 9px ice + 4px navy rule between major page bands.
3. **Faceoff geometry** — concentric rings as large low-opacity art inside dark bands.
4. **The blue leaf** — the mark's silhouette as section watermark and empty-state mark.

## Layout

4px spacing base. `--bl-container` 1200px, shared by every band including the header.

Wide tables scroll inside their own container; **the page body never scrolls horizontally.**
A class-agnostic DOM pass wraps SportsPress tables, with `html { overflow-x: clip }` as backstop.

## Components

Cards are used sparingly and are never nested. Empty states are explicit everywhere: the leaf
mark plus one line, never an empty container.

## Motion

Minimal and purposeful. Every animation has a `prefers-reduced-motion: reduce` alternative.
No bounce, no elastic.

## Known constraints

- SportsPress emits no frontend CSS on this site (`sportspress_enable_frontend_css` unset), so
  the theme supplies the only styling that exists for SP surfaces.
- The `simple-css` plugin injects sitewide CSS that survives theme changes. It was pruned during
  R1; anything re-added there can override tokens with `!important`.

## Contributing

There is **no CI** in this repository — no `.github`, no `.gitlab-ci.yml`, no `Jenkinsfile`. The
checks below are the only quality gate that exists; they only run if you run them.

**Setup, from a fresh clone, in `themes/blueline/`:**

```
npm install
composer install
```

`node_modules/` and `vendor/` are both gitignored, so both installs are required before any of
the commands below will work — there is nothing partially usable straight out of `git clone`.

**Enable the pre-commit hook** (also from the repo root, once per clone — this is local git
config, not something committed, so a fresh clone or a new worktree does not inherit it):

```
git config core.hooksPath .githooks
```

With that set, committing anything under `themes/blueline/` runs the full check automatically
(`.githooks/pre-commit`) and blocks the commit if it fails. Without it, nothing enforces these
gates at all — they become purely a "run it yourself before you push" convention.

**Run every gate by hand** at any time with:

```
npm run check
```

which chains, in order:

1. `lint:css` — `wp-scripts lint-style` over `assets/src/css/**/*.css`.
2. `lint:js` — `wp-scripts lint-js` over `assets/src/js/**/*.js`.
3. `test:js` — the Node test runner over `tools/**/*.test.mjs` (the CSS-token/contrast-maths
   unit tests).
4. `tokens:check` — `tools/check-contrast.mjs`, the WCAG contrast guard: every pairing in
   `tools/contrast-rules.json` re-derived from the real `style.css`, plus the editor.css /
   team-colors.php token-parity checks and the anti-`rgba()`-hardcoding lint.
5. `composer test` — the PHPUnit suite.
6. `composer lint` — `phpcs --standard=WordPress` over the theme's PHP (see `composer.json`'s
   `scripts-descriptions.lint` for exactly which `woocommerce/` paths are excluded as vendor
   code, and why).

All six must exit 0. Because there is no CI, that is a statement about your own working tree
right now, not a promise anyone else has verified — re-run it after pulling, and before every
commit if the hook above is not enabled.

### Before turning on `BLUELINE_SRCACHE_PURGE`

`inc/settings/cache.php` implements a guarded Redis `SCAN`/`UNLINK` purge of the nginx srcache
page cache on settings save, but ships with the `BLUELINE_SRCACHE_PURGE` constant **off** by
default. The reasoning is in that file's own docblock; the short version:

The WordPress object cache (via the Redis Object Cache drop-in's `redis_instance()`) and
nginx's srcache module may not point at the **same Redis server and logical DB index** —
hardened production setups routinely separate them. If they differ, the purge connects fine,
`SCAN`s an empty keyspace, and reports success while purging nothing — worse than not purging,
because it lies to the admin who just saved. Staging has no page-cache layer at all, so a green
staging run cannot tell "the purge worked" from "there was nothing to purge either way" — this
is the one component staging structurally cannot validate.

Before ever defining `BLUELINE_SRCACHE_PURGE` as `true` (e.g. in `wp-config.php`), confirm on
the actual server:

1. Which Redis server/DB index nginx's srcache module writes into — `nginx.conf`'s
   `srcache_store`/`redis2_query` (or equivalent) directives.
2. Which Redis server/DB index the WordPress object cache connects to —
   `wp-config.php`'s `WP_REDIS_HOST`/`WP_REDIS_PORT`/`WP_REDIS_DATABASE` (or equivalent).
3. That (1) and (2) name the **same host AND the same DB index** — not merely the same host.
4. Only then flip the constant, and verify by hand that a save actually evicts a known cached
   page before trusting it unattended.

Until that verification happens, the panel shows a persistent, dismissible admin notice naming
the exact manual purge command instead — see `blueline_cache_purge_notice_message()` and
`blueline_cache_purge_command()`.
