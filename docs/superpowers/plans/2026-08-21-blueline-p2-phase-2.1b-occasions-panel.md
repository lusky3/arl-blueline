# Blueline P2 Phase 2.1b — Occasions Panel UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the admin-facing Occasions tab in Appearance -> Blueline: a map-keyed repeater UI to add/edit/delete occasions, with server-derived ids, the four shipped presets as an "add from preset" affordance, colour+motif+window+mode fields, a live JS contrast readout backed by a mandatory PHP<->JS parity test, and the save-time AA-override checkbox lifecycle — with zero change to 2.1a's already-shipped backend behavior.

**Architecture:** Everything lives in the already-required `inc/occasions.php` (two new save-time helper functions: id derivation/de-duplication, and the AA-override lifecycle) and `inc/settings/page.php` (a narrow carve-out in the existing sanitize callback, one named exception in tab routing, and the bespoke render functions for the repeater itself). The repeater follows this project's existing conventions: `band_photos`' server-rendered-rows + `<template>`-clone JS pattern for the mechanics (never let JS build markup independently of a server-rendered template), and `<section class="notice ...">` (never a `<div>`) for every admin-facing blocking notice. The live contrast readout is a small, additional JS file (`assets/src/js/settings-occasions.js`) enqueued only on this tab, carrying a deliberate, tested *duplicate* of the WCAG ratio math already in `inc/team-colors.php` — never an import of it.

**Tech Stack:** PHP 8.1+ (PHPUnit 12 against `tests/bootstrap.php`'s WordPress stubs), plain browser JS with no build step (matching `assets/src/js/settings-photos.js`'s own precedent), Node's built-in test runner (`node --test`, i.e. `npm run test:js`) for the JS-side tests.

**Spec:** docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md (§5.1 specifically)

## Global Constraints

- `phpcs:ignoreFile` is forbidden. Only line-level `phpcs:ignore <sniff> -- <reason>`, and a trailing annotation on a line REPLACES a preceding-line one silently — never stack two expecting both to apply.
- No admin notice may render as a bare `<div>` (`tests/NoticeDivGuardTest.php` scans every theme `.php` file, not just this plan's own additions). Every notice this plan adds is a `<section class="notice ...">`.
- Never write a comment or docblock claiming a test proves something that isn't actually true, or that code does something it doesn't — verify before asserting.
- A guard added to a write path retroactively threatens every test that reaches the guarded value through that path. Tasks 2 and 3 both add a new branch inside `blueline_settings_sanitize_callback()` — a single choke point every settings save passes through — so re-run the WHOLE suite after each, not just the new tests.
- `blueline_settings`'s storage shape stays flat. `occasions` remains a map keyed by occasion id, exactly as 2.1a shipped it — this plan adds no nesting.
- **This plan must NOT change the behavior of `blueline_sanitize_occasions()`, `blueline_resolve_active_occasion()`, or any other already-shipped 2.1a function** in `inc/occasions.php` or `inc/settings/acknowledgements.php` — only new callers, new UI, and new JS around them, per the design spec §5.1's own framing that the resolver and sanitizer "need no change."
- **Ruling (design spec §5.1, first): the admin never types an occasion's `id` directly.** No rendered field in this plan accepts a raw id/slug as free text. It is always derived server-side via `sanitize_title( $label )` and de-duplicated against the rest of the same submission and the currently-stored `occasions` array (excluding the row's own existing slot).
- **Ruling (design spec §5.1, second): the Occasions tab's own save is a narrow, explicit, `_tab === 'occasions'`-scoped exception in the existing sanitize callback — not a second save path.** `_schema` and `aa_acknowledgements` keep the existing absolute "a reserved key never survives a tab-scoped submission" rule unchanged; only `occasions`, and only when `_tab` is literally `'occasions'`, is exempted.
- **Ruling (design spec §5.1, third): the Occasions tab itself is one explicit, named exception to "purely schema-derived" tab routing** — not a generalized custom-tab plugin system nobody else needs yet.
- **Ruling (design spec §5.1, fourth): the live contrast readout is genuine client-side JS with a DUPLICATED (never imported) ratio calculation**, covered by a mandatory PHP<->JS parity test. Without JS, every row still renders its correct, server-computed contrast state (from the same PHP function) and still saves correctly — "accurate but not live," never "broken."
- **Ruling (design spec §5.1, fifth): the AA-override checkbox's save-time behavior is per-occasion and symmetric** (record when failing+checked, remove when passing or unchecked), **plus orphan cleanup** for any `occasion:*`-scoped acknowledgement whose id is no longer present in the saved map.
- Out of scope: Phase 2.2 (deploy drift, Site Health fields, `wp blueline settings occasions`), and any admin-facing surface beyond the Occasions tab itself.
- No new `inc/` file is added by this plan — everything lives in the already-required `inc/occasions.php` and `inc/settings/page.php` — so no `functions.php` change is needed anywhere in this plan.
- **Read the actual current line numbers/text yourself** before editing `inc/settings/page.php` in any task — this plan's own `old_string` anchors were captured directly from the live file during research, but confirm they still match before applying an edit.

---

### Task 1: The id-derivation and de-duplication layer

**Files:**
- Modify: `tests/bootstrap.php` (add a `sanitize_title()` stub, guarded by `function_exists()`)
- Modify: `inc/occasions.php` (add `blueline_occasions_assign_unique_ids()`)
- Test: `tests/OccasionsTest.php` (add test methods)

