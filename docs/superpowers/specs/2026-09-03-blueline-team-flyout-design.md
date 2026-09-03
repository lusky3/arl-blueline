# Team flyout menu — design spec

## Goal

Give the admin control panel a second display position for the theme's team
directory (today: a wrapping crest list near the footer, `blueline_footer_team_directory()`).
The new position is a sitewide, always-available left-edge flyout: a small
trigger tab that reveals a scrollable column of floating, gently bobbing
crest bubbles. Off / Footer / Flyout — exactly one of the three, admin-picked.

Chosen over a second concept (a spinnable rotary-dial wheel) explored via
mockups: bubbles reuse a plain scrollable list under the hood, which is
trivially keyboard/screen-reader accessible and touch-native (no custom
drag-to-rotate gesture competing with the page's own vertical scroll), and
their motion — a gentle float — reads closer to DESIGN.md's "minimal and
purposeful... no bounce, no elastic" than a spinning novelty would.

## Why now

The theme already disables SportsPress Pro's own League Menu on the front
end (`inc/sportspress.php`'s `option_sportspress_league_menu_*` filters) — it
used to prepend 22-34 crest links to `<body>` on every page, ahead of the
skip link and, at narrow widths, on top of the open mobile drawer. The
theme's own `blueline_footer_team_directory()` replaced it, admin-configured
list intact, just relocated below the fold. This adds a second, more visible
position for admins who want the directory more prominent than the footer
without reopening that original problem — the flyout is deliberately
sitewide chrome (like the floating next-game widget), not another
`<body>`-prepended block competing with the skip link.

## Architecture

**New file, `inc/team-flyout.php`** — same "deliberately its own file"
rationale `inc/floating-next-game.php` already documents: sitewide chrome
consumed from `footer.php`, independently toggleable, not a My Account
dashboard module even though it touches team data. `require_once`'d from
`functions.php` next to the floating-next-game require.

**Rendering:** `blueline_render_team_flyout(): void`, called from
`footer.php` directly beside the existing `blueline_render_floating_next_game();`
call — same "prints nothing at all, or the whole fixed-position widget" shape.
Reuses `blueline_league_menu_team_ids()` (`inc/sportspress.php`) unchanged:
identical admin-configured team list and order to today's footer directory,
no new data source.

**Styles:** new `assets/src/css/team-flyout.css`, enqueued the same way
`floating-next-game.css` already is — same "its own file" reasoning as the
PHP side.

**Gate:**
```
if ( ! blueline_section_enabled( 'chrome_footer_teams' ) ) return;
if ( 'flyout' !== blueline_settings( 'chrome_team_directory_position' ) ) return;
$team_ids = blueline_league_menu_team_ids();
if ( ! $team_ids ) return;
```
`chrome_footer_teams` keeps its current meaning (master on/off for the
team directory as a whole) and its current admin-facing label; it is NOT
renamed or migrated. `blueline_footer_team_directory()` (template-tags.php)
gets the same two-line guard in reverse (renders only when the position is
`'footer'`), so the two renderers are strictly mutually exclusive and the
master toggle still turns both off at once.

## Admin control panel

One new field in `blueline_settings_schema()` (`inc/settings/defaults.php`),
declared manually (not part of the `blueline_section_definitions()` auto-generated
loop, since it needs `choices` and that loop only emits `type: 'section'`
booleans) but sharing `chrome_footer_teams`'s `tab` and `group` so it renders
directly beside it in the admin UI:

```php
'chrome_team_directory_position' => array(
    'type'    => 'text',
    'tab'     => 'sections',
    'group'   => 'Site chrome',
    'label'   => 'Team directory position',
    'help'    => 'Only matters while the team directory above is on.',
    'choices' => array(
        'footer' => 'Footer directory',
        'flyout' => 'Flyout menu',
    ),
),
```
Default `'footer'` — matches this codebase's stated principle that installing
a new field changes no rendered output until an admin actually edits it.
Modeled directly on the existing `announcement_severity` field (same shape:
`type: text` + `choices`, no empty option, a value always selected).

## Component behavior

**Trigger:** a small tab flush to the left edge, vertically centered,
containing a rotated "Teams" label — same idea as the mockups shown during
brainstorming. Opens on hover on non-touch input (`@media (hover: hover)`)
and toggles open/closed on click/tap universally — the same interaction
shape the account nav's own Billing `<details>` disclosure already
establishes in this codebase (`woocommerce/myaccount/navigation.php`), and
the recommended implementation: a native `<details>`/`<summary>` pair needs
no JavaScript for open/close at all, `<summary>` being the trigger tab and
the crest list living in the disclosed content.

**Bubble list:** a vertically scrollable `<nav aria-label="Teams">` containing
a plain `<ul>` of `<a>` team links — real, individually focusable anchor
elements, not `<button>`s wired to a JS click handler, so Tab order and
screen readers get the list for free with zero extra ARIA. Each item is a
circular crest wrapped in its link, sized and offset with slight per-item
variation (staggered size/horizontal jitter) for an organic, non-gridded
feel, with an independent CSS `@keyframes` bob (small vertical drift, varied
duration/delay per item so they never move in lockstep).

**Scrollbar:** hidden per explicit instruction (`scrollbar-width: none` +
the `::-webkit-scrollbar { display: none }` pair) — scroll (wheel, trackpad,
touch drag) stays fully functional, only the visible track is suppressed.

**Crest fallback:** a team with no featured image renders the theme's
existing leaf-mark icon (`blueline_leaf_mark()`, already used for this exact
purpose on the account Player Profile bio and the claim card) instead of a
blank circle — matching DESIGN.md's "never an empty container" rule and
this codebase's established empty-state idiom, rather than the footer
directory's current behavior of silently omitting the image.

**Hover/focus state:** a bubble pauses its bob (`animation-play-state:
paused`) and gets an ice-colored ring on hover or keyboard focus — mirrors
the mockup shown during brainstorming, and gives keyboard users the same
"this one's selected" affordance mouse users get.

## Motion and accessibility

- `prefers-reduced-motion: reduce` disables the bob keyframe entirely
  (bubbles sit still; hover/focus ring still works) — sitewide convention,
  no exceptions (DESIGN.md: "Every animation has a `prefers-reduced-motion:
  reduce` alternative").
- The whole thing degrades to a static, fully keyboard-operable list with
  JavaScript unavailable: `<details>` is native, the bob is decorative CSS
  layered on top of real links, not the mechanism that makes them reachable.
- Touch: the flyout adapts in place rather than falling back to the footer
  directory below a breakpoint (explicit decision made during brainstorming)
  — sizing/spacing may need a narrower variant at small widths, left to the
  implementation plan, but the interaction model (tap the tab to open, tap a
  crest to navigate, tap outside or the tab again to close) stays the same
  shape as desktop, just without the hover pre-reveal.

## Out of scope

- The rotary-dial concept explored during brainstorming — not being built.
- Reordering or otherwise changing which teams appear or in what order
  (still 100% admin-configured via SportsPress's own League Menu settings
  screen, read through the existing `blueline_league_menu_team_ids()`).
- Any change to the footer directory's own markup/behavior beyond the new
  guard clause making it mutually exclusive with the flyout.
- Migrating or renaming the existing `chrome_footer_teams` setting.
