# Blueline P0 — Verification Record

**Plan:** `2026-08-13-blueline-p0-accessibility-foundation.md`
**Spec:** `2026-08-13-blueline-control-panel-design.md` §5
**Completed:** 2026-08-13 · 23 commits, `5086548..6678ef7`

## Final state

| Gate | Result |
|---|---|
| PHPUnit | **177 tests / 395 assertions** (was 153/355) |
| JS tests (`node --test`) | **42** (new in this work) |
| Contrast guard | **35 `ok` + 1 informational**, exit 0 (was 14 checks) |
| Rule table | **31 rules** (was 11) |
| `composer lint` | exit 0 (was exit 2, 112 findings) |
| Staging smoke | 25/25, exit 0 |

## The two WCAG failures this closed

**Focus indicator — SC 1.4.11 / 2.4.7.** `--bl-accent-text` `#3F6E9D` was **2.91:1 on `--bl-ink`**.
It was also a shorthand token (`--bl-focus: 3px solid var(...)`) that no colour-pair table could
validate, and the *same token as accent text* — so darkening it to satisfy its one governing rule
(5.13 → 10.17 on paper) would have driven the ring to 1.47 on ink while the gate reported success.

No single colour fixes this: the ring is drawn on grounds from `#FFFFFF` through `#74C0E1` to
`#0D1729`. Eight candidates were measured; the best reached 2.64. Now a **two-tone indicator** —
`--bl-focus-color: #0D1729` outline plus `--bl-focus-halo: #FFFFFF` — measuring 17.21 / 17.92 / 8.85 /
15.57 / 17.92 on its five grounds, with the two rings 17.92 apart. All six pairings rule-guarded.

**Form-field borders — SC 1.4.11.** `--bl-border-strong` was `#C3D6E4` = **1.49:1 on white**, against
a 3:1 requirement, while being the sole identifier of every form control (the field fill is
`--bl-white` on a `--bl-paper` page = 1.05:1). Now `#7C93A8` — 3.18 on white, 3.06 on paper.

## What the guard covers now that it did not

Previously 11 contrast pairs over 8 tokens. Added: the focus ring (6 rules), the border tokens (4),
the semantic notice colours `--bl-success`/`--bl-warning`/`--bl-danger` (3), the `color-mix()` tints
those notices sit on (4 — a new derived-value rule type, since a hex-pair table cannot see a computed
value), and the `--bl-white` ground that zebra rows, cards and form fields actually sit on (3).

The rules moved from a JS array to `tools/contrast-rules.json`, read by the build guard and by
`inc/team-colors.php`. Thresholds are **declared** (`thresholds: { body: 4.5, large: 3.0 }`), not
inferred — an earlier revision derived them from `max(rules[].min)`, which meant any future AAA-strength
rule would silently raise the AA floor across 142 team pages.

## Deferred — carry into P1/P2

1. **WooCommerce `.form-row` cascade gap.** WC core's `.woocommerce form .form-row .input-text`
   (0-3-1) outranks the theme's `.woocommerce .input-text` (0-2-0), so checkout billing/shipping,
   login, account-edit and the **coupon** field never receive `--bl-border-strong`. **Not a live
   failure** — WC's own `--wc-form-border-color` measures ~10.7:1. Belongs in the spec's §9
   "Collisions — who wins" table. Note select2 was checked separately and the theme **does** win there
   (0-3-0 vs 0-2-0, computed `rgb(124,147,168)` on staging) — which matters, because select2's default
   `#aaa` is 2.32:1 and would have been a live failure.
2. **`--bl-border` is guarded by `max` rules only.** Correct today (decorative dividers), but §10's
   "every tunable token covered" inverse test will need it reclassified if P1 makes it tunable.
3. **PHP does not validate the rule table's shape.** `validateRule`'s fail-loudly property is JS-only;
   PHP reads `thresholds` and ignores the rest. P1's save-time validator must implement the whole
   contract.
4. **`mixSrgb` must be ported to PHP bit-for-bit.** Gamma-encoded sRGB, no linearisation, convex
   combination of `[0,255]` so never negative; JS `Math.round` and PHP `round()` agree half-away-from-zero
   across the whole reachable domain. The tie case is pinned by a test (`round(127.5) → 128`).
5. **`--bl-team-accent` is dead computation.** Still derived and printed on every team page; its only
   consumer was removed as unsound. Delete it, and rewrite the now-stale mandate at
   `sportspress.css:713-717`.
6. **Two `transparent` mixes are unguardable.** `homepage.css` and `base.css` composite over
   `--bl-paper`, producing `#edf6fa`, on which `--bl-accent-text` is **4.88:1** — the tightest
   homepage pairing, outside every rule because the `mix` endpoint needs two named tokens.
7. **`tests/bootstrap.php` still lacks transient stubs** and `pre_update_option_*`. Spec §6.1.1 makes a
   filemtime-keyed transient load-bearing for the defaults parser, and §6.2 names both write-path hooks.
8. **`accepted_args = 0`** in the stub injects `$value` where core passes nothing. No production code
   relies on it today.
9. **Focus-ring clipping under `overflow: hidden`** (`.sp-event-blocks`, `.sp-event-calendar`,
   `.bl-hero`, `.sp-scoreboard`) is **pre-existing** — the old 3px/2px outline clipped identically; the
   halo extends 1px further. Worth one deliberate tab-through in P2.
10. **`tools/` is runtime-required.** `inc/team-colors.php` reads `tools/contrast-rules.json` on the
    front end. `deploy-theme.sh` now documents this; do not "tidy" it into the exclude list.

## Notes on process

The plan's *outcome specifications* held up without exception. Its *authored algorithm bodies* and its
*enumerations of existing call sites* did not: five defects were found in plan-supplied code or claims
during execution (a broken `node --test` invocation, a cycle detector that rejected valid diamond
dependencies, an unanchored lint pattern that excluded a theme source file, a false claim about which
shadows mirror `--bl-ink`, and a `wp_kses` stub that failed its own test because `strip_tags()` leaves
script *content* behind). Implementers separately found three incomplete enumerations — three
undocumented `--bl-focus` consumers, a fifth hard-coded `rgba()` site, and the WooCommerce cascade.

For future plans: plan-authored algorithms and plan-authored inventories of existing code both need
independent verification before an implementer is told to copy them verbatim.