**Interfaces:**
- Consumes: nothing from a later task. Consumes `sanitize_title( string $title ): string` (WP core in production; this task's own new bootstrap stub in tests).
- Produces: `blueline_occasions_assign_unique_ids( $submitted, array $stored ): array` (`inc/occasions.php`) — Task 2 calls this. Contract: `$submitted` is a map of per-request row key => raw row array, where each row MAY carry `_original_id` (string, `''` for a brand-new row, or the row's existing occasion id when editing one) and `label` (string). Every OTHER key on a row (e.g. a later task's `override_aa`) is copied through UNCHANGED into the result — this function only ever reads `_original_id` and `label`, and only ever writes `id` and removes `_original_id`. Returns a NEW map keyed by each row's final, unique, derived id, with that id also written into the row's own `id` field.

- [ ] **Step 1: Write the failing test**

  Add to `tests/OccasionsTest.php` (inside the existing `OccasionsTest` class, after the last preset test):

  ```php
  	/* ------------------------------------------------ assign_unique_ids */

  	/**
  	 * Asserts a brand-new row (empty `_original_id`) derives its `id`
  	 * from `sanitize_title( $label )`.
  	 */
  	public function test_assign_unique_ids_derives_a_slug_from_the_label(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  			),
  			array()
  		);

  		$this->assertArrayHasKey( 'canada-day', $result );
  		$this->assertSame( 'canada-day', $result['canada-day']['id'] );
  	}

  	/**
  	 * Asserts `_original_id` is stripped from the returned row -- it is
  	 * request-scoped bookkeeping this function consumes, not part of the
  	 * Occasion shape blueline_sanitize_occasions() expects.
  	 */
  	public function test_assign_unique_ids_strips_original_id(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  			),
  			array()
  		);

  		$this->assertArrayNotHasKey( '_original_id', $result['canada-day'] );
  	}

  	/**
  	 * Asserts two new rows submitted with the same label in one batch are
  	 * de-duplicated against EACH OTHER with an incrementing numeric
  	 * suffix -- design spec §5.1's first ruling.
  	 */
  	public function test_assign_unique_ids_dedupes_within_the_same_batch(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  				'row-2' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  			),
  			array()
  		);

  		$this->assertArrayHasKey( 'canada-day', $result );
  		$this->assertArrayHasKey( 'canada-day-2', $result );
  		$this->assertSame( 'canada-day', $result['canada-day']['id'] );
  		$this->assertSame( 'canada-day-2', $result['canada-day-2']['id'] );
  	}

  	/**
  	 * Asserts a new row whose derived slug collides with a DIFFERENT
  	 * currently-stored occasion is de-duplicated against the stored array
  	 * too, not only against the rest of this batch.
  	 */
  	public function test_assign_unique_ids_dedupes_against_a_different_stored_occasion(): void {
  		$stored = array(
  			'canada-day' => array( 'id' => 'canada-day', 'label' => 'Canada Day' ),
  		);

  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  			),
  			$stored
  		);

  		$this->assertArrayHasKey( 'canada-day-2', $result );
  		$this->assertArrayNotHasKey( 'canada-day', $result );
  	}

  	/**
  	 * Asserts a row EDITING an existing occasion, whose label is
  	 * unchanged (so its derived slug is unchanged), keeps its own
  	 * existing id rather than being treated as a collision against
  	 * itself.
  	 */
  	public function test_assign_unique_ids_lets_a_row_keep_its_own_unchanged_id(): void {
  		$stored = array(
  			'canada-day' => array( 'id' => 'canada-day', 'label' => 'Canada Day' ),
  		);

  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'canada-day' => array( '_original_id' => 'canada-day', 'label' => 'Canada Day' ),
  			),
  			$stored
  		);

  		$this->assertArrayHasKey( 'canada-day', $result );
  		$this->assertCount( 1, $result );
  	}

  	/**
  	 * Asserts editing an existing occasion's LABEL enough to change its
  	 * derived slug is treated as a rename: the new slug is used, and the
  	 * old key does not reappear in the result (the caller's own
  	 * submission IS the whole new map, so an old key simply not being
  	 * present in the result is what "removed" means here).
  	 */
  	public function test_assign_unique_ids_treats_a_changed_label_as_a_rename(): void {
  		$stored = array(
  			'canada-day' => array( 'id' => 'canada-day', 'label' => 'Canada Day' ),
  		);

  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'canada-day' => array( '_original_id' => 'canada-day', 'label' => 'Canada Day Long Weekend' ),
  			),
  			$stored
  		);

  		$this->assertArrayHasKey( 'canada-day-long-weekend', $result );
  		$this->assertArrayNotHasKey( 'canada-day', $result );
  	}

  	/**
  	 * Asserts a rename that collides with a DIFFERENT stored occasion is
  	 * de-duplicated rather than silently overwriting it.
  	 */
  	public function test_assign_unique_ids_a_rename_that_collides_is_deduped(): void {
  		$stored = array(
  			'canada-day' => array( 'id' => 'canada-day', 'label' => 'Canada Day' ),
  			'christmas'  => array( 'id' => 'christmas', 'label' => 'Christmas' ),
  		);

  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'canada-day' => array( '_original_id' => 'canada-day', 'label' => 'Christmas' ),
  			),
  			$stored
  		);

  		$this->assertArrayHasKey( 'christmas-2', $result );
  		$this->assertArrayNotHasKey( 'canada-day', $result );
  	}

  	/**
  	 * Asserts a row with no usable label (empty, or only whitespace)
  	 * derives no id and is dropped outright -- blueline_sanitize_occasions()
  	 * would reject it for the same reason anyway, so there is no id worth
  	 * manufacturing for it.
  	 */
  	public function test_assign_unique_ids_drops_a_row_with_no_usable_label(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => '' ),
  				'row-2' => array( '_original_id' => '', 'label' => '   ' ),
  			),
  			array()
  		);

  		$this->assertSame( array(), $result );
  	}

  	/**
  	 * Asserts a non-array $submitted value sanitizes to an empty map,
  	 * matching blueline_sanitize_occasions()'s own defensive posture for
  	 * the same shape of bad input.
  	 */
  	public function test_assign_unique_ids_non_array_value_returns_empty(): void {
  		foreach ( array( null, 'not-an-array', 42, false ) as $bad ) {
  			$this->assertSame( array(), blueline_occasions_assign_unique_ids( $bad, array() ) );
  		}
  	}

  	/**
  	 * Asserts a non-array ROW (not a whole submission) is skipped rather
  	 * than fataling the rest of the batch.
  	 */
  	public function test_assign_unique_ids_skips_a_non_array_row(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => 'not-an-array',
  				'row-2' => array( '_original_id' => '', 'label' => 'Canada Day' ),
  			),
  			array()
  		);

  		$this->assertSame( array( 'canada-day' ), array_keys( $result ) );
  	}

  	/**
  	 * Asserts every OTHER key on a row (e.g. a future override checkbox
  	 * field) is copied through unchanged -- this function only ever
  	 * reads `_original_id`/`label` and writes `id`.
  	 */
  	public function test_assign_unique_ids_copies_other_keys_through_unchanged(): void {
  		$result = blueline_occasions_assign_unique_ids(
  			array(
  				'row-1' => array( '_original_id' => '', 'label' => 'Canada Day', 'motif' => 'maple-leaf', 'override_aa' => '1' ),
  			),
  			array()
  		);

  		$this->assertSame( 'maple-leaf', $result['canada-day']['motif'] );
  		$this->assertSame( '1', $result['canada-day']['override_aa'] );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_occasions_assign_unique_ids()".

- [ ] **Step 3: Write minimal implementation**

  Modify `tests/bootstrap.php`: insert this new stub between the existing `sanitize_html_class` stub and the existing `absint` stub (i.e. immediately after the `sanitize_html_class` stub's closing `}`, before `if ( ! function_exists( 'absint' ) ) {`):

  ```php
  if ( ! function_exists( 'sanitize_title' ) ) {
  	/**
  	 * Minimal stand-in for WordPress' sanitize_title() -- lowercases,
  	 * collapses any run of non alphanumeric characters to a single
  	 * hyphen, and trims leading/trailing hyphens. Not a faithful port of
  	 * core's accent-stripping remove_accents() behaviour, but every label
  	 * this suite ever feeds it is plain ASCII, so that gap is never
  	 * exercised.
  	 *
  	 * @param string $title Raw text.
  	 * @return string
  	 */
  	function sanitize_title( $title ) {
  		$title = strtolower( trim( (string) $title ) );
  		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
  		return trim( (string) $title, '-' );
  	}
  }
  ```

  Modify `inc/occasions.php`: append this immediately after `blueline_occasion_presets()`'s closing `}` (before `blueline_occasion_today_md()`'s docblock):

  ```php
  /**
   * Assign each submitted occasions row a server-derived, de-duplicated
   * `id` -- design spec §5.1's first ruling: the admin never types an id
   * directly.
   *
   * A row keeps its own existing id when its derived slug is unchanged
   * from `_original_id`. A row whose derived slug differs from
   * `_original_id` (a brand-new row, where `_original_id` is '', or an
   * existing row whose label edit changed the derived slug -- a rename)
   * is checked for a collision against both the rest of THIS batch and
   * the currently-stored array, EXCLUDING the row's own original slot,
   * and bumped with an incrementing numeric suffix on collision -- the
   * same shape wp_unique_post_slug() already uses for post slugs.
   *
   * Deliberately does not call blueline_sanitize_occasions() itself, and
   * does not validate anything beyond having a usable label: the caller
   * (inc/settings/page.php's sanitize-callback carve-out) runs the
   * result through that unchanged validator immediately afterwards. Any
   * OTHER key a row carries (e.g. a save-time override checkbox a later
   * task reads) is copied through untouched -- this function only ever
   * reads `_original_id`/`label` and writes `id`.
   *
   * @param mixed                                $submitted Raw submitted rows, keyed by an opaque per-request row identifier.
   * @param array<string, array<string, mixed>>   $stored    Currently stored `occasions` map, read BEFORE this save.
   * @return array<string, array<string, mixed>> The same rows, re-keyed by their final, unique, derived id.
   */
  function blueline_occasions_assign_unique_ids( $submitted, array $stored ): array {
  	if ( ! is_array( $submitted ) ) {
  		return array();
  	}

  	$taken  = array();
  	$result = array();

  	foreach ( $submitted as $row ) {
  		if ( ! is_array( $row ) ) {
  			continue;
  		}

  		$original_id = is_string( $row['_original_id'] ?? null ) ? $row['_original_id'] : '';
  		$label       = is_string( $row['label'] ?? null ) ? $row['label'] : '';
  		$base        = sanitize_title( $label );

  		if ( '' === $base ) {
  			// No usable label at all -- blueline_sanitize_occasions()
  			// will reject this row for its own empty-label reason
  			// regardless, so there is no id worth manufacturing for it.
  			continue;
  		}

  		$final_id = $base;
  		$suffix   = 2;

  		while (
  			isset( $taken[ $final_id ] )
  			|| ( isset( $stored[ $final_id ] ) && $final_id !== $original_id )
  		) {
  			$final_id = $base . '-' . $suffix;
  			++$suffix;
  		}

  		$taken[ $final_id ] = true;

  		$row['id'] = $final_id;
  		unset( $row['_original_id'] );

  		$result[ $final_id ] = $row;
  	}

  	return $result;
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsTest` — Expected: PASS.

- [ ] **Step 5: Commit**
  ```bash
  git add tests/bootstrap.php inc/occasions.php tests/OccasionsTest.php
  git commit -m "Add the occasions id-derivation and de-duplication layer"
  ```

---

### Task 2: The sanitize-callback carve-out for the Occasions tab's own save

**Files:**
- Modify: `inc/settings/page.php` (the reserved-key branch of `blueline_settings_sanitize_callback()`)
- Test: `tests/SettingsPageTest.php` (add test methods)

**Interfaces:**
- Consumes: `blueline_occasions_assign_unique_ids( $submitted, array $stored ): array` (Task 1), `blueline_sanitize_occasions( $value ): array` (2.1a, unchanged).
- Produces: `blueline_settings_sanitize_callback()`'s new behavior — when a submission carries `_tab === 'occasions'`, its `occasions` key survives (instead of being dropped like every other reserved key on a tab-scoped submission), is run through `blueline_occasions_assign_unique_ids()` THEN `blueline_sanitize_occasions()`, and lands in `$output['occasions']`. A submission with any OTHER `_tab` value still drops `occasions` outright, unchanged from 2.1a. A programmatic write (no `_tab` at all) still calls `blueline_sanitize_occasions()` directly with NO id derivation, unchanged from 2.1a. Task 3 modifies the inner body of the `'occasions' === $submitted_tab` branch this task creates, so its exact shape matters: it must end with a single statement assigning `$output[ $key ]` from `blueline_sanitize_occasions( $with_ids )`, immediately followed by `continue;`, immediately followed by `} else {` opening the programmatic-write branch — Task 3 replaces exactly that assignment line with a longer block.

- [ ] **Step 1: Write the failing test**

  Add to `tests/SettingsPageTest.php` (inside the existing `SettingsPageTest` class, near its existing `test_sanitize_callback_lets_occasions_survive_a_programmatic_write()`/`test_sanitize_callback_drops_occasions_from_a_form_submission()` methods from 2.1a):

  ```php
  	/**
  	 * The Occasions tab's own submission derives an id from the label
  	 * rather than trusting one the admin typed -- design spec §5.1's
  	 * first ruling, exercised through the real save path.
  	 */
  	public function test_sanitize_callback_derives_an_id_for_a_new_occasions_row(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'      => 'occasions',
  				'occasions' => array(
  					'row-1' => array(
  						'_original_id' => '',
  						'label'        => 'Canada Day',
  						'type'         => 'decorative',
  						'window'       => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent'       => '',
  						'motif'        => 'maple-leaf',
  						'line'         => '',
  						'mode'         => 'auto',
  					),
  				),
  			)
  		);

  		$this->assertArrayHasKey( 'canada-day', $output['occasions'] );
  		$this->assertSame( 'canada-day', $output['occasions']['canada-day']['id'] );
  	}

  	/**
  	 * Asserts `occasions` submitted from a DIFFERENT tab is still dropped
  	 * outright -- the new exception names `occasions` AND
  	 * `'occasions' === $submitted_tab` together, never `occasions` alone.
  	 */
  	public function test_sanitize_callback_still_drops_occasions_from_a_foreign_tab(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'      => 'content',
  				'occasions' => array( 'canada-day' => array( 'anything' => true ) ),
  			)
  		);

  		$this->assertArrayNotHasKey( 'occasions', $output );
  	}

  	/**
  	 * Asserts the pre-existing programmatic write path (no `_tab` at
  	 * all) is unaffected: a row's `id` is honoured exactly as submitted,
  	 * with NO derivation step run over it. 2.1a's own
  	 * test_sanitize_callback_lets_occasions_survive_a_programmatic_write()
  	 * already covers the happy path; this covers that derivation is
  	 * SKIPPED for this path specifically.
  	 */
  	public function test_sanitize_callback_does_not_re_derive_ids_on_a_programmatic_write(): void {
  		$entry = array(
  			'id'     => 'custom-slug',
  			'label'  => 'Something Else Entirely',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  			'accent' => '',
  			'motif'  => 'none',
  			'line'   => '',
  			'mode'   => 'auto',
  		);

  		$output = blueline_settings_sanitize_callback(
  			array( 'occasions' => array( 'custom-slug' => $entry ) )
  		);

  		$this->assertSame( $entry, $output['occasions']['custom-slug'] );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsPageTest` — Expected: FAIL on `test_sanitize_callback_derives_an_id_for_a_new_occasions_row()`, with `$output['occasions']` not set at all (an undefined array key), because the pre-existing unconditional "drop any reserved key when `_tab` is non-empty" check still fires for `occasions` too.

- [ ] **Step 3: Write minimal implementation**

  In `inc/settings/page.php`, locate this exact block inside `blueline_settings_sanitize_callback()` (immediately after the `if ( ! in_array( $key, BLUELINE_SETTINGS_RESERVED_KEYS, true ) ) { ... }` check):

  ```php
  			if ( '' !== $submitted_tab ) {
  				// Reserved, but this submission carries `_tab` -- it came
  				// from this file's own rendered form, which never
  				// legitimately submits a reserved key. Dropped, not
  				// honoured, rather than trusted just because it's on the
  				// allow-list.
  				continue;
  			}

  			if ( 'aa_acknowledgements' === $key ) {
  				// Not an integer like every other reserved key today -- a map
  				// of acknowledgement entries (inc/settings/acknowledgements.php).
  				// Its own validator drops anything malformed rather than
  				// corrupting the option or crashing a later reader.
  				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
  				continue;
  			}

  			if ( 'occasions' === $key ) {
  				// A map, not an integer like every other reserved key --
  				// its own validator (inc/occasions.php) drops anything
  				// malformed rather than corrupting the option or crashing a
  				// later reader.
  				$output[ $key ] = blueline_sanitize_occasions( $value );
  				continue;
  			}
  ```

  Replace it with:

  ```php
  			if ( '' !== $submitted_tab && ! ( 'occasions' === $key && 'occasions' === $submitted_tab ) ) {
  				// Reserved, but this submission carries `_tab` -- it came
  				// from this file's own rendered form, which never
  				// legitimately submits a reserved key... EXCEPT
  				// `occasions` submitted BY its own Occasions tab (design
  				// spec §5.1's second ruling): that tab's own form posts
  				// `blueline_settings[occasions]` as one opaque map value,
  				// never through `_posted_fields` per-field carry-forward,
  				// since `occasions` is not a scalar schema field at all.
  				// `_schema` and `aa_acknowledgements` keep the absolute
  				// rule unchanged -- this exception names `occasions` AND
  				// `'occasions' === $submitted_tab` together, rather than
  				// loosening the rule for every reserved key.
  				continue;
  			}

  			if ( 'aa_acknowledgements' === $key ) {
  				// Not an integer like every other reserved key today -- a map
  				// of acknowledgement entries (inc/settings/acknowledgements.php).
  				// Its own validator drops anything malformed rather than
  				// corrupting the option or crashing a later reader.
  				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
  				continue;
  			}

  			if ( 'occasions' === $key ) {
  				if ( 'occasions' === $submitted_tab ) {
  					// The Occasions tab's own save (design spec §5.1's
  					// first ruling): derive and de-duplicate every row's
  					// id server-side BEFORE the unchanged
  					// blueline_sanitize_occasions() ever sees it -- the
  					// admin never types an id directly.
  					$stored_occasions = is_array( $current['occasions'] ?? null ) ? $current['occasions'] : array();
  					$with_ids         = blueline_occasions_assign_unique_ids( $value, $stored_occasions );

  					$output[ $key ] = blueline_sanitize_occasions( $with_ids );
  				} else {
  					// A programmatic write (WP-CLI, a direct update_option()
  					// call, an import) -- no id derivation: the caller is
  					// expected to already supply final, correctly-keyed
  					// ids, exactly as this branch behaved before 2.1b.
  					$output[ $key ] = blueline_sanitize_occasions( $value );
  				}
  				continue;
  			}
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsPageTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS. This branch sits on `blueline_settings_sanitize_callback()`, a choke point every settings save passes through — confirm nothing else that reaches it regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/settings/page.php tests/SettingsPageTest.php
  git commit -m "Add the Occasions tab's sanitize-callback carve-out"
  ```

---

### Task 3: The AA-override save-time lifecycle

**Files:**
- Modify: `inc/occasions.php` (add `blueline_occasions_apply_aa_overrides()`)
- Modify: `inc/settings/page.php` (extend the `'occasions' === $submitted_tab` branch Task 2 created)
- Test: `tests/OccasionsTest.php` (add test methods for the pure function)
- Test: `tests/SettingsPageTest.php` (add integration test methods)

**Interfaces:**
- Consumes: Task 2's exact branch shape (the `if ( 'occasions' === $submitted_tab ) { ... } else {` block, whose body currently ends with `$output[ $key ] = blueline_sanitize_occasions( $with_ids );`), `blueline_record_acknowledgement( array $acknowledgements, string $scope, string $rule_id, string $value, float $ratio, string $inputs_hash, int $user_id ): array`, `blueline_remove_acknowledgement( array $acknowledgements, string $scope ): array`, `blueline_stored_acknowledgements(): array`, `blueline_settings_inputs_hash(): string` (all pre-existing, `inc/settings/acknowledgements.php` and `inc/settings/validation.php`), `blueline_sanitize_hex_color( $value ): string`, `blueline_contrast_ratio( string $a, string $b ): float`, `blueline_contrast_threshold( string $which ): float`, `BLUELINE_TOKEN_INK` (pre-existing, `inc/team-colors.php`), `blueline_occasion_accent_default(): string` (pre-existing, `inc/occasions.php`), `get_current_user_id(): int` (WP core / test stub).
- Produces: `blueline_occasions_apply_aa_overrides( array $sanitized, array $raw_overrides, array $acknowledgements, string $inputs_hash, int $user_id ): array` (`inc/occasions.php`) — a PURE function (never calls `update_option()` itself, matching `blueline_record_acknowledgement()`/`blueline_remove_acknowledgement()`'s own contract). `$raw_overrides` is a map of final occasion id => bool (was that row's `override_aa` checkbox checked in this submission). The rule id used throughout is the exact literal string `'ink-on-occasion-accent'` — the same one `blueline_resolve_active_occasion()` already checks against (`inc/occasions.php`, 2.1a) — so an acknowledgement this function records is one the resolver can actually honour. After this task, `blueline_settings_sanitize_callback()` also sets `$output['aa_acknowledgements']` whenever `$submitted_tab === 'occasions'`. Task 4 (the render function) and this task must agree on the exact per-row field name `override_aa` (i.e. the row array carries a key literally named `override_aa`, truthy for "checked") — Task 4's own Interfaces block restates this same contract.

- [ ] **Step 1: Write the failing test**

  Add to `tests/OccasionsTest.php` (inside the existing `OccasionsTest` class, after the `assign_unique_ids` tests):

  ```php
  	/* ------------------------------------------------ apply_aa_overrides */

  	/**
  	 * A minimal, valid, force_on occasion whose accent FAILS contrast
  	 * against BLUELINE_TOKEN_INK -- reuses the exact fixture value
  	 * tests/OccasionsResolverTest.php's own fail-closed/fail-open pair
  	 * already established as failing.
  	 *
  	 * @param array<string, mixed> $overrides Keys to override.
  	 * @return array<string, mixed>
  	 */
  	private function failing_occasion( array $overrides = array() ): array {
  		return array_merge(
  			array(
  				'id'     => 'failing',
  				'label'  => 'Failing',
  				'type'   => 'decorative',
  				'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  				'accent' => '#274a63',
  				'motif'  => 'none',
  				'line'   => '',
  				'mode'   => 'force_on',
  			),
  			$overrides
  		);
  	}

  	/**
  	 * Asserts a failing accent WITH its override checkbox checked
  	 * records a new acknowledgement scoped `occasion:{id}`.
  	 */
  	public function test_apply_aa_overrides_records_a_checked_failing_row(): void {
  		$hash = 'test-hash';

  		$result = blueline_occasions_apply_aa_overrides(
  			array( 'failing' => $this->failing_occasion() ),
  			array( 'failing' => true ),
  			array(),
  			$hash,
  			7
  		);

  		$this->assertArrayHasKey( 'occasion:failing', $result );
  		$this->assertSame( 'ink-on-occasion-accent', $result['occasion:failing']['rule_id'] );
  		$this->assertSame( '#274a63', $result['occasion:failing']['value'] );
  		$this->assertSame( $hash, $result['occasion:failing']['inputs_hash'] );
  		$this->assertSame( 7, $result['occasion:failing']['user_id'] );
  	}

  	/**
  	 * Asserts a failing accent whose checkbox is NOT checked removes any
  	 * existing acknowledgement for that scope rather than leaving it --
  	 * design spec §5.1's fifth ruling: symmetric, not additive-only.
  	 */
  	public function test_apply_aa_overrides_removes_when_the_checkbox_is_unchecked(): void {
  		$existing = blueline_record_acknowledgement( array(), 'occasion:failing', 'ink-on-occasion-accent', '#274a63', 1.66, 'stale-hash', 1 );

  		$result = blueline_occasions_apply_aa_overrides(
  			array( 'failing' => $this->failing_occasion() ),
  			array( 'failing' => false ),
  			$existing,
  			'test-hash',
  			7
  		);

  		$this->assertArrayNotHasKey( 'occasion:failing', $result );
  	}

  	/**
  	 * Asserts an occasion whose accent now PASSES contrast has its
  	 * acknowledgement removed even if the checkbox happens to still be
  	 * checked in the submission -- nothing left to acknowledge.
  	 */
  	public function test_apply_aa_overrides_removes_when_the_accent_now_passes(): void {
  		$existing = blueline_record_acknowledgement( array(), 'occasion:passing', 'ink-on-occasion-accent', '#274a63', 1.66, 'stale-hash', 1 );

  		$passing = $this->failing_occasion( array( 'id' => 'passing', 'accent' => '#ffffff' ) );

  		$result = blueline_occasions_apply_aa_overrides(
  			array( 'passing' => $passing ),
  			array( 'passing' => true ),
  			$existing,
  			'test-hash',
  			7
  		);

  		$this->assertArrayNotHasKey( 'occasion:passing', $result );
  	}

  	/**
  	 * Asserts an acknowledgement scoped to an occasion id that is no
  	 * longer present in $sanitized AT ALL (deleted, or renamed away
  	 * from) is removed -- orphan cleanup, design spec §5.1's fifth
  	 * ruling.
  	 */
  	public function test_apply_aa_overrides_cleans_up_an_orphaned_acknowledgement(): void {
  		$existing = blueline_record_acknowledgement( array(), 'occasion:deleted-one', 'ink-on-occasion-accent', '#274a63', 1.66, 'test-hash', 1 );

  		$result = blueline_occasions_apply_aa_overrides(
  			array(), // Nothing submitted this save -- the occasion is gone.
  			array(),
  			$existing,
  			'test-hash',
  			7
  		);

  		$this->assertArrayNotHasKey( 'occasion:deleted-one', $result );
  	}

  	/**
  	 * Asserts a live acknowledgement for a scope this mechanism does not
  	 * own (does not start with `occasion:`) is left completely alone --
  	 * orphan cleanup is scoped narrowly to this mechanism's own prefix.
  	 */
  	public function test_apply_aa_overrides_leaves_a_foreign_scope_alone(): void {
  		$existing = blueline_record_acknowledgement( array(), 'something-else:entirely', 'some-other-rule', '#274a63', 1.66, 'test-hash', 1 );

  		$result = blueline_occasions_apply_aa_overrides( array(), array(), $existing, 'test-hash', 7 );

  		$this->assertArrayHasKey( 'something-else:entirely', $result );
  	}

  	/**
  	 * Asserts an occasion with an EMPTY `accent` (use the resolved
  	 * default) is evaluated against that resolved default, not against
  	 * an empty string.
  	 */
  	public function test_apply_aa_overrides_resolves_an_empty_accent_to_the_default(): void {
  		$occasion = $this->failing_occasion( array( 'id' => 'default-accent', 'accent' => '' ) );

  		$result = blueline_occasions_apply_aa_overrides(
  			array( 'default-accent' => $occasion ),
  			array( 'default-accent' => true ),
  			array(),
  			'test-hash',
  			7
  		);

  		// The real stylesheet default (--bl-ice, '#74c0e1') passes contrast
  		// outright, so nothing should have been recorded for it.
  		$this->assertArrayNotHasKey( 'occasion:default-accent', $result );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_occasions_apply_aa_overrides()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php` (immediately after `blueline_occasions_assign_unique_ids()`):

  ```php
  /**
   * Compute the new `aa_acknowledgements` map after an Occasions-tab save
   * -- design spec §5.1's fifth ruling: per-occasion, symmetric
   * record/remove, plus orphan cleanup.
   *
   * For every occasion in $sanitized (already run through
   * blueline_sanitize_occasions() -- this function trusts it is
   * well-formed), resolves its effective accent (its own `accent`, or
   * blueline_occasion_accent_default() when empty), checks contrast
   * against BLUELINE_TOKEN_INK, and either records or removes its
   * acknowledgement accordingly. Then removes every acknowledgement
   * scoped `occasion:*` whose id is not present in $sanitized at all --
   * deleting or renaming an occasion must not leave its acknowledgement
   * behind forever.
   *
   * Pure: takes the current map and returns a new one; never calls
   * update_option() itself, matching blueline_record_acknowledgement()/
   * blueline_remove_acknowledgement()'s own contract.
   *
   * @param array<string, array<string, mixed>> $sanitized        This save's new `occasions` value (post blueline_sanitize_occasions()).
   * @param array<string, bool>                  $raw_overrides    Map of occasion id => whether ITS override checkbox was checked in this submission.
   * @param array<string, array<string, mixed>>  $acknowledgements Currently stored `aa_acknowledgements`.
   * @param string                               $inputs_hash      blueline_settings_inputs_hash()'s current value.
   * @param int                                   $user_id          The saving user's id.
   * @return array<string, array<string, mixed>> The updated map, to be stored under `aa_acknowledgements`.
   */
  function blueline_occasions_apply_aa_overrides(
  	array $sanitized,
  	array $raw_overrides,
  	array $acknowledgements,
  	string $inputs_hash,
  	int $user_id
  ): array {
  	foreach ( $sanitized as $id => $occasion ) {
  		$scope = 'occasion:' . $id;

  		$raw_accent = '' !== ( $occasion['accent'] ?? '' )
  			? $occasion['accent']
  			: blueline_occasion_accent_default();

  		$accent = blueline_sanitize_hex_color( $raw_accent );

  		if ( '' === $accent ) {
  			// Unresolvable accent -- nothing to acknowledge either way;
  			// do not leave a stale acknowledgement behind for a value
  			// that no longer means anything.
  			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
  			continue;
  		}

  		$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $accent );
  		$passes = $ratio >= blueline_contrast_threshold( 'body' );

  		if ( ! $passes && ! empty( $raw_overrides[ $id ] ) ) {
  			$acknowledgements = blueline_record_acknowledgement(
  				$acknowledgements,
  				$scope,
  				'ink-on-occasion-accent',
  				$accent,
  				$ratio,
  				$inputs_hash,
  				$user_id
  			);
  		} else {
  			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
  		}
  	}

  	// Orphan cleanup: an acknowledgement scoped to an occasion id no
  	// longer present in this save's own occasions map at all (deleted,
  	// or renamed away from) has nothing left to cover.
  	foreach ( array_keys( $acknowledgements ) as $scope ) {
  		if ( 0 !== strpos( $scope, 'occasion:' ) ) {
  			continue; // Not this mechanism's business -- e.g. a future non-occasion scope.
  		}

  		$id = substr( $scope, strlen( 'occasion:' ) );

  		if ( ! isset( $sanitized[ $id ] ) ) {
  			$acknowledgements = blueline_remove_acknowledgement( $acknowledgements, $scope );
  		}
  	}

  	return $acknowledgements;
  }
  ```

  In `inc/settings/page.php`, inside the `'occasions' === $submitted_tab` branch Task 2 added, replace:

  ```php
  					$output[ $key ] = blueline_sanitize_occasions( $with_ids );
  				} else {
  ```

  with:

  ```php
  					// Per-row override checkboxes ride along inside
  					// $with_ids (blueline_occasions_assign_unique_ids()
  					// copies every OTHER key of a row through untouched)
  					// -- read them here, keyed by each row's own FINAL
  					// id, before blueline_sanitize_occasions() strips the
  					// extra `override_aa` key off (it only ever keeps
  					// the eight documented Occasion keys).
  					$raw_overrides = array();
  					foreach ( $with_ids as $row_id => $row ) {
  						$raw_overrides[ $row_id ] = is_array( $row ) && ! empty( $row['override_aa'] );
  					}

  					$sanitized_occasions = blueline_sanitize_occasions( $with_ids );

  					$output[ $key ] = $sanitized_occasions;

  					// design spec §5.1's fifth ruling: the Occasions
  					// tab's own save is also what decides this save's
  					// new `aa_acknowledgements` value -- per-occasion,
  					// symmetric record/remove, plus orphan cleanup. This
  					// key is never present in $input for this
  					// submission (the form never renders a field named
  					// it), so nothing else in this loop will ever
  					// overwrite it.
  					$output['aa_acknowledgements'] = blueline_occasions_apply_aa_overrides(
  						$sanitized_occasions,
  						$raw_overrides,
  						blueline_stored_acknowledgements(),
  						blueline_settings_inputs_hash(),
  						get_current_user_id()
  					);
  				} else {
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsTest` — Expected: PASS.

  Then add to `tests/SettingsPageTest.php` (integration, through the real save path):

  ```php
  	/**
  	 * End-to-end: the Occasions tab's own submission (a failing accent,
  	 * override checkbox checked) reaches
  	 * blueline_settings_sanitize_callback() and produces BOTH a
  	 * sanitized `occasions` entry AND a new, matching
  	 * `aa_acknowledgements` entry -- design spec §5.1's fifth ruling,
  	 * run through the real save path rather than the pure function
  	 * alone.
  	 */
  	public function test_sanitize_callback_records_an_acknowledgement_for_an_occasions_tab_save(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'      => 'occasions',
  				'occasions' => array(
  					'failing' => array(
  						'_original_id' => '',
  						'label'        => 'Failing',
  						'type'         => 'decorative',
  						'window'       => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  						'accent'       => '#274a63',
  						'motif'        => 'none',
  						'line'         => '',
  						'mode'         => 'force_on',
  						'override_aa'  => '1',
  					),
  				),
  			)
  		);

  		$this->assertArrayHasKey( 'failing', $output['occasions'] );
  		$this->assertArrayHasKey( 'occasion:failing', $output['aa_acknowledgements'] );
  		$this->assertSame( '#274a63', $output['aa_acknowledgements']['occasion:failing']['value'] );
  	}

  	/**
  	 * Asserts deleting an occasion (simply omitting it from this save's
  	 * own submission) also removes its now-orphaned acknowledgement, in
  	 * the same real save path.
  	 */
  	public function test_sanitize_callback_cleans_up_an_orphaned_acknowledgement_on_save(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(
  					'failing' => array(
  						'id'     => 'failing',
  						'label'  => 'Failing',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  						'accent' => '#274a63',
  						'motif'  => 'none',
  						'line'   => '',
  						'mode'   => 'force_on',
  					),
  				),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:failing', 'ink-on-occasion-accent', '#274a63', 1.66, blueline_settings_inputs_hash(), 1 ),
  			)
  		);

  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'      => 'occasions',
  				'occasions' => array(), // The occasion was removed in this save.
  			)
  		);

  		$this->assertSame( array(), $output['occasions'] );
  		$this->assertArrayNotHasKey( 'occasion:failing', $output['aa_acknowledgements'] );
  	}
  ```

  Run: `composer test -- --filter SettingsPageTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS. This branch sits on `blueline_settings_sanitize_callback()` again — confirm nothing regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/occasions.php inc/settings/page.php tests/OccasionsTest.php tests/SettingsPageTest.php
  git commit -m "Add the AA-override save-time lifecycle for occasions"
  ```

---

### Task 4: The Occasions tab's own render functions

**Files:**
- Modify: `inc/settings/page.php` (add `blueline_settings_render_occasions_tab()`, `blueline_settings_render_occasion_row()`, `blueline_occasion_type_label()`, `blueline_occasion_motif_label()`, `blueline_occasion_mode_label()`)
- Test: `tests/SettingsOccasionsTabTest.php` (new)

**Interfaces:**
- Consumes: `blueline_settings( 'occasions' ): array`, `BLUELINE_SETTINGS_OPTION` (pre-existing, `inc/settings/store.php`), `blueline_settings_inputs_hash(): string` (pre-existing, `inc/settings/validation.php`), `blueline_stored_acknowledgements(): array`, `blueline_acknowledgement_covers( array $acknowledgements, string $scope, string $rule_id, string $value, string $current_inputs_hash ): bool` (pre-existing, `inc/settings/acknowledgements.php`), `blueline_occasion_presets(): array`, `blueline_occasion_types(): array`, `blueline_occasion_motifs(): array`, `blueline_occasion_modes(): array`, `blueline_occasion_accent_default(): string` (pre-existing, `inc/occasions.php`), `blueline_sanitize_hex_color( $value ): string`, `blueline_contrast_ratio( string $a, string $b ): float`, `blueline_contrast_threshold( string $which ): float`, `BLUELINE_TOKEN_INK` (pre-existing, `inc/team-colors.php`), `sanitize_html_class( $value )` (WP core / test stub), `wp_json_encode()` (WP core / test stub, requires `tests/cli-stubs.php` in tests). This task does NOT depend on Task 2's or Task 3's code — it reads only already-shipped 2.1a primitives, and is fully testable by calling its two render functions directly.
- Produces: `blueline_settings_render_occasions_tab(): void` and `blueline_settings_render_occasion_row( string $name, string $row_key, array $occasion, string $inputs_hash, array $acknowledgements ): void` (both `inc/settings/page.php`) — Task 5 calls `blueline_settings_render_occasions_tab()` from the tab-routing dispatch branch. The per-row markup this task produces establishes the exact POST field-name contract every later task (and the browser) relies on: for a row whose base name is `$name . '[' . $row_key . ']'` (e.g. `blueline_settings[occasions][canada-day]`), the rendered fields are `[_original_id]`, `[label]`, `[type]`, `[window][start_md]`, `[window][end_md]`, `[accent]`, `[motif]`, `[line]`, `[mode]`, and `[override_aa]` (a checkbox, value `"1"` when checked) — Tasks 1-3 already assume exactly this shape. `$row_key` is the real occasion id for an existing row, and the literal string `__TEMPLATE__` for the `<template>` row Task 6's JS clones (Task 6 replaces `__TEMPLATE__` with a fresh client-generated placeholder on every clone).

- [ ] **Step 1: Write the failing test**

  Create `tests/SettingsOccasionsTabTest.php`:

  ```php
  <?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/sanitize.php';
  require_once __DIR__ . '/../inc/settings/links.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';
  require_once __DIR__ . '/../inc/settings/page.php';
  require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by the "add from preset" options.

  /**
   * Covers blueline_settings_render_occasions_tab() and
   * blueline_settings_render_occasion_row() (inc/settings/page.php):
   * design spec §5.1's third and fifth rulings' admin-facing half.
   * Follows tests/SettingsPageTest.php's own convention -- render into an
   * output buffer via the real render function, assert on the captured
   * HTML string directly.
   */
  final class SettingsOccasionsTabTest extends TestCase {

  	protected function setUp(): void {
  		blueline_test_reset();
  	}

  	/**
  	 * @param array<string, mixed> $overrides Keys to override.
  	 * @return array<string, mixed>
  	 */
  	private function occasion( array $overrides = array() ): array {
  		return array_merge(
  			array(
  				'id'     => 'canada-day',
  				'label'  => 'Canada Day',
  				'type'   => 'decorative',
  				'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  				'accent' => '',
  				'motif'  => 'maple-leaf',
  				'line'   => '',
  				'mode'   => 'auto',
  			),
  			$overrides
  		);
  	}

  	/**
  	 * Asserts the empty state renders when nothing is stored.
  	 */
  	public function test_renders_the_empty_state_when_nothing_is_stored(): void {
  		ob_start();
  		blueline_settings_render_occasions_tab();
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString( 'data-bl-occasions-empty', $html );
  		$this->assertStringContainsString( 'No occasions configured yet.', $html );
  	}

  	/**
  	 * Asserts a stored occasion renders its own row, with its stored
  	 * values actually reaching the input `value`/`selected` attributes
  	 * -- not merely that SOME row rendered.
  	 */
  	public function test_renders_one_row_per_stored_occasion_with_its_values(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->occasion() ) ) );

  		ob_start();
  		blueline_settings_render_occasions_tab();
  		$html = (string) ob_get_clean();

  		$this->assertStringNotContainsString( 'data-bl-occasions-empty', $html );
  		$this->assertStringContainsString( 'value="Canada Day"', $html );
  		$this->assertMatchesRegularExpression( '/value="maple-leaf"\s+selected="selected"/', $html );
  		$this->assertMatchesRegularExpression( '/value="decorative"\s+selected="selected"/', $html );
  		$this->assertMatchesRegularExpression( '/value="auto"\s+selected="selected"/', $html );
  		$this->assertStringContainsString( 'value="07-01"', $html );
  	}

  	/**
  	 * A real `<label for>` for the label field -- the accessibility
  	 * baseline every field in this repeater needs.
  	 */
  	public function test_the_label_field_has_a_real_label_association(): void {
  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $this->occasion(), 'test-hash', array() );
  		$html = (string) ob_get_clean();

  		$this->assertMatchesRegularExpression( '/for="bl-occasion-canada-day-label"[^>]*>Label/', $html );
  		$this->assertStringContainsString( 'id="bl-occasion-canada-day-label"', $html );
  	}

  	/**
  	 * A passing accent hides the AA-override notice section entirely.
  	 */
  	public function test_a_passing_accent_hides_the_aa_override_notice(): void {
  		$occasion = $this->occasion( array( 'accent' => '#ffffff' ) );

  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $occasion, 'test-hash', array() );
  		$html = (string) ob_get_clean();

  		$this->assertMatchesRegularExpression( '/data-bl-occasion-aa-notice\s+hidden/', $html );
  	}

  	/**
  	 * A failing accent shows the AA-override notice section, unhidden,
  	 * with its own explanatory copy and checkbox.
  	 */
  	public function test_a_failing_accent_shows_the_aa_override_notice(): void {
  		$occasion = $this->occasion( array( 'accent' => '#274a63' ) );

  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $occasion, 'test-hash', array() );
  		$html = (string) ob_get_clean();

  		$this->assertDoesNotMatchRegularExpression( '/data-bl-occasion-aa-notice\s+hidden/', $html );
  		$this->assertStringContainsString( 'does not meet the AA contrast requirement', $html );
  		$this->assertStringContainsString( 'name="blueline_settings[occasions][canada-day][override_aa]"', $html );
  	}

  	/**
  	 * A row failing contrast, WITH a live, matching acknowledgement on
  	 * record, renders its override checkbox pre-checked -- reopening the
  	 * panel must not look like the admin never acknowledged it.
  	 */
  	public function test_an_existing_acknowledgement_pre_checks_the_override_box(): void {
  		$occasion         = $this->occasion( array( 'accent' => '#274a63' ) );
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'test-hash', 1 );

  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $occasion, 'test-hash', $acknowledgements );
  		$html = (string) ob_get_clean();

  		$this->assertMatchesRegularExpression( '/name="blueline_settings\[occasions\]\[canada-day\]\[override_aa\]"[^>]*checked="checked"/', $html );
  	}

  	/**
  	 * A stale inputs hash on an otherwise-matching acknowledgement leaves
  	 * the checkbox UNchecked -- an admin must consciously re-acknowledge,
  	 * not have it silently appear already covered.
  	 */
  	public function test_a_stale_acknowledgement_does_not_pre_check_the_box(): void {
  		$occasion         = $this->occasion( array( 'accent' => '#274a63' ) );
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-different-hash', 1 );

  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $occasion, 'current-hash', $acknowledgements );
  		$html = (string) ob_get_clean();

  		$this->assertDoesNotMatchRegularExpression( '/name="blueline_settings\[occasions\]\[canada-day\]\[override_aa\]"[^>]*checked="checked"/', $html );
  	}

  	/**
  	 * The "add from preset" select lists exactly the four documented
  	 * presets (design spec §5's second ruling: presets are a read-only
  	 * catalog this tab reads from, never pre-populated live).
  	 */
  	public function test_the_preset_select_lists_the_four_documented_presets(): void {
  		ob_start();
  		blueline_settings_render_occasions_tab();
  		$html = (string) ob_get_clean();

  		foreach ( array( 'canada-day', 'remembrance-day', 'christmas', 'new-year' ) as $preset_id ) {
  			$this->assertStringContainsString( 'value="' . $preset_id . '"', $html );
  		}
  	}

  	/**
  	 * Exactly one `<template>` element, holding a row keyed by the
  	 * `__TEMPLATE__` placeholder Task 6's JS replaces on clone.
  	 */
  	public function test_the_template_element_exists_exactly_once_and_uses_the_placeholder_key(): void {
  		ob_start();
  		blueline_settings_render_occasions_tab();
  		$html = (string) ob_get_clean();

  		$this->assertSame( 1, substr_count( $html, '<template' ) );
  		$this->assertStringContainsString( 'blueline_settings[occasions][__TEMPLATE__][label]', $html );
  	}

  	/**
  	 * The `_original_id` hidden field carries the row's own existing id
  	 * -- Task 2's blueline_occasions_assign_unique_ids() reads exactly
  	 * this field.
  	 */
  	public function test_the_original_id_hidden_field_carries_the_existing_id(): void {
  		ob_start();
  		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $this->occasion(), 'test-hash', array() );
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString(
  			'name="blueline_settings[occasions][canada-day][_original_id]" value="canada-day"',
  			$html
  		);
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsOccasionsTabTest` — Expected: FAIL with "Call to undefined function blueline_settings_render_occasions_tab()".

- [ ] **Step 3: Write minimal implementation**

  In `inc/settings/page.php`, insert the following immediately after `blueline_settings_alignment_label()`'s closing `}` (before `add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_photo_picker' );`):

  ```php
  /**
   * Render the entire Occasions tab: an "add from preset"/"add blank"
   * toolbar, one row per stored occasion, and the `<template>` a JS-added
   * row is cloned from.
   *
   * Design spec §5.1's third ruling: this is the bespoke renderer
   * blueline_settings_render_page() dispatches to for the Occasions tab
   * INSTEAD of the generic per-field `<table>` loop -- `occasions` has
   * zero schema fields of its own (it is a reserved settings key, not a
   * schema field: see BLUELINE_SETTINGS_RESERVED_KEYS's own docblock),
   * so the generic loop has nothing to render for this tab at all.
   *
   * @return void
   */
  function blueline_settings_render_occasions_tab(): void {
  	$occasions        = blueline_settings( 'occasions' );
  	$occasions        = is_array( $occasions ) ? $occasions : array();
  	$inputs_hash      = blueline_settings_inputs_hash();
  	$acknowledgements = blueline_stored_acknowledgements();
  	$name             = BLUELINE_SETTINGS_OPTION . '[occasions]';
  	?>
  	<div class="bl-occasions" data-bl-occasions data-bl-occasions-name="<?php echo esc_attr( $name ); ?>">
  		<p class="description">
  			<?php
  			echo esc_html(
  				__( 'Occasions add a temporary accent colour, a small motif, and an optional line of copy for a set window of the calendar year. Nothing here activates until its Mode is set to something other than "Always off", or its window includes today.', 'blueline' )
  			);
  			?>
  		</p>

  		<ul class="bl-occasions__list" data-bl-occasions-list>
  			<?php if ( array() === $occasions ) : ?>
  				<li class="bl-occasions__empty" data-bl-occasions-empty>
  					<?php esc_html_e( 'No occasions configured yet.', 'blueline' ); ?>
  				</li>
  			<?php endif; ?>
  			<?php foreach ( $occasions as $id => $occasion ) : ?>
  				<?php
  				if ( is_array( $occasion ) ) {
  					blueline_settings_render_occasion_row( $name, (string) $id, $occasion, $inputs_hash, $acknowledgements );
  				}
  				?>
  			<?php endforeach; ?>
  		</ul>

  		<p class="bl-occasions__toolbar">
  			<label for="bl-occasions-preset-select"><?php esc_html_e( 'Add from preset', 'blueline' ); ?></label>
  			<select id="bl-occasions-preset-select" data-bl-occasions-preset-select>
  				<option value=""><?php esc_html_e( 'Choose a preset', 'blueline' ); ?></option>
  				<?php foreach ( blueline_occasion_presets() as $preset_id => $preset ) : ?>
  					<option
  						value="<?php echo esc_attr( $preset_id ); ?>"
  						data-bl-occasion-preset="<?php echo esc_attr( wp_json_encode( $preset ) ); ?>"
  					>
  						<?php echo esc_html( $preset['label'] ); ?>
  					</option>
  				<?php endforeach; ?>
  			</select>
  			<button type="button" class="button" data-bl-occasions-add-preset>
  				<?php esc_html_e( 'Add', 'blueline' ); ?>
  			</button>
  			<button type="button" class="button" data-bl-occasions-add-blank>
  				<?php esc_html_e( 'Add a blank occasion', 'blueline' ); ?>
  			</button>
  		</p>

  		<template data-bl-occasions-template>
  			<?php
  			blueline_settings_render_occasion_row(
  				$name,
  				'__TEMPLATE__',
  				array(
  					'id'     => '',
  					'label'  => '',
  					'type'   => 'decorative',
  					'window' => array(
  						'start_md' => '',
  						'end_md'   => '',
  					),
  					'accent' => '',
  					'motif'  => 'none',
  					'line'   => '',
  					'mode'   => 'auto',
  				),
  				$inputs_hash,
  				array()
  			);
  			?>
  		</template>
  	</div>
  	<?php
  }

  /**
   * Render one occasion's row: every Task-1-shaped field, the AA-override
   * checkbox+notice (design spec §5.1's fifth ruling), and a
   * server-computed contrast readout that is already correct even with
   * no JS at all.
   *
   * @param string                              $name             The `occasions` field's base POST name, e.g. `blueline_settings[occasions]`.
   * @param string                              $row_key          This row's per-request array key: a real occasion's own id for an existing row, or `__TEMPLATE__` for the `<template>` a later task's JS clones.
   * @param array<string, mixed>                $occasion         A Task-1-shaped Occasion (or the blank template shape above).
   * @param string                              $inputs_hash      blueline_settings_inputs_hash()'s current value.
   * @param array<string, array<string, mixed>> $acknowledgements blueline_stored_acknowledgements()'s current value.
   * @return void
   */
  function blueline_settings_render_occasion_row( string $name, string $row_key, array $occasion, string $inputs_hash, array $acknowledgements ): void {
  	$id     = (string) ( $occasion['id'] ?? '' );
  	$label  = (string) ( $occasion['label'] ?? '' );
  	$type   = (string) ( $occasion['type'] ?? 'decorative' );
  	$start  = (string) ( $occasion['window']['start_md'] ?? '' );
  	$end    = (string) ( $occasion['window']['end_md'] ?? '' );
  	$accent = (string) ( $occasion['accent'] ?? '' );
  	$motif  = (string) ( $occasion['motif'] ?? 'none' );
  	$line   = (string) ( $occasion['line'] ?? '' );
  	$mode   = (string) ( $occasion['mode'] ?? 'auto' );

  	$resolved_accent = '' !== $accent ? blueline_sanitize_hex_color( $accent ) : blueline_occasion_accent_default();
  	$swatch_accent   = '' !== $resolved_accent ? $resolved_accent : BLUELINE_TOKEN_INK;

  	$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $swatch_accent );
  	$passes = $ratio >= blueline_contrast_threshold( 'body' );

  	$already_acknowledged = '' !== $id && blueline_acknowledgement_covers(
  		$acknowledgements,
  		'occasion:' . $id,
  		'ink-on-occasion-accent',
  		$swatch_accent,
  		$inputs_hash
  	);

  	$base    = $name . '[' . $row_key . ']';
  	$row_uid = 'bl-occasion-' . sanitize_html_class( '' !== $row_key ? $row_key : 'row' );
  	?>
  	<li class="bl-occasions__row" data-bl-occasion-row>
  		<input
  			type="hidden"
  			name="<?php echo esc_attr( $base . '[_original_id]' ); ?>"
  			value="<?php echo esc_attr( $id ); ?>"
  			data-bl-occasion-original-id
  		>

  		<p class="bl-occasions__slug">
  			<?php esc_html_e( 'ID:', 'blueline' ); ?>
  			<code data-bl-occasion-slug-preview><?php echo esc_html( '' !== $id ? $id : __( '(new, named from its label)', 'blueline' ) ); ?></code>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-label' ); ?>"><?php esc_html_e( 'Label', 'blueline' ); ?></label>
  			<input
  				type="text"
  				id="<?php echo esc_attr( $row_uid . '-label' ); ?>"
  				name="<?php echo esc_attr( $base . '[label]' ); ?>"
  				value="<?php echo esc_attr( $label ); ?>"
  				data-bl-occasion-label
  			>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-type' ); ?>"><?php esc_html_e( 'Type', 'blueline' ); ?></label>
  			<select id="<?php echo esc_attr( $row_uid . '-type' ); ?>" name="<?php echo esc_attr( $base . '[type]' ); ?>" data-bl-occasion-type>
  				<?php foreach ( blueline_occasion_types() as $type_choice ) : ?>
  					<option value="<?php echo esc_attr( $type_choice ); ?>" <?php selected( $type, $type_choice ); ?>>
  						<?php echo esc_html( blueline_occasion_type_label( $type_choice ) ); ?>
  					</option>
  				<?php endforeach; ?>
  			</select>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-start' ); ?>"><?php esc_html_e( 'Start (MM-DD)', 'blueline' ); ?></label>
  			<input
  				type="text"
  				id="<?php echo esc_attr( $row_uid . '-start' ); ?>"
  				name="<?php echo esc_attr( $base . '[window][start_md]' ); ?>"
  				value="<?php echo esc_attr( $start ); ?>"
  				pattern="\d{2}-\d{2}"
  				placeholder="MM-DD"
  				data-bl-occasion-window-start
  			>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-end' ); ?>"><?php esc_html_e( 'End (MM-DD)', 'blueline' ); ?></label>
  			<input
  				type="text"
  				id="<?php echo esc_attr( $row_uid . '-end' ); ?>"
  				name="<?php echo esc_attr( $base . '[window][end_md]' ); ?>"
  				value="<?php echo esc_attr( $end ); ?>"
  				pattern="\d{2}-\d{2}"
  				placeholder="MM-DD"
  				data-bl-occasion-window-end
  			>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-accent' ); ?>"><?php esc_html_e( 'Accent colour (hex, blank for the theme default)', 'blueline' ); ?></label>
  			<input type="color" value="<?php echo esc_attr( $swatch_accent ); ?>" data-bl-occasion-color tabindex="-1" aria-hidden="true">
  			<input
  				type="text"
  				id="<?php echo esc_attr( $row_uid . '-accent' ); ?>"
  				name="<?php echo esc_attr( $base . '[accent]' ); ?>"
  				value="<?php echo esc_attr( $accent ); ?>"
  				placeholder="#rrggbb"
  				data-bl-occasion-accent
  			>
  		</p>

  		<p class="bl-occasions__contrast" data-bl-occasion-contrast aria-live="polite">
  			<?php
  			printf(
  				/* translators: 1: a contrast ratio like "4.5:1", 2: "passes AA" or "fails AA". */
  				esc_html__( 'Contrast against body text: %1$s (%2$s)', 'blueline' ),
  				esc_html( number_format( $ratio, 1 ) . ':1' ),
  				esc_html( $passes ? __( 'passes AA', 'blueline' ) : __( 'fails AA', 'blueline' ) )
  			);
  			?>
  		</p>

  		<section class="notice notice-warning bl-occasions__aa-notice" data-bl-occasion-aa-notice<?php echo $passes ? ' hidden' : ''; ?>>
  			<p>
  				<?php
  				echo esc_html(
  					__( 'This accent does not meet the AA contrast requirement against body text. Checking the box below ships it anyway. Leaving it unchecked means this occasion will not activate until the colour passes, or this box is checked and saved.', 'blueline' )
  				);
  				?>
  			</p>
  			<label>
  				<input
  					type="checkbox"
  					name="<?php echo esc_attr( $base . '[override_aa]' ); ?>"
  					value="1"
  					data-bl-occasion-override
  					<?php checked( $already_acknowledged ); ?>
  				>
  				<?php esc_html_e( 'Yes, ship this colour despite the failing contrast', 'blueline' ); ?>
  			</label>
  		</section>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-motif' ); ?>"><?php esc_html_e( 'Motif', 'blueline' ); ?></label>
  			<select id="<?php echo esc_attr( $row_uid . '-motif' ); ?>" name="<?php echo esc_attr( $base . '[motif]' ); ?>" data-bl-occasion-motif>
  				<?php foreach ( blueline_occasion_motifs() as $motif_choice ) : ?>
  					<option value="<?php echo esc_attr( $motif_choice ); ?>" <?php selected( $motif, $motif_choice ); ?>>
  						<?php echo esc_html( blueline_occasion_motif_label( $motif_choice ) ); ?>
  					</option>
  				<?php endforeach; ?>
  			</select>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-line' ); ?>"><?php esc_html_e( 'Optional line of copy', 'blueline' ); ?></label>
  			<input
  				type="text"
  				id="<?php echo esc_attr( $row_uid . '-line' ); ?>"
  				name="<?php echo esc_attr( $base . '[line]' ); ?>"
  				value="<?php echo esc_attr( $line ); ?>"
  				data-bl-occasion-line
  			>
  		</p>

  		<p>
  			<label for="<?php echo esc_attr( $row_uid . '-mode' ); ?>"><?php esc_html_e( 'Mode', 'blueline' ); ?></label>
  			<select id="<?php echo esc_attr( $row_uid . '-mode' ); ?>" name="<?php echo esc_attr( $base . '[mode]' ); ?>" data-bl-occasion-mode>
  				<?php foreach ( blueline_occasion_modes() as $mode_choice ) : ?>
  					<option value="<?php echo esc_attr( $mode_choice ); ?>" <?php selected( $mode, $mode_choice ); ?>>
  						<?php echo esc_html( blueline_occasion_mode_label( $mode_choice ) ); ?>
  					</option>
  				<?php endforeach; ?>
  			</select>
  		</p>

  		<button type="button" class="button-link bl-occasions__remove" data-bl-occasion-remove>
  			<?php esc_html_e( 'Remove', 'blueline' ); ?>
  		</button>
  	</li>
  	<?php
  }

  /**
   * Human-readable label for an occasion `type` value.
   *
   * @param string $type A blueline_occasion_types() value.
   * @return string
   */
  function blueline_occasion_type_label( string $type ): string {
  	$labels = array(
  		'decorative'    => __( 'Decorative', 'blueline' ),
  		'commemorative' => __( 'Commemorative', 'blueline' ),
  	);

  	return $labels[ $type ] ?? $type;
  }

  /**
   * Human-readable label for an occasion `motif` value.
   *
   * @param string $motif A blueline_occasion_motifs() value.
   * @return string
   */
  function blueline_occasion_motif_label( string $motif ): string {
  	$labels = array(
  		'none'       => __( 'None', 'blueline' ),
  		'maple-leaf' => __( 'Maple leaf', 'blueline' ),
  		'poppy'      => __( 'Poppy', 'blueline' ),
  		'snowflake'  => __( 'Snowflake', 'blueline' ),
  		'sparkle'    => __( 'Sparkle', 'blueline' ),
  	);

  	return $labels[ $motif ] ?? $motif;
  }

  /**
   * Human-readable label for an occasion `mode` value.
   *
   * @param string $mode A blueline_occasion_modes() value.
   * @return string
   */
  function blueline_occasion_mode_label( string $mode ): string {
  	$labels = array(
  		'auto'      => __( 'Automatic, during its window', 'blueline' ),
  		'force_on'  => __( 'Always on (preview now)', 'blueline' ),
  		'force_off' => __( 'Always off', 'blueline' ),
  	);

  	return $labels[ $mode ] ?? $mode;
  }
  ```

  Note the AA-notice `hidden` attribute logic: `data-bl-occasion-aa-notice<?php echo $passes ? ' hidden' : ''; ?>>` renders `data-bl-occasion-aa-notice hidden>` when the accent PASSES (notice hidden), and `data-bl-occasion-aa-notice>` when it FAILS (notice visible) — get this the right way round; it is easy to invert by accident.

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsOccasionsTabTest` — Expected: PASS.

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/page.php tests/SettingsOccasionsTabTest.php
  git commit -m "Add the Occasions tab's own render functions"
  ```

