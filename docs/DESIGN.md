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
