# Blueline P2 Phase 2.3 — Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship §9's four documentation deliverables — the last remaining item of the whole P2 initiative — with zero change to any already-shipped Phase 2.0/2.1a/2.1b/2.2 behavior: one new `DESIGN.md` table row plus a pointer sentence, one new `PRODUCT.md` sentence, two new cutover-checklist items, and a permanent, non-dismissible `<details>`/`<summary>` help pane replacing the Occasions tab's one-sentence intro.

**Architecture:** Three of the four tasks are pure prose edits to committed Markdown at the repo root (`docs/DESIGN.md`, `docs/PRODUCT.md`, `docs/superpowers/plans/2026-08-11-blueline-r1-verification.md`) — no code, no tests. The fourth touches exactly one function, `blueline_settings_render_occasions_tab()` (`themes/blueline/inc/settings/page.php`), replacing its existing `<p class="description">` intro with a native `<details>`/`<summary>` element that needs no new CSS or JS, covered by a new PHPUnit test in the existing `tests/SettingsOccasionsTabTest.php`.

**Tech Stack:** Markdown (repo-root `docs/`); PHP 8.1+ / PHPUnit 12 for the one code task (`themes/blueline/`).

**Spec:** docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md (§9.1 specifically)

## Global Constraints

- **Ruling (a): the Occasions tab's help pane is a permanent, non-dismissible `<details>`/`<summary>` disclosure.** No new dismissal infrastructure of any kind (no `localStorage`, no `is-dismissible` + AJAX + user-meta) — a native `<details>` element needs zero JS and zero new storage.
- **Ruling (b): the new pane REPLACES the Occasions tab's existing one-sentence intro paragraph, it does not supplement it.** The existing sentence's content is folded into the pane's own intro rather than duplicated as a second explanation stacked above or below it.
- **Ruling (c): `DESIGN.md`'s table gains exactly one new row** (for `--bl-occasion-accent`, describing its default-resolves-to and its conditional-pass nature, not a fixed hex/ratio) **plus one pointer sentence** naming `tools/contrast-rules.json` as the real, machine-checked contract. This is NOT a full mirror of every rule in that file — DESIGN.md's table stays a reference subset.
- **Ruling (d): `PRODUCT.md`'s new sentence goes in "Accessibility requirements," not into principle #5's own one-line statement.** Principle #5 ("Accessibility is a floor, not a finish") keeps its exact current wording, untouched.
- **Ruling (e): the cutover checklist gains exactly two new numbered items**, sequenced immediately after the existing item 6 (the general srcache purge) and before the existing item 8 (final smoke test) — which means the two new items become items 7 and 8, and the existing items 7 and 8 are renumbered to 9 and 10 — **plus one added clause to the closing "Sequence matters" paragraph** covering both new items.
- No placeholders. Every doc edit below is the actual final prose to apply, in full — not a description of what it should say.
- Tasks 1–3 touch files under the repo root (`docs/`), OUTSIDE `themes/blueline/` — run their steps from the repo root. Task 4 touches `themes/blueline/inc/settings/page.php` and its test — `composer test`/`composer lint`/`npm run check` for that task must run from `themes/blueline/`, where `composer.json`/`package.json` live.
- Do not touch the hidden marker `<input>` (`data-bl-occasions-marker`) immediately before the intro paragraph in `blueline_settings_render_occasions_tab()` — it is load-bearing (Phase 2.2's fix for the delete-to-empty bug) and entirely unrelated to this plan.
- No new CSS or JS for the `<details>`/`<summary>` pane. Confirmed by direct search: no `details`/`summary` selector exists in `style.css` or `assets/src/css/editor.css`, and no admin-side stylesheet is enqueued for the settings page at all (only `wp-admin`'s own inline-style additions for an unrelated photo picker) — plain, browser-default `<details>` is in scope and sufficient.
- This plan does not touch any already-shipped 2.0/2.1a/2.1b/2.2 function's behavior — `blueline_sanitize_occasions()`, `blueline_resolve_active_occasion()`, `blueline_occasions_apply_aa_overrides()`, `blueline_record_acknowledgement()`, `blueline_remove_acknowledgement()`, `blueline_acknowledgement_covers()`, `blueline_stored_acknowledgements()`, `blueline_settings_render_occasion_row()`, none of it. Task 4 only changes markup that surrounds those calls inside `blueline_settings_render_occasions_tab()`.

---

### Task 1: `DESIGN.md` — the `--bl-occasion-accent` table row and pointer sentence

**Files:**
- Modify: `docs/DESIGN.md:18-29`

**Interfaces:**
- Consumes: nothing from another task.
- Produces: an updated "Color palette" hex table and a new pointer sentence naming `tools/contrast-rules.json` as the enforced contract. Read-only prose; nothing else in the repo consumes this text programmatically.

- [ ] **Step 1: Apply this exact edit**

  In `docs/DESIGN.md`, replace:

  ```
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
  ```

  with:

  ```
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
  | `--bl-occasion-accent` | resolves to `--bl-ice` (`#74C0E1`) by default; admin-settable per occasion | `ink-on-occasion-accent`, ≥ 4.5:1 — or a recorded AA-override acknowledgement when it fails (see PRODUCT.md's "Accessibility requirements") | fill only — CTA ribbon, signature band, motif; never text on light |

  This table is a reference subset, not the enforced contract: `themes/blueline/tools/contrast-rules.json` is the real, machine-checked contract the build gate reads, and it carries more rules than this table lists — including the focus ring, borders, and the success/warning/danger status colours, none of which this table has ever enumerated.

  **Two rules that fall out of the arithmetic and are not negotiable:**
  ```

- [ ] **Step 2: Verify**

  Read `docs/DESIGN.md` after editing and confirm: the table now has nine rows ending with `--bl-occasion-accent`; the new pointer sentence reads correctly as its own paragraph between the table and the "Two rules..." paragraph; no other line in the file changed.

- [ ] **Step 3: Commit**
  ```bash
  git add docs/DESIGN.md
  git commit -m "Add the occasion accent to DESIGN.md's colour table and point at contrast-rules.json"
  ```

---

### Task 2: `PRODUCT.md` — name the AA-override acknowledgement mechanism

**Files:**
- Modify: `docs/PRODUCT.md:63-67`

**Interfaces:**
- Consumes: nothing from another task.
- Produces: one new factual sentence in "Accessibility requirements" naming the acknowledgement mechanism concretely. Principle #5's wording is untouched.

- [ ] **Step 1: Apply this exact edit**

  In `docs/PRODUCT.md`, replace:

  ```
  ## Accessibility requirements

  WCAG 2.2 AA. Contrast ratios are asserted in CI (`tools/check-contrast.mjs`), not assumed.
  Keyboard traversal, visible focus on every control including skewed ones, no horizontal page
  scroll at 360px, and a working skip link.
  ```

  with:

  ```
  ## Accessibility requirements

  WCAG 2.2 AA. Contrast ratios are asserted in CI (`tools/check-contrast.mjs`), not assumed.
  Keyboard traversal, visible focus on every control including skewed ones, no horizontal page
  scroll at 360px, and a working skip link.

  The one narrow exception is explicit and logged, never silent: an Advanced-tier admin can
  acknowledge one specific failing colour (for example, an Occasion's accent) at save time, and
  that acknowledgement is scoped to exactly what failed, re-checked live on every request, and
  falls back to the safe default the moment it goes stale or missing.
  ```

- [ ] **Step 2: Verify**

  Read `docs/PRODUCT.md` after editing and confirm: principle #5 (the numbered list above this section) is byte-for-byte unchanged; the new paragraph reads as a natural continuation of "Accessibility requirements," not a contradiction of the existing three sentences.

- [ ] **Step 3: Commit**
  ```bash
  git add docs/PRODUCT.md
  git commit -m "Name the AA-override acknowledgement mechanism in PRODUCT.md"
  ```

---

### Task 3: The cutover checklist — two new items plus a "Sequence matters" clause

**Files:**
- Modify: `docs/superpowers/plans/2026-08-11-blueline-r1-verification.md:810-822`

**Interfaces:**
- Consumes: nothing from another task.
- Produces: two new numbered checklist items (7 and 8), the former items 7 and 8 renumbered to 9 and 10, and one added clause in the closing "Sequence matters" paragraph.

- [ ] **Step 1: Apply this exact edit**

  In `docs/superpowers/plans/2026-08-11-blueline-r1-verification.md`, replace:

  ```
  6. **Purge the Redis-backed nginx srcache by key** after deploying/activating. `wo clean
     --fastcgi` does **not** touch this cache layer — it needs its own purge mechanism.
  7. **`show_avatars` is off site-wide** and an active Code Snippets rule (ID 23) strips Gravatars
     — the avatar migration (#4) will produce **no visible change** even once it succeeds. This is
     expected, not a sign the migration failed; don't "verify" it by looking for visible avatars.
  8. **Re-run smoke against production** after cutover (`BASE=https://rookiehockey.ca
     ./scripts/smoke-staging.sh`, or the production equivalent) before considering cutover
     complete.

  Sequence matters: 1–2 should happen before/alongside activation (menu and bot-protection gaps
  are user-facing immediately); 3 can happen any time after activation; 4–5 must happen in that
  order (mu-plugin check *before* any rewrite flush) and 4 is only meaningful once the theme is
  active; 6 happens last, after every other change that could be cached.
  ```

  with:

  ```
  6. **Purge the Redis-backed nginx srcache by key** after deploying/activating. `wo clean
     --fastcgi` does **not** touch this cache layer — it needs its own purge mechanism.
  7. **Verify `BLUELINE_SRCACHE_PURGE`'s shared-Redis-instance prerequisites before flipping it
     on.** `docs/DESIGN.md`'s "Before turning on `BLUELINE_SRCACHE_PURGE`" section lists the four
     manual verification steps (same Redis server, same DB index, confirmed by hand on the actual
     server) — walk through them again against production specifically, and only then set the
     constant to `true` in `wp-config.php`. This is independent of item 6's manual by-key purge,
     which needs no flag at all.
  8. **Verify the occasion scheduling WP-Cron boundary-purge event fires correctly against
     production.** The srcache purge this event triggers at each occasion's window boundary has
     never been exercised anywhere but production — staging has no page-cache layer to prove a
     purge against — so the first real confirmation happens here, at the next window boundary
     after cutover, not before.
  9. **`show_avatars` is off site-wide** and an active Code Snippets rule (ID 23) strips Gravatars
     — the avatar migration (#4) will produce **no visible change** even once it succeeds. This is
     expected, not a sign the migration failed; don't "verify" it by looking for visible avatars.
  10. **Re-run smoke against production** after cutover (`BASE=https://rookiehockey.ca
      ./scripts/smoke-staging.sh`, or the production equivalent) before considering cutover
      complete.

  Sequence matters: 1–2 should happen before/alongside activation (menu and bot-protection gaps
  are user-facing immediately); 3 can happen any time after activation; 4–5 must happen in that
  order (mu-plugin check *before* any rewrite flush) and 4 is only meaningful once the theme is
  active; 6 happens last, after every other change that could be cached; 7 is independent of
  activation timing and must be confirmed before `BLUELINE_SRCACHE_PURGE` is ever set to `true`,
  whenever that happens; 8 can only be confirmed after cutover, at the next occasion window
  boundary, and presumes 6 has already fired at least once so the cache being purged is in a
  known state.
  ```

- [ ] **Step 2: Verify**

  Read the file after editing and confirm: the list now runs 1 through 10 with no gap or
  duplicate number; items 9 and 10 are byte-for-byte the old items 7 and 8 except for their
  leading digit; the "Sequence matters" paragraph's original four clauses are unchanged and the
  new clause reads as a natural continuation.

- [ ] **Step 3: Commit**
  ```bash
  git add docs/superpowers/plans/2026-08-11-blueline-r1-verification.md
  git commit -m "Add srcache-prerequisite and occasion cron-purge verification to the cutover checklist"
  ```

---

### Task 4: The Occasions tab's permanent help pane

**Files:**
- Modify: `themes/blueline/inc/settings/page.php:2341-2347`
- Test: `themes/blueline/tests/SettingsOccasionsTabTest.php`

**Interfaces:**
- Consumes: `blueline_settings_render_occasions_tab()`'s existing surrounding markup (the hidden marker `<input>`, the `<ul class="bl-occasions__list">` repeater, the toolbar, the `<template>`) — none of it changes.
- Produces: a `<details class="bl-occasions__help">` / `<summary>` disclosure replacing the old standalone `<p class="description">` intro, folding that sentence's content in as the pane's first paragraph.

- [ ] **Step 1: Write the failing test**

  Add to `tests/SettingsOccasionsTabTest.php`, immediately before the final closing `}` of the
  class (after `test_the_original_id_hidden_field_carries_the_existing_id()`):

  ```php

  	/**
  	 * The Occasions tab's intro is now a permanent, non-dismissible
  	 * <details>/<summary> help pane (design spec §9.1's first and second
  	 * rulings) -- not a dismissible notice, and not a bare intro
  	 * paragraph sitting outside any disclosure. The folded-in intro
  	 * sentence must render INSIDE the <details> element, replacing the
  	 * old standalone paragraph rather than sitting alongside it.
  	 */
  	public function test_the_intro_is_a_permanent_details_pane_not_a_standalone_paragraph(): void {
  		ob_start();
  		blueline_settings_render_occasions_tab();
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString( '<details', $html );
  		$this->assertStringContainsString( '<summary>', $html );
  		$this->assertStringNotContainsString( 'is-dismissible', $html );

  		$details_pos = strpos( $html, '<details' );
  		$intro_pos    = strpos( $html, 'Occasions add a temporary accent colour' );
  		$close_pos    = strpos( $html, '</details>' );

  		$this->assertNotFalse( $details_pos, 'A <details> element must render.' );
  		$this->assertNotFalse( $intro_pos, 'The folded-in intro sentence must still render somewhere.' );
  		$this->assertNotFalse( $close_pos, 'The <details> element must be closed.' );
  		$this->assertGreaterThan( $details_pos, $intro_pos, 'The folded-in intro sentence must render after <details> opens.' );
  		$this->assertLessThan( $close_pos, $intro_pos, 'The folded-in intro sentence must render before </details> closes.' );

  		// The OLD standalone intro paragraph (a bare <p class="description">
  		// sitting outside any disclosure) is gone -- there is now exactly
  		// one occurrence of this sentence in the whole tab, and it is the
  		// one already proven above to sit inside <details>.
  		$this->assertSame( 1, substr_count( $html, 'Occasions add a temporary accent colour' ) );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  From `themes/blueline/`, run: `composer test -- --filter SettingsOccasionsTabTest` — Expected:
  FAIL on the new test (the current markup has no `<details>`/`<summary>` at all — `assertStringContainsString( '<details', $html )` fails first).

- [ ] **Step 3: Write minimal implementation**

  In `themes/blueline/inc/settings/page.php`, inside `blueline_settings_render_occasions_tab()`,
  replace:

  ```php
  		<p class="description">
  			<?php
  			echo esc_html(
  				__( 'Occasions add a temporary accent colour, a small motif, and an optional line of copy for a set window of the calendar year. Nothing here activates until its Mode is set to something other than "Always off", or its window includes today.', 'blueline' )
  			);
  			?>
  		</p>
  ```

  with:

  ```php
  		<details class="bl-occasions__help">
  			<summary><?php esc_html_e( 'How Occasions work', 'blueline' ); ?></summary>
  			<p class="description">
  				<?php
  				echo esc_html(
  					__( 'Occasions add a temporary accent colour, a small motif, and an optional line of copy for a set window of the calendar year. Nothing here activates until its Mode is set to something other than "Always off", or its window includes today.', 'blueline' )
  				);
  				?>
  			</p>
  			<p class="description">
  				<?php
  				echo esc_html(
  					__( 'Add one from the preset list below, or start with a blank occasion. Each row has its own Mode, which decides when it can activate: "Automatic, during its window" lets its date range decide, "Always on (preview now)" turns it on right now no matter what the calendar says, and "Always off" disables it no matter what the window says.', 'blueline' )
  				);
  				?>
  			</p>
  			<p class="description">
  				<?php
  				echo esc_html(
  					__( 'If an occasion accent colour fails the AA contrast check against ink text, saving is blocked unless the row acknowledgement checkbox is ticked. An acknowledgement is re-checked every time settings are saved; if the accent no longer matches what was acknowledged, the occasion falls back to its default colour instead of showing a colour that fails the check.', 'blueline' )
  				);
  				?>
  			</p>
  		</details>
  ```

  This is a plain, browser-default `<details>`/`<summary>` — no new CSS or JS. No `open`
  attribute: collapsed by default matches design spec §9.1's own reasoning (unobtrusive,
  always available, never nags) for why a disclosure was chosen over a dismissible notice.

- [ ] **Step 4: Run test to verify it passes**

  From `themes/blueline/`, run: `composer test -- --filter SettingsOccasionsTabTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  From `themes/blueline/`, run: `composer test` — Expected: PASS. Also run `composer lint` —
  Expected: no new WordPress-Coding-Standards findings in `inc/settings/page.php` or
  `tests/SettingsOccasionsTabTest.php`. This edit sits inside a function several other tests in
  this same file already exercise (empty-state rendering, the marker row, the preset list, the
  `<template>` element) — confirm none of them regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/settings/page.php tests/SettingsOccasionsTabTest.php
  git commit -m "Replace the Occasions tab intro with a permanent details/summary help pane"
  ```