---

### Task 5: Tab routing — the named exception

**Files:**
- Modify: `inc/settings/page.php` (`blueline_settings_tab_slugs()`, `blueline_settings_tab_label()`, `blueline_settings_render_page()`)
- Test: `tests/SettingsPageTest.php` (add test methods)

**Interfaces:**
- Consumes: `blueline_settings_render_occasions_tab(): void` (Task 4).
- Produces: `'occasions'` appears in `blueline_settings_tab_slugs()`'s return value (as the final entry, after every schema-derived tab), `blueline_settings_tab_label( 'occasions' )` returns `'Occasions'`, and `blueline_settings_render_page()` dispatches to `blueline_settings_render_occasions_tab()` instead of the generic `<table>` loop when the current tab is `'occasions'`.

- [ ] **Step 1: Write the failing test**

  Add to `tests/SettingsPageTest.php`:

  ```php
  	/**
  	 * `occasions` appears in the tab list as one explicit, named
  	 * exception -- design spec §5.1's third ruling -- while every other
  	 * tab remains exactly what the schema itself declares.
  	 */
  	public function test_tab_slugs_includes_occasions_as_a_named_exception(): void {
  		$slugs = blueline_settings_tab_slugs();

  		$this->assertContains( 'occasions', $slugs );

  		$schema_tabs = array();
  		foreach ( blueline_settings_schema() as $field ) {
  			$tab = $field['tab'] ?? '';
  			if ( '' !== $tab && ! in_array( $tab, $schema_tabs, true ) ) {
  				$schema_tabs[] = $tab;
  			}
  		}

  		$this->assertSame( $schema_tabs, array_values( array_diff( $slugs, array( 'occasions' ) ) ) );
  	}

  	/**
  	 * Asserts `occasions` has a real, human-readable tab label rather
  	 * than falling through to the raw-slug guess.
  	 */
  	public function test_tab_label_for_occasions(): void {
  		$this->assertSame( 'Occasions', blueline_settings_tab_label( 'occasions' ) );
  	}

  	/**
  	 * On the Occasions tab, blueline_settings_render_page() dispatches
  	 * to the bespoke renderer instead of the generic per-field
  	 * `<table>` loop.
  	 */
  	public function test_render_page_dispatches_to_the_occasions_renderer(): void {
  		$_GET['tab'] = 'occasions'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simulating a read-only tab request, matching blueline_settings_current_tab()'s own contract.

  		ob_start();
  		blueline_settings_render_page();
  		$html = (string) ob_get_clean();

  		unset( $_GET['tab'] );

  		$this->assertStringContainsString( 'data-bl-occasions', $html );
  		$this->assertStringNotContainsString( '<table class="form-table"', $html );
  	}

  	/**
  	 * A schema-backed tab is unaffected: it still renders the generic
  	 * `<table>` loop, and never the occasions-specific markup.
  	 */
  	public function test_render_page_still_uses_the_generic_loop_for_a_schema_tab(): void {
  		$_GET['tab'] = 'content'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simulating a read-only tab request, matching blueline_settings_current_tab()'s own contract.

  		ob_start();
  		blueline_settings_render_page();
  		$html = (string) ob_get_clean();

  		unset( $_GET['tab'] );

  		$this->assertStringContainsString( '<table class="form-table"', $html );
  		$this->assertStringNotContainsString( 'data-bl-occasions', $html );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsPageTest` — Expected: FAIL — `test_tab_slugs_includes_occasions_as_a_named_exception()` fails because `'occasions'` is absent from `blueline_settings_tab_slugs()`'s return value, and `test_render_page_dispatches_to_the_occasions_renderer()` fails because `blueline_settings_current_tab()` falls back to the first schema tab (an unrecognised `tab` query value is never trusted) and renders the generic loop instead.

- [ ] **Step 3: Write minimal implementation**

  In `inc/settings/page.php`, modify `blueline_settings_tab_slugs()`:

  ```php
  function blueline_settings_tab_slugs(): array {
  	$slugs = array();
  	foreach ( blueline_settings_schema() as $field ) {
  		$tab = $field['tab'] ?? '';
  		if ( '' !== $tab && ! in_array( $tab, $slugs, true ) ) {
  			$slugs[] = $tab;
  		}
  	}

  	// One explicit, named exception (design spec §5.1's third ruling):
  	// `occasions` has zero schema fields of its own -- it is a reserved
  	// settings key (BLUELINE_SETTINGS_RESERVED_KEYS), not a
  	// `type => 'occasions'` schema entry. Every other tab above is still
  	// 100% schema-derived; this is the one deliberate exception, not a
  	// general "custom tabs" registration point nobody else needs.
  	$slugs[] = 'occasions';

  	return $slugs;
  }
  ```

  Modify `blueline_settings_tab_label()`:

  ```php
  function blueline_settings_tab_label( string $tab_slug ): string {
  	$labels = array(
  		'content'    => __( 'Content', 'blueline' ),
  		'links'      => __( 'Links', 'blueline' ),
  		'appearance' => __( 'Appearance', 'blueline' ),
  		'sections'   => __( 'Sections', 'blueline' ),
  		'commerce'   => __( 'Commerce', 'blueline' ),
  		'occasions'  => __( 'Occasions', 'blueline' ),
  	);

  	return $labels[ $tab_slug ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $tab_slug ) );
  }
  ```

  In `blueline_settings_render_page()`, locate this exact block:

  ```php
  			<table class="form-table" role="presentation">
  				<tbody>
  					<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
  						<?php blueline_settings_render_field( $field_key, $field, $field_errors[ $field_key ] ?? null ); ?>
  					<?php endforeach; ?>
  				</tbody>
  			</table>
  ```

  Replace it with:

  ```php
  			<?php if ( 'occasions' === $current_tab ) : ?>
  				<?php
  				/*
  				 * design spec §5.1's third ruling: one explicit, named
  				 * exception in the page renderer, not a general "custom
  				 * tabs" mechanism. blueline_settings_fields_for_tab(
  				 * 'occasions' ) is always empty (no schema field ever
  				 * declares tab => 'occasions'), so the generic loop below
  				 * would render nothing useful for this tab anyway -- this
  				 * branch swaps it for a bespoke renderer instead.
  				 */
  				?>
  				<?php blueline_settings_render_occasions_tab(); ?>
  			<?php else : ?>
  				<table class="form-table" role="presentation">
  					<tbody>
  						<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
  							<?php blueline_settings_render_field( $field_key, $field, $field_errors[ $field_key ] ?? null ); ?>
  						<?php endforeach; ?>
  					</tbody>
  				</table>
  			<?php endif; ?>
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsPageTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/settings/page.php tests/SettingsPageTest.php
  git commit -m "Add the Occasions tab to tab routing as a named exception"
  ```

---

### Task 6: The live contrast-readout JS, and its PHP<->JS parity test

**Files:**
- Create: `assets/src/js/settings-occasions.js`
- Modify: `inc/settings/page.php` (add `blueline_settings_maybe_enqueue_occasions_script()`)
- Test: `assets/src/js/settings-occasions.test.mjs` (new)
- Test: `tests/OccasionsContrastParityTest.php` (new)

**Interfaces:**
- Consumes: nothing from earlier 2.1b tasks at runtime (the JS math is an independent, deliberate duplicate — design spec §5.1's fourth ruling — not a call into any PHP or earlier-task code). Shares only a data-attribute CONTRACT with Task 4's server-rendered markup: `[data-bl-occasions]` (root), `[data-bl-occasions-list]`, `[data-bl-occasions-empty]`, `[data-bl-occasions-template]`, `[data-bl-occasions-preset-select]`, `[data-bl-occasions-add-preset]`, `[data-bl-occasions-add-blank]`, `[data-bl-occasion-row]`, `[data-bl-occasion-label]`, `[data-bl-occasion-type]`, `[data-bl-occasion-window-start]`, `[data-bl-occasion-window-end]`, `[data-bl-occasion-accent]`, `[data-bl-occasion-color]`, `[data-bl-occasion-contrast]`, `[data-bl-occasion-aa-notice]`, `[data-bl-occasion-motif]`, `[data-bl-occasion-line]`, `[data-bl-occasion-mode]`, `[data-bl-occasion-remove]` — all already emitted by Task 4. Also consumes, server-side only (for the enqueue function): `blueline_settings_page_hook(): ?string`, `blueline_settings_current_tab(): string` (pre-existing, `inc/settings/page.php`), `BLUELINE_DIR`, `BLUELINE_URI` (pre-existing constants), `BLUELINE_TOKEN_INK`, `blueline_contrast_threshold( string $which ): float` (pre-existing, `inc/team-colors.php`).
- Produces: `blOccasionLuminance( hex )`, `blOccasionContrastRatio( a, b )`, `blOccasionIsHex( value )` (plain functions in `assets/src/js/settings-occasions.js`, additionally exposed via a CommonJS `module.exports` guard for `settings-occasions.test.mjs` only — never reachable in a real browser, where `module` is undefined). `blueline_settings_maybe_enqueue_occasions_script( string $hook_suffix ): void` (`inc/settings/page.php`), hooked to `admin_enqueue_scripts`, enqueuing the script only when the current admin screen is this settings page AND the current tab is `'occasions'`, and passing `BLUELINE_TOKEN_INK`/the body contrast threshold to it via `wp_localize_script()` as the global `blOccasionsData` (`{ inkHex, threshold }`) — never hardcoded independently in the JS file, which would be a third place these values could drift out of sync.

- [ ] **Step 1: Write the failing test**

  Create `assets/src/js/settings-occasions.test.mjs`:

  ```js
  import { test } from 'node:test';
  import assert from 'node:assert/strict';
  import { createRequire } from 'node:module';

  const require = createRequire( import.meta.url );
  const { blOccasionContrastRatio, blOccasionIsHex } = require( './settings-occasions.js' );

  test( 'blOccasionContrastRatio: black on white is the maximum ratio', () => {
  	assert.ok( blOccasionContrastRatio( '#000000', '#ffffff' ) > 20 );
  } );

  test( 'blOccasionContrastRatio: a colour against itself is exactly 1', () => {
  	assert.equal( blOccasionContrastRatio( '#132343', '#132343' ), 1 );
  } );

  test( 'blOccasionContrastRatio: order of arguments does not matter', () => {
  	const a = blOccasionContrastRatio( '#132343', '#ffffff' );
  	const b = blOccasionContrastRatio( '#ffffff', '#132343' );
  	assert.equal( a, b );
  } );

  test( 'blOccasionIsHex: accepts a well-formed 6-digit hex colour', () => {
  	assert.equal( blOccasionIsHex( '#132343' ), true );
  	assert.equal( blOccasionIsHex( '#ABCDEF' ), true );
  } );

  test( 'blOccasionIsHex: rejects anything else', () => {
  	assert.equal( blOccasionIsHex( '132343' ), false );
  	assert.equal( blOccasionIsHex( '#12334' ), false );
  	assert.equal( blOccasionIsHex( 'not-a-colour' ), false );
  	assert.equal( blOccasionIsHex( '' ), false );
  } );

  /*
   * PHP<->JS parity fixture (design spec §5.1's fourth ruling): these
   * exact hex pairs and their expected ratios were computed once,
   * directly, with this exact formula, and confirmed identical (to 6
   * decimal places) against inc/team-colors.php's
   * blueline_contrast_ratio() by running both side by side -- see
   * tests/OccasionsContrastParityTest.php, which pins the SAME table on
   * the PHP side. Neither file imports the other; if
   * blOccasionContrastRatio() or blueline_contrast_ratio() ever drifts
   * from this formula, whichever one moved fails its OWN half of this
   * pinned table, which is the whole point: a silent, one-sided drift is
   * exactly what the ruling is guarding against.
   */
  const PARITY_FIXTURE = [
  	[ '#132343', '#ffffff', 15.565337 ],
  	[ '#132343', '#000000', 1.349152 ],
  	[ '#132343', '#132343', 1.0 ],
  	[ '#132343', '#274a63', 1.664712 ],
  	[ '#132343', '#f7fbfc', 14.942248 ],
  	[ '#132343', '#c8102e', 2.645692 ],
  	[ '#132343', '#ffd700', 11.097474 ],
  ];

  test( 'blOccasionContrastRatio matches the PHP<->JS parity fixture', () => {
  	for ( const [ a, b, expected ] of PARITY_FIXTURE ) {
  		const actual = blOccasionContrastRatio( a, b );
  		assert.ok(
  			Math.abs( actual - expected ) < 0.0005,
  			`${ a } vs ${ b }: expected ${ expected }, got ${ actual }`
  		);
  	}
  } );
  ```

  Create `tests/OccasionsContrastParityTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/team-colors.php';

  /**
   * Design spec §5.1's fourth ruling: assets/src/js/settings-occasions.js
   * carries a small, DELIBERATE duplicate of blueline_contrast_ratio()'s
   * math (never an import of tools/lib/contrast.mjs, which is dev/build
   * tooling, not a runtime asset), and that duplicate must be proven to
   * agree with the PHP original at a fixed set of hex pairs -- "required,
   * not optional" per that ruling.
   *
   * This is the PHP half of a two-file, shared-fixture parity guard: this
   * exact table of pairs and expected ratios is pinned again,
   * independently, on the JS side in
   * assets/src/js/settings-occasions.test.mjs (see that file's own
   * comment). Both tables were produced from the SAME computation, run
   * once and confirmed to agree to 6 decimal places before either was
   * written down -- so a future edit to either formula that silently
   * drifts from the other fails ITS OWN half of this shared table,
   * without either file ever having to import, shell out to, or
   * otherwise depend on the other language at runtime.
   */
  final class OccasionsContrastParityTest extends TestCase {

  	/**
  	 * The exact same seven hex pairs and expected ratios as
  	 * assets/src/js/settings-occasions.test.mjs's own PARITY_FIXTURE.
  	 * Keep both in sync by hand if this ever changes -- there is no
  	 * automated link between the two files, by design (see this
  	 * class's own docblock).
  	 *
  	 * @return array<int, array{0:string, 1:string, 2:float}>
  	 */
  	private function fixture(): array {
  		return array(
  			array( '#132343', '#ffffff', 15.565337 ),
  			array( '#132343', '#000000', 1.349152 ),
  			array( '#132343', '#132343', 1.0 ),
  			array( '#132343', '#274a63', 1.664712 ),
  			array( '#132343', '#f7fbfc', 14.942248 ),
  			array( '#132343', '#c8102e', 2.645692 ),
  			array( '#132343', '#ffd700', 11.097474 ),
  		);
  	}

  	/**
  	 * Asserts blueline_contrast_ratio() matches the shared fixture, to
  	 * within a small floating-point delta.
  	 */
  	public function test_php_contrast_ratio_matches_the_shared_parity_fixture(): void {
  		foreach ( $this->fixture() as $pair ) {
  			list( $a, $b, $expected ) = $pair;
  			$this->assertEqualsWithDelta(
  				$expected,
  				blueline_contrast_ratio( $a, $b ),
  				0.0005,
  				"$a vs $b"
  			);
  		}
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `npm run test:js` — Expected: FAIL — Node reports it cannot find module `./settings-occasions.js` (it does not exist yet).

  Run: `composer test -- --filter OccasionsContrastParityTest` — Expected: PASS already. `blueline_contrast_ratio()` is pre-existing (shipped in Phase 2.0), and this task adds no new PHP function — only the JS side is new here, so only the JS-side test can be legitimately red at this step. This is expected, not a problem: the PHP file is a regression pin confirming the fixture is accurate against the CURRENT PHP behaviour, which Step 4 will re-confirm is still true once the JS side exists.

- [ ] **Step 3: Write minimal implementation**

  Create `assets/src/js/settings-occasions.js`:

  ```js
  /**
   * The live contrast readout, colour-input syncing, and repeater
   * mechanics for the Occasions tab in Appearance -> Blueline.
   *
   * The ratio/luminance math below is a DELIBERATE, small,
   * self-contained DUPLICATE of blueline_contrast_ratio()/
   * blueline_relative_luminance() (inc/team-colors.php) -- not an import
   * of tools/lib/contrast.mjs, which is dev/build tooling, not a runtime
   * asset (design spec §5.1's fourth ruling). tests/OccasionsContrastParityTest.php
   * and this file's own settings-occasions.test.mjs both assert this
   * copy agrees with the PHP original at a fixed set of hex pairs -- if
   * this math ever changes, BOTH must change with it, or one of those
   * two tests fails.
   *
   * Progressive, not required: with this file absent, every row still
   * renders with its correct, server-computed (at last save) contrast
   * readout and override state -- only the LIVE update as an admin
   * edits a colour is lost, the same "accurate but not live"
   * degradation settings-photos.js's own docblock describes for its
   * "Add" button.
   *
   * global module -- see the CommonJS export guard at the foot of this
   * file, used only by settings-occasions.test.mjs (Node's test
   * runner); `module` is never defined in a browser, so that guard is
   * always skipped there.
   */

  /* global module */

  const ROOT = '[data-bl-occasions]';

  /**
   * WCAG relative luminance of a `#rrggbb` colour. Byte-for-byte the
   * same formula as inc/team-colors.php's blueline_relative_luminance().
   *
   * @param {string} hex 6-digit hex, with `#`.
   * @return {number} 0..1.
   */
  function blOccasionLuminance( hex ) {
  	const n = parseInt( hex.slice( 1 ), 16 );
  	const channel = ( c ) => {
  		const s = c / 255;
  		return s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
  	};
  	return (
  		0.2126 * channel( ( n >> 16 ) & 255 ) +
  		0.7152 * channel( ( n >> 8 ) & 255 ) +
  		0.0722 * channel( n & 255 )
  	);
  }

  /**
   * WCAG contrast ratio between two `#rrggbb` colours. Byte-for-byte the
   * same formula as inc/team-colors.php's blueline_contrast_ratio().
   *
   * @param {string} a Hex colour.
   * @param {string} b Hex colour.
   * @return {number} 1..21.
   */
  function blOccasionContrastRatio( a, b ) {
  	const la = blOccasionLuminance( a );
  	const lb = blOccasionLuminance( b );
  	const light = Math.max( la, lb );
  	const dark = Math.min( la, lb );
  	return ( light + 0.05 ) / ( dark + 0.05 );
  }

  /**
   * Whether $value looks like a real `#rrggbb` hex colour -- deliberately
   * strict, since this only decides whether to run the LIVE readout at
   * all; the authoritative check remains blueline_sanitize_hex_color()
   * at save time.
   *
   * @param {string} value Candidate value.
   * @return {boolean}
   */
  function blOccasionIsHex( value ) {
  	return /^#[0-9a-f]{6}$/i.test( value );
  }

  /**
   * Update one row's contrast readout, override-notice visibility, and
   * colour-swatch mirror from its current hex text field value.
   *
   * @param {Element} row       A [data-bl-occasion-row] element.
   * @param {string}  inkHex    The site's ink token, from window.blOccasionsData.
   * @param {number}  threshold Minimum passing ratio, from window.blOccasionsData.
   * @return {void}
   */
  function updateRow( row, inkHex, threshold ) {
  	const hexInput = row.querySelector( '[data-bl-occasion-accent]' );
  	const colorInput = row.querySelector( '[data-bl-occasion-color]' );
  	const readout = row.querySelector( '[data-bl-occasion-contrast]' );
  	const notice = row.querySelector( '[data-bl-occasion-aa-notice]' );

  	if ( ! hexInput || ! readout ) {
  		return;
  	}

  	const raw = hexInput.value.trim();
  	const effective = blOccasionIsHex( raw ) ? raw : colorInput ? colorInput.value : inkHex;

  	if ( colorInput && blOccasionIsHex( raw ) ) {
  		colorInput.value = raw;
  	}

  	if ( ! blOccasionIsHex( effective ) ) {
  		return; // Nothing sensible to show yet (e.g. mid-edit); leave the last known-good readout in place.
  	}

  	const ratio = blOccasionContrastRatio( inkHex, effective );
  	const passes = ratio >= threshold;

  	readout.textContent = `Contrast against body text: ${ ratio.toFixed( 1 ) }:1 (${ passes ? 'passes AA' : 'fails AA' })`;

  	if ( notice ) {
  		notice.hidden = passes;
  	}
  }

  let addCounter = 0;

  /**
   * Append a new row, cloned from the server-rendered `<template>`,
   * filled from either a preset or a blank shape.
   *
   * Every field in the clone is renamed to a fresh, unique placeholder
   * row key (`__new_{n}`) so two added rows in the same submission can
   * never collide as PHP array keys before the server ever gets a
   * chance to derive their real ids (design spec §5.1's first ruling)
   * -- unlike settings-photos.js's renumber(), no OTHER row's name
   * needs to change when one is added or removed, since `occasions` is
   * a map, not a contiguous indexed array.
   *
   * @param {Element} root   The field wrapper.
   * @param {Object}  preset A blueline_occasion_presets() entry, or {} for blank.
   * @return {void}
   */
  function addRow( root, preset ) {
  	const list = root.querySelector( '[data-bl-occasions-list]' );
  	const template = root.querySelector( '[data-bl-occasions-template]' );
  	const empty = root.querySelector( '[data-bl-occasions-empty]' );

  	if ( ! list || ! template ) {
  		return;
  	}

  	addCounter += 1;
  	const rowKey = `__new_${ addCounter }`;

  	const row = template.content.firstElementChild.cloneNode( true );

  	row.querySelectorAll( '[name]' ).forEach( ( field ) => {
  		field.name = field.name.replace( '__TEMPLATE__', rowKey );
  	} );

  	const setField = ( selector, value ) => {
  		const field = row.querySelector( selector );
  		if ( field && undefined !== value ) {
  			field.value = value;
  		}
  	};

  	setField( '[data-bl-occasion-label]', preset.label || '' );
  	setField( '[data-bl-occasion-accent]', preset.accent || '' );
  	setField( '[data-bl-occasion-line]', preset.line || '' );

  	if ( preset.window ) {
  		setField( '[data-bl-occasion-window-start]', preset.window.start_md || '' );
  		setField( '[data-bl-occasion-window-end]', preset.window.end_md || '' );
  	}

  	[ 'type', 'motif', 'mode' ].forEach( ( key ) => {
  		if ( ! preset[ key ] ) {
  			return;
  		}
  		const field = row.querySelector( `[data-bl-occasion-${ key }]` );
  		if ( field ) {
  			field.value = preset[ key ];
  		}
  	} );

  	if ( empty ) {
  		empty.hidden = true;
  	}

  	list.appendChild( row );

  	const data = window.blOccasionsData || { inkHex: '#132343', threshold: 4.5 };
  	updateRow( row, data.inkHex, data.threshold );
  }

  function initOccasionsField( root ) {
  	const data = window.blOccasionsData || { inkHex: '#132343', threshold: 4.5 };

  	root.querySelectorAll( '[data-bl-occasion-row]' ).forEach( ( row ) => {
  		updateRow( row, data.inkHex, data.threshold );
  	} );

  	root.addEventListener( 'input', ( event ) => {
  		const row = event.target.closest( '[data-bl-occasion-row]' );

  		if ( row && event.target.matches( '[data-bl-occasion-accent]' ) ) {
  			updateRow( row, data.inkHex, data.threshold );
  		}
  	} );

  	root.addEventListener( 'change', ( event ) => {
  		const colorInput = event.target.closest( '[data-bl-occasion-color]' );

  		if ( ! colorInput ) {
  			return;
  		}

  		const row = colorInput.closest( '[data-bl-occasion-row]' );
  		const hexInput = row ? row.querySelector( '[data-bl-occasion-accent]' ) : null;

  		if ( row && hexInput ) {
  			hexInput.value = colorInput.value;
  			updateRow( row, data.inkHex, data.threshold );
  		}
  	} );

  	root.addEventListener( 'click', ( event ) => {
  		const remove = event.target.closest( '[data-bl-occasion-remove]' );

  		if ( remove ) {
  			const row = remove.closest( '[data-bl-occasion-row]' );
  			if ( row ) {
  				row.remove();
  			}
  			return;
  		}

  		if ( event.target.closest( '[data-bl-occasions-add-blank]' ) ) {
  			addRow( root, {} );
  			return;
  		}

  		if ( event.target.closest( '[data-bl-occasions-add-preset]' ) ) {
  			const select = root.querySelector( '[data-bl-occasions-preset-select]' );
  			const chosen = select ? select.options[ select.selectedIndex ] : null;
  			const preset = chosen && chosen.dataset.blOccasionPreset ? JSON.parse( chosen.dataset.blOccasionPreset ) : null;

  			if ( preset ) {
  				addRow( root, preset );
  			}
  		}
  	} );
  }

  function init() {
  	document.querySelectorAll( ROOT ).forEach( initOccasionsField );
  }

  if ( typeof document !== 'undefined' ) {
  	if ( 'loading' === document.readyState ) {
  		document.addEventListener( 'DOMContentLoaded', init );
  	} else {
  		init();
  	}
  }

  if ( typeof module !== 'undefined' && module.exports ) {
  	module.exports = { blOccasionLuminance, blOccasionContrastRatio, blOccasionIsHex };
  }
  ```

  Modify `inc/settings/page.php`: append, at the end of the file, after `blueline_settings_photo_picker_styles()`:

  ```php
  add_action( 'admin_enqueue_scripts', 'blueline_settings_maybe_enqueue_occasions_script' );
  /**
   * Enqueue the Occasions tab's live contrast-readout/repeater script, on
   * this page's Occasions tab only.
   *
   * A plain source file, no webpack entry -- the same deliberate choice
   * blueline_settings_maybe_enqueue_photo_picker()'s own docblock
   * explains for settings-photos.js: no imports, no JSX, no dependencies.
   * Still linted (npm run lint:js) and still shipped by the same rsync
   * as everything else.
   *
   * blueline_settings_inputs_hash()-adjacent values -- BLUELINE_TOKEN_INK
   * and blueline_contrast_threshold( 'body' ) -- are read here,
   * server-side, and handed to the script via wp_localize_script():
   * real settings data the JS math needs but must never hardcode
   * independently, which would be a third place these values could
   * drift out of sync from inc/team-colors.php.
   *
   * @param string $hook_suffix The current admin screen's hook suffix.
   * @return void
   */
  function blueline_settings_maybe_enqueue_occasions_script( string $hook_suffix ): void {
  	if ( blueline_settings_page_hook() !== $hook_suffix ) {
  		return;
  	}

  	if ( 'occasions' !== blueline_settings_current_tab() ) {
  		return;
  	}

  	$relative = '/assets/src/js/settings-occasions.js';
  	$path     = BLUELINE_DIR . $relative;

  	wp_enqueue_script(
  		'blueline-settings-occasions',
  		BLUELINE_URI . $relative,
  		array(),
  		file_exists( $path ) ? (string) filemtime( $path ) : '1',
  		true
  	);

  	wp_localize_script(
  		'blueline-settings-occasions',
  		'blOccasionsData',
  		array(
  			'inkHex'    => BLUELINE_TOKEN_INK,
  			'threshold' => blueline_contrast_threshold( 'body' ),
  		)
  	);
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `npm run test:js` — Expected: PASS (all tests, including the parity fixture).

  Run: `composer test -- --filter OccasionsContrastParityTest` — Expected: PASS, unchanged.

  Run: `npm run lint:js` — Expected: PASS. If it flags `module` as undefined, confirm the `/* global module */` comment is present at the top of `settings-occasions.js`.

- [ ] **Step 5: Commit**
  ```bash
  git add assets/src/js/settings-occasions.js assets/src/js/settings-occasions.test.mjs inc/settings/page.php tests/OccasionsContrastParityTest.php
  git commit -m "Add the live contrast-readout JS and its PHP<->JS parity test"
  ```

---

### Task 7: Full verification

**Files:**
- (none — verification only)

**Interfaces:**
- Consumes: everything produced by Tasks 1-6.
- Produces: a confirmed-green `npm run check` for this phase.

- [ ] **Step 1: Run the full gate**

  Run: `npm run check` — Expected: PASS (`lint:css`, `lint:js`, `test:js`, `tokens:check`, `composer test`, `composer lint` all green). `lint:css`/`tokens:check` should be unaffected by anything in this plan (no CSS or token changes); if either fails, the failure is a pre-existing condition on this branch, not something Tasks 1-6 introduced — investigate before assuming otherwise.

- [ ] **Step 2: If `composer lint` (phpcs) reports anything in the modified files**

  Fix any WordPress-Coding-Standards nit it finds (docblock alignment, spacing, array alignment) with a line-level `phpcs:ignore <sniff> -- <reason>` only where the sniff is flagging something deliberate — never `phpcs:ignoreFile`, and never stack a second annotation on a line that already has a trailing one.

- [ ] **Step 3: Confirm no test from Tasks 1-6 regressed**

  Run: `composer test` — Expected: PASS, full suite (not just this phase's new files). Tasks 2 and 3 both added a new branch to `blueline_settings_sanitize_callback()`, a shared write path every settings save reaches — exactly the kind of change that can retroactively break an existing test reaching the same path.

- [ ] **Step 4: Confirm the suite passes with an occasions-tab save and an override checkbox actually exercised, not only at defaults**

  Per the design spec's §7 ("whole-suite, every phase" testing requirement): this plan's own tests already exercise the full suite with a real Occasions-tab submission, a recorded acknowledgement, and an orphan-cleanup case (`tests/SettingsPageTest.php`'s new methods from Tasks 2/3, `tests/OccasionsTest.php`'s new methods from Tasks 1/3), so Step 3's full run already covers this — no separate action needed here beyond confirming Step 3 actually passed with those files included, not skipped by an over-narrow `--filter`.

- [ ] **Step 5: Manual smoke check (optional but recommended before merging)**

  Load Appearance -> Blueline -> Occasions in a real wp-admin session. Confirm: the tab appears in the nav; "Add a blank occasion" and "Add from preset" both append a row via the `<template>` clone; typing a hex into the accent field updates the live readout without a page reload; unchecking JS (or disabling it) still shows the last-saved, server-computed readout and still allows a save; saving a failing accent with the override box checked persists across a reload with the box still checked; removing an occasion and saving removes its acknowledgement too (confirmed via Site Health's raw option dump, or a direct database check, since Phase 2.2's Site Health surfacing is out of scope for this plan).

- [ ] **Step 6: Commit** — nothing to commit; this task is verification-only. If Step 2 required a fix, that fix was already committed as part of its own task before reaching here; re-run Step 1 to confirm.

