# Blueline P2 Phase 2.1a — Occasions Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete, testable Occasions backend — the model/sanitizer, the read-only preset catalog, the resolution engine (with its AA-override integration), the motif SVG set, commemorative announcement-severity suppression, the WP-Cron boundary purge, and block-editor CSS parity — with zero admin-facing surface.

**Architecture:** Everything lives in the existing `inc/occasions.php` (already holding Phase 2.0's `blueline_occasion_accent_default()`), following the same never-fatal, log-once defensive-read posture that file and `inc/team-colors.php` already established. `occasions` becomes a new reserved top-level key in the settings option — mechanically identical to `aa_acknowledgements` (its own `blueline_sanitize_occasions()` validator, dispatched from `blueline_settings_sanitize_callback()`'s reserved-key branch) except that, per the design spec's first ruling, it DOES get a real default (`array()`) so the front end can read it via `blueline_settings( 'occasions' )`. The resolution engine is a small set of pure functions (window matching, precedence, today's date in site timezone) composed into one `blueline_resolve_active_occasion()`, which is itself the single source every other piece (motifs' caller, the announcement filter, the cron boundary calculator, the editor-parity filter) reads from — none of them re-derives "what's active" independently. WP-Cron scheduling reuses this theme's own established convention (`update_option_{$option}`/`add_option_{$option}` hooks, exactly as `inc/settings/cache.php` already does for its purge trigger) rather than polling on `init`.

**Tech Stack:** PHP 8.1+ (PHPUnit 12 against `tests/bootstrap.php`'s WordPress stubs). No new build tooling, no new `tools/` files, no JS.

**Spec:** docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md (§5 specifically)

## Global Constraints

- `phpcs:ignoreFile` is forbidden. Only line-level `phpcs:ignore <sniff> -- <reason>`, and a trailing annotation on a line REPLACES a preceding-line one silently — never stack two expecting both to apply.
- Every `sanitize_option_{$option}`-equivalent validator this phase touches must keep running unconditionally at file scope, so WP-CLI and a direct `update_option()` call are validated exactly like the panel — this phase must not weaken that.
- No admin notice may render as a bare `<div>` (`NoticeDivGuardTest`) — not directly relevant to this phase (no UI), but no new notice-rendering code may sneak in that violates it.
- Never write a comment or docblock claiming a test proves something that isn't actually true, or that code does something it doesn't — verify before asserting.
- A guard added to a write path retroactively threatens every test that reached the guarded value through that path — Task 1 adds a new sanitizer branch (`occasions`); re-run the whole suite after it, not just the new tests.
- No general-purpose PHP `:root` CSS parser. The editor-parity filter (Task 7) emits only the one already-known `--bl-occasion-accent` token with an already-validated hex value — it resolves nothing new.
- `blueline_settings`'s storage shape stays flat. `occasions` is a new top-level key (a map keyed by occasion id), not a nested structure.
- **Ruling (design spec §5): `occasions` is a reserved key, not a schema field.** No `type => 'occasions'` schema entry, no `tab => 'occasions'` — either would make `blueline_settings_tab_slugs()` create a half-functional "Occasions" tab with no render branch. Protected exactly like `aa_acknowledgements`: `BLUELINE_SETTINGS_RESERVED_KEYS`, a branch in `blueline_settings_sanitize_callback()`, its own `blueline_sanitize_occasions()`. Unlike `aa_acknowledgements`, it DOES get a default (`'occasions' => array()`) in `blueline_settings_defaults()`, because the front-end resolver needs `blueline_settings( 'occasions' )` to return something.
- **Ruling (design spec §5): the four shipped occasions are a read-only preset catalog, not pre-populated live entries.** `blueline_settings_defaults()`'s `occasions` default is a genuinely empty `array()`. `blueline_occasion_presets()` is a separate, read-only function for a future admin UI (2.1b) to read from — the resolver never calls it, and nothing in this plan seeds the real stored `occasions` array with any preset.
- Cron is used ONLY for the purge, never for correctness. `blueline_resolve_active_occasion()` recomputes from scratch on every request; a missed or delayed cron event delays visibility of a change but never produces a wrong result.
- Editor parity uses the `block_editor_settings_all` filter, selector `:root, .editor-styles-wrapper`, emitting only `--bl-occasion-accent` — never `enqueue_block_editor_assets` (see `inc/enqueue.php`'s own docblock for why that hook was tried and reverted).
- Out of scope for this plan: any admin UI (colour input, contrast readout, motif picker, an Occasions tab, the save-time AA-override checkbox) — that is Phase 2.1b, a separate plan. Also out of scope: Phase 2.2's `_validated_against`, its Site Health fields, and `wp blueline settings occasions`.
- Every new `.php` file under `inc/` must be `require_once`'d from `functions.php`, or `tests/IncRequireCoverageTest.php` fails the build. This plan adds no new `inc/` file — everything lives in the already-required `inc/occasions.php` and modifies already-required files — so no `functions.php` change is needed anywhere in this plan.
- No new `inc/` file may contain a bare top-level function call outside the allow-list (`add_action`/`add_filter`/`remove_action`/`remove_filter`/`defined`/`define`/`class_exists`/`function_exists`/`interface_exists`/`trait_exists`/`method_exists`/`WP_CLI::add_command`), or `tests/IncTopLevelCallGuardTest.php` fails the build. Every file-scope statement this plan adds to `inc/occasions.php` is an `add_action()`/`add_filter()` call.

---

### Task 1: The Occasion model, its sanitizer, and reserved-key registration

**Files:**
- Modify: `inc/occasions.php` (add enums + `blueline_sanitize_occasions()`)
- Modify: `inc/settings/defaults.php` (`blueline_settings_defaults()`)
- Modify: `inc/settings/page.php:219` (`BLUELINE_SETTINGS_RESERVED_KEYS`), and the reserved-key branch of `blueline_settings_sanitize_callback()` (around line 485)
- Modify: `tests/SettingsPageTest.php` (add requires, add test methods)
- Test: `tests/OccasionsTest.php` (add requires, add test methods)

**Interfaces:**
- Consumes: `blueline_sanitize_hex_color( $value ): string` (`inc/team-colors.php:199`, pre-existing), `BLUELINE_SETTINGS_RESERVED_KEYS`/`blueline_settings_sanitize_callback()` (`inc/settings/page.php`, pre-existing), `blueline_settings_defaults()` (`inc/settings/defaults.php`, pre-existing), `blueline_settings_merge()` (`inc/settings/store.php`, pre-existing, unmodified — its generic carry-forward logic already protects `occasions` since no tab's `_posted_fields` ever names it).
- Produces: `blueline_occasion_types(): array`, `blueline_occasion_motifs(): array`, `blueline_occasion_modes(): array`, `blueline_occasion_valid_md( $value ): bool`, `blueline_sanitize_occasions( $value ): array` (all `inc/occasions.php`) — the exact Occasion array shape every later task in this plan uses:
  ```
  array(
      'id'     => string,               // must equal the map's own key
      'label'  => non-empty string,
      'type'   => 'decorative' | 'commemorative',
      'window' => array( 'start_md' => 'MM-DD', 'end_md' => 'MM-DD' ),
      'accent' => '' | '#rrggbb',        // '' means "use the resolved default"
      'motif'  => 'none' | 'maple-leaf' | 'poppy' | 'snowflake' | 'sparkle',
      'line'   => string,                // '' allowed; never contains '%'
      'mode'   => 'auto' | 'force_on' | 'force_off',
  )
  ```
  the default `'occasions' => array()` in `blueline_settings_defaults()`, and `'occasions'` on `BLUELINE_SETTINGS_RESERVED_KEYS`.

- [ ] **Step 1: Write the failing test**

  Modify `tests/OccasionsTest.php`'s requires (it currently has only one):

  ```php
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';
  ```

  Add these methods to the existing `OccasionsTest` class:

  ```php
  	/* ------------------------------------------------------- sanitizer */

  	/**
  	 * A well-formed entry, reused across several tests.
  	 *
  	 * @return array<string, mixed>
  	 */
  	private function valid_occasion(): array {
  		return array(
  			'id'     => 'canada-day',
  			'label'  => 'Canada Day',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  			'accent' => '',
  			'motif'  => 'maple-leaf',
  			'line'   => '',
  			'mode'   => 'auto',
  		);
  	}

  	/**
  	 * Asserts a non-array value sanitizes to an empty map.
  	 */
  	public function test_sanitize_occasions_non_array_value_sanitizes_to_empty(): void {
  		foreach ( array( null, 'not-an-array', 42, false ) as $bad ) {
  			$this->assertSame( array(), blueline_sanitize_occasions( $bad ) );
  		}
  	}

  	/**
  	 * Asserts a well-formed entry survives, keyed by its own id, and is
  	 * returned as exactly the eight documented keys (no extras carried
  	 * through from a submission).
  	 */
  	public function test_sanitize_occasions_a_well_formed_entry_survives(): void {
  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $this->valid_occasion() ) );

  		$this->assertSame( $this->valid_occasion(), $clean['canada-day'] );
  	}

  	/**
  	 * Asserts an entry missing a required key is dropped rather than
  	 * corrupting the whole read.
  	 */
  	public function test_sanitize_occasions_an_entry_missing_a_required_key_is_dropped(): void {
  		$entry = $this->valid_occasion();
  		unset( $entry['motif'] );

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts an entry whose own `id` field disagrees with its map key is
  	 * dropped rather than trusted — mirrors the `aa_acknowledgements`
  	 * precedent's identical check on its `scope` field
  	 * (tests/SettingsAcknowledgementsTest.php).
  	 */
  	public function test_sanitize_occasions_an_entry_whose_id_disagrees_with_its_key_is_dropped(): void {
  		$entry       = $this->valid_occasion();
  		$entry['id'] = 'remembrance-day';

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts a non-array row value (not a whole entry) is dropped.
  	 */
  	public function test_sanitize_occasions_a_non_array_row_is_dropped(): void {
  		$clean = blueline_sanitize_occasions( array( 'canada-day' => 'not-an-array' ) );

  		$this->assertSame( array(), $clean );
  	}

  	/**
  	 * Asserts a valid entry and an invalid one in the same map are handled
  	 * independently.
  	 */
  	public function test_sanitize_occasions_one_bad_entry_does_not_take_down_a_good_one(): void {
  		$bad = $this->valid_occasion();
  		$bad['id']    = 'remembrance-day';
  		$bad['label'] = '';

  		$clean = blueline_sanitize_occasions(
  			array(
  				'canada-day'      => $this->valid_occasion(),
  				'remembrance-day' => $bad,
  			)
  		);

  		$this->assertArrayHasKey( 'canada-day', $clean );
  		$this->assertArrayNotHasKey( 'remembrance-day', $clean );
  	}

  	/**
  	 * Asserts an empty label (or a label that is only whitespace) is
  	 * rejected — there is no legitimate reading of an occasion with no
  	 * name.
  	 */
  	public function test_sanitize_occasions_empty_label_is_dropped(): void {
  		$entry          = $this->valid_occasion();
  		$entry['label'] = '   ';

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts `type` only accepts the two enumerated values.
  	 */
  	public function test_sanitize_occasions_invalid_type_is_dropped(): void {
  		$entry         = $this->valid_occasion();
  		$entry['type'] = 'festive';

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts a window missing either bound, or carrying a calendar-invalid
  	 * MM-DD (Feb 30th), is dropped.
  	 */
  	public function test_sanitize_occasions_invalid_window_is_dropped(): void {
  		$missing_bound = $this->valid_occasion();
  		unset( $missing_bound['window']['end_md'] );

  		$bad_calendar_date          = $this->valid_occasion();
  		$bad_calendar_date['window'] = array( 'start_md' => '02-30', 'end_md' => '02-30' );

  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $missing_bound ) ) );
  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $bad_calendar_date ) ) );
  	}

  	/**
  	 * Asserts Feb 29th is accepted as a valid recurring MM-DD — validated
  	 * against a leap year (2024), since this is an ANNUALLY RECURRING date,
  	 * not an instant, so "no such day in a non-leap year" is not a reason
  	 * to reject it outright.
  	 */
  	public function test_sanitize_occasions_leap_day_window_is_accepted(): void {
  		$entry           = $this->valid_occasion();
  		$entry['window'] = array( 'start_md' => '02-29', 'end_md' => '02-29' );

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts a window that crosses the year boundary (start after end,
  	 * e.g. New Year's own 12-27..01-02) is accepted at the sanitizer level
  	 * — the resolver (Task 3) is what interprets the wraparound, not this
  	 * validator, which only checks that both bounds are individually
  	 * well-formed calendar dates.
  	 */
  	public function test_sanitize_occasions_year_boundary_crossing_window_is_accepted(): void {
  		$entry           = $this->valid_occasion();
  		$entry['window'] = array( 'start_md' => '12-27', 'end_md' => '01-02' );

  		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

  		$this->assertArrayHasKey( 'canada-day', $clean );
  	}

  	/**
  	 * Asserts an empty `accent` survives as '' (meaning "use the resolved
  	 * default"), a valid hex is normalised (uppercase/3-digit/no-`#`
  	 * forms all resolve through blueline_sanitize_hex_color()), and a
  	 * non-empty value that is NOT a real hex colour drops the whole entry
  	 * rather than silently coercing it to the default.
  	 */
  	public function test_sanitize_occasions_accent_handling(): void {
  		$empty = $this->valid_occasion();
  		$empty['accent'] = '';
  		$this->assertSame( '', blueline_sanitize_occasions( array( 'canada-day' => $empty ) )['canada-day']['accent'] );

  		$normalised = $this->valid_occasion();
  		$normalised['accent'] = '#ABC';
  		$this->assertSame( '#aabbcc', blueline_sanitize_occasions( array( 'canada-day' => $normalised ) )['canada-day']['accent'] );

  		$invalid = $this->valid_occasion();
  		$invalid['accent'] = 'not-a-colour';
  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $invalid ) ) );
  	}

  	/**
  	 * Asserts `motif` only accepts the five enumerated values.
  	 */
  	public function test_sanitize_occasions_invalid_motif_is_dropped(): void {
  		$entry          = $this->valid_occasion();
  		$entry['motif'] = 'fireworks';

  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $entry ) ) );
  	}

  	/**
  	 * Asserts `mode` only accepts the three enumerated values.
  	 */
  	public function test_sanitize_occasions_invalid_mode_is_dropped(): void {
  		$entry         = $this->valid_occasion();
  		$entry['mode'] = 'sometimes';

  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $entry ) ) );
  	}

  	/**
  	 * Asserts `line` is optional (absent defaults to ''), and that any
  	 * value containing a literal '%' is rejected outright — this value is
  	 * never passed through sprintf(), so there is no legitimate
  	 * placeholder for it to carry at all (design spec §5/§7.1's "no
  	 * placeholders permitted").
  	 */
  	public function test_sanitize_occasions_line_handling(): void {
  		$absent = $this->valid_occasion();
  		unset( $absent['line'] );
  		$this->assertSame( '', blueline_sanitize_occasions( array( 'canada-day' => $absent ) )['canada-day']['line'] );

  		$with_percent = $this->valid_occasion();
  		$with_percent['line'] = 'Save 50%!';
  		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $with_percent ) ) );

  		$normal = $this->valid_occasion();
  		$normal['line'] = '  Happy Canada Day!  ';
  		$this->assertSame( 'Happy Canada Day!', blueline_sanitize_occasions( array( 'canada-day' => $normal ) )['canada-day']['line'] );
  	}

  	/**
  	 * Asserts the three small enumerations blueline_sanitize_occasions()
  	 * validates against are exactly what the design spec's §5/§7.1 model
  	 * declares — pinned independently so a future edit to one of them is a
  	 * deliberate, visible change rather than a silent drift.
  	 */
  	public function test_occasion_enumerations_match_the_design_spec(): void {
  		$this->assertSame( array( 'decorative', 'commemorative' ), blueline_occasion_types() );
  		$this->assertSame( array( 'none', 'maple-leaf', 'poppy', 'snowflake', 'sparkle' ), blueline_occasion_motifs() );
  		$this->assertSame( array( 'auto', 'force_on', 'force_off' ), blueline_occasion_modes() );
  	}
  ```

  Modify `tests/SettingsPageTest.php`: add, alongside its existing requires:

  ```php
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';
  ```

  and add these test methods to the class:

  ```php
  	/**
  	 * Asserts `occasions` survives a programmatic write (no `_tab`), the
  	 * same path `_schema`/`aa_acknowledgements` already rely on.
  	 */
  	public function test_sanitize_callback_lets_occasions_survive_a_programmatic_write(): void {
  		$entry  = array(
  			'id'     => 'canada-day',
  			'label'  => 'Canada Day',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  			'accent' => '',
  			'motif'  => 'maple-leaf',
  			'line'   => '',
  			'mode'   => 'auto',
  		);
  		$output = blueline_settings_sanitize_callback(
  			array( 'occasions' => array( 'canada-day' => $entry ) )
  		);

  		$this->assertSame( $entry, $output['occasions']['canada-day'] );
  	}

  	/**
  	 * Asserts `occasions` is dropped outright from a tab-scoped (form)
  	 * submission — no existing tab's rendered form ever legitimately
  	 * submits it.
  	 */
  	public function test_sanitize_callback_drops_occasions_from_a_form_submission(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'      => 'content',
  				'occasions' => array( 'canada-day' => array( 'anything' => true ) ),
  			)
  		);

  		$this->assertArrayNotHasKey( 'occasions', $output );
  	}

  	/**
  	 * Asserts a malformed `occasions` value is repaired to an empty map
  	 * rather than trusted verbatim, even on a programmatic write.
  	 */
  	public function test_sanitize_callback_validates_occasions_shape(): void {
  		$output = blueline_settings_sanitize_callback(
  			array( 'occasions' => 'not-an-array' )
  		);

  		$this->assertSame( array(), $output['occasions'] );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_sanitize_occasions()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php` (after `blueline_occasion_accent_default()`):

  ```php
  /**
   * The two occasion types the model recognises (design spec §5/§7.1).
   * `commemorative` is validated separately by its own rules elsewhere
   * (Task 5's announcement-severity suppression, and the panel-side "only
   * the poppy motif" restriction 2.1b will add) — this enumeration is only
   * the shape check.
   *
   * @return string[]
   */
  function blueline_occasion_types(): array {
  	return array( 'decorative', 'commemorative' );
  }

  /**
   * The shipped, enumerated motif set (design spec §5/§7.7) — never
   * uploadable, so this list is exhaustive and closed.
   *
   * @return string[]
   */
  function blueline_occasion_motifs(): array {
  	return array( 'none', 'maple-leaf', 'poppy', 'snowflake', 'sparkle' );
  }

  /**
   * The three activation modes (design spec §5/§7.5): `auto` (window-
   * driven), `force_on` (always eligible, regardless of window --
   * "preview it now"), `force_off` (never eligible, regardless of window --
   * "pull it now").
   *
   * @return string[]
   */
  function blueline_occasion_modes(): array {
  	return array( 'auto', 'force_on', 'force_off' );
  }

  /**
   * Whether $value is a well-formed, annually-recurring `MM-DD` calendar
   * date.
   *
   * Validated against 2024 (a leap year) deliberately: an occasion window
   * is a RECURRING annual date, not a single instant, so `02-29` must be
   * accepted as a real recurring day even though it does not exist every
   * year -- Task 3's resolver and Task 6's cron boundary calculation are
   * both what actually decide what happens to a `02-29` window in a
   * non-leap target year, not this shape check.
   *
   * @param mixed $value Candidate value.
   * @return bool
   */
  function blueline_occasion_valid_md( $value ): bool {
  	if ( ! is_string( $value ) || ! preg_match( '/^(\d{2})-(\d{2})$/', $value, $matches ) ) {
  		return false;
  	}

  	return checkdate( (int) $matches[1], (int) $matches[2], 2024 );
  }

  /**
   * Validate and repair a stored `occasions` value.
   *
   * Called from inc/settings/page.php's blueline_settings_sanitize_callback()
   * reserved-key branch -- the same choke point `_schema`/`aa_acknowledgements`
   * already go through, so this runs on every write that actually reaches
   * update_option() for this option (WP-CLI, a direct update_option() call,
   * a future 2.1b panel save), not only ones that pass through wp-admin.
   *
   * A map keyed by occasion id (design spec §5/§7.1: "stored flat inside
   * blueline_settings['occasions'] -- a map keyed by `id`"). Never fatal
   * and, like blueline_sanitize_band_photos() and
   * blueline_sanitize_acknowledgements() before it, drops a malformed
   * entry rather than corrupting the whole map over one bad row: a
   * non-array input, a row that is not itself an array, a row missing any
   * required key, an `id` that disagrees with its own map key, an empty
   * label, an unrecognised `type`/`motif`/`mode`, a structurally invalid
   * (or calendar-invalid) window bound, a non-empty `accent` that is not a
   * real hex colour, or a `line` containing a literal `%` are all dropped
   * individually.
   *
   * @param mixed $value Raw value to validate.
   * @return array<string, array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string}>
   */
  function blueline_sanitize_occasions( $value ): array {
  	if ( ! is_array( $value ) ) {
  		return array();
  	}

  	$clean = array();

  	foreach ( $value as $id => $entry ) {
  		if ( ! is_string( $id ) || '' === $id || ! is_array( $entry ) ) {
  			continue;
  		}

  		if ( ! isset( $entry['id'], $entry['label'], $entry['type'], $entry['window'], $entry['accent'], $entry['motif'], $entry['mode'] ) ) {
  			continue;
  		}

  		if ( ! is_string( $entry['id'] ) || $entry['id'] !== $id ) {
  			continue;
  		}

  		$label = is_string( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : '';
  		if ( '' === $label ) {
  			continue;
  		}

  		if ( ! is_string( $entry['type'] ) || ! in_array( $entry['type'], blueline_occasion_types(), true ) ) {
  			continue;
  		}

  		if ( ! is_array( $entry['window'] )
  			|| ! isset( $entry['window']['start_md'], $entry['window']['end_md'] )
  			|| ! blueline_occasion_valid_md( $entry['window']['start_md'] )
  			|| ! blueline_occasion_valid_md( $entry['window']['end_md'] )
  		) {
  			continue;
  		}

  		if ( ! is_string( $entry['accent'] ) ) {
  			continue;
  		}

  		$accent = '';
  		if ( '' !== $entry['accent'] ) {
  			$accent = blueline_sanitize_hex_color( $entry['accent'] );
  			if ( '' === $accent ) {
  				// Non-empty but not a real hex colour: reject the whole
  				// entry rather than silently coercing it to the default,
  				// matching blueline_sanitize_band_photos()'s "reject, don't
  				// repair" posture for a value the admin actually typed.
  				continue;
  			}
  		}

  		if ( ! is_string( $entry['motif'] ) || ! in_array( $entry['motif'], blueline_occasion_motifs(), true ) ) {
  			continue;
  		}

  		if ( ! is_string( $entry['mode'] ) || ! in_array( $entry['mode'], blueline_occasion_modes(), true ) ) {
  			continue;
  		}

  		$line = '';
  		if ( isset( $entry['line'] ) ) {
  			if ( ! is_string( $entry['line'] ) ) {
  				continue;
  			}

  			$line = sanitize_text_field( $entry['line'] );

  			if ( false !== strpos( $line, '%' ) ) {
  				// No placeholders permitted at all (design spec §5/§7.1):
  				// this value is never passed through sprintf(), so there
  				// is no legitimate conversion spec for it to carry, unlike
  				// the panel's sprintf()-fed content fields
  				// (inc/settings/sanitize.php).
  				continue;
  			}
  		}

  		$clean[ $id ] = array(
  			'id'     => $id,
  			'label'  => $label,
  			'type'   => $entry['type'],
  			'window' => array(
  				'start_md' => $entry['window']['start_md'],
  				'end_md'   => $entry['window']['end_md'],
  			),
  			'accent' => $accent,
  			'motif'  => $entry['motif'],
  			'line'   => $line,
  			'mode'   => $entry['mode'],
  		);
  	}

  	return $clean;
  }
  ```

  Modify `inc/settings/defaults.php`: in `blueline_settings_defaults()`, immediately after the `aa_acknowledgements` comment block (before the array's closing `);`), add:

  ```php
  		// `occasions` (design spec §5) IS listed here, unlike
  		// `aa_acknowledgements` immediately above -- the front-end resolver
  		// (Task 3) needs blueline_settings( 'occasions' ) to return
  		// something. Its own default is a genuinely empty array: the four
  		// shipped presets (blueline_occasion_presets(), Task 2) are a
  		// READ-ONLY catalog for a future admin UI, never pre-populated live
  		// entries -- design spec §5's second ruling.
  		'occasions'                     => array(),
  ```

  Modify `inc/settings/page.php:219`:

  ```php
  const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements', 'occasions' );
  ```

  Modify `blueline_settings_sanitize_callback()`'s reserved-key branch: immediately after the `aa_acknowledgements` branch added in Phase 2.0 (before the `// A programmatic write ...` comment and `$sanitized_reserved = absint( $value );` line), add:

  ```php
  			if ( 'occasions' === $key ) {
  				// A map, not an integer like every other reserved key --
  				// its own validator (inc/occasions.php) drops anything
  				// malformed rather than corrupting the option or crashing a
  				// later reader.
  				$output[ $key ] = blueline_sanitize_occasions( $value );
  				continue;
  			}

  ```

  No `functions.php` change: `inc/occasions.php` is already required, and PHP resolves the new `blueline_sanitize_occasions()` call inside `inc/settings/page.php` at call time (a real settings write), well after every `inc/` file has loaded — the same reasoning Phase 2.0's Task 5 documented for `aa_acknowledgements`.

  No `inc/settings/import.php` change either: unlike `aa_acknowledgements`/`advanced_enabled` (unconditionally discarded there because they are consent, not configuration — see that file's own docblock), `occasions` is ordinary site configuration with no consent semantics, and it is not named in either `unset()` call. An imported payload's `occasions` key reaches `blueline_settings_import_prepare()`'s caller, which runs it through the identical `blueline_settings_sanitize_callback()` reserved-key branch this task just added — so import already re-validates it through the same sanitizer as any other write, exactly as the original spec's §6.8 requires for real configuration. This is a verified consequence of the existing generic mechanism, not a gap.

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter "OccasionsTest|SettingsPageTest|SettingsDefaultsTest|SettingsStoreTest|SchemaFieldCoverageTest"` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php inc/settings/defaults.php inc/settings/page.php tests/OccasionsTest.php tests/SettingsPageTest.php
  git commit -m "Add the Occasion model, blueline_sanitize_occasions(), and reserved-key registration"
  ```

---

### Task 2: The read-only preset catalog

**Files:**
- Modify: `inc/occasions.php` (add `blueline_occasion_presets()`)
- Test: `tests/OccasionsTest.php` (add test methods)

**Interfaces:**
- Consumes: `blueline_sanitize_occasions( $value ): array` (Task 1, used only for a round-trip regression check below).
- Produces: `blueline_occasion_presets(): array`, a map keyed by `canada-day`, `remembrance-day`, `christmas`, `new-year`, each entry in the exact shape Task 1 defined. Never called by any function this plan adds after this task — the resolver (Task 3), the cron boundary calculator (Task 6), and the editor-parity filter (Task 7) all read `blueline_settings( 'occasions' )` only.

- [ ] **Step 1: Write the failing test**

  Add to `tests/OccasionsTest.php`:

  ```php
  	/* --------------------------------------------------------- presets */

  	/**
  	 * Asserts the catalog has exactly the four documented presets, each
  	 * with `mode => 'auto'` (never pre-activated) and no `enabled` key at
  	 * all -- design spec §5's second ruling: "the model has no `enabled`
  	 * field".
  	 */
  	public function test_presets_are_the_four_documented_occasions(): void {
  		$presets = blueline_occasion_presets();

  		$this->assertSame( array( 'canada-day', 'remembrance-day', 'christmas', 'new-year' ), array_keys( $presets ) );

  		foreach ( $presets as $id => $preset ) {
  			$this->assertSame( $id, $preset['id'] );
  			$this->assertSame( 'auto', $preset['mode'] );
  			$this->assertArrayNotHasKey( 'enabled', $preset );
  		}
  	}

  	/**
  	 * Pins each preset's type/window/motif to the design spec's own
  	 * choices, so a future edit to one is a deliberate, visible change.
  	 */
  	public function test_presets_match_the_design_specs_choices(): void {
  		$presets = blueline_occasion_presets();

  		$this->assertSame( 'decorative', $presets['canada-day']['type'] );
  		$this->assertSame( array( 'start_md' => '07-01', 'end_md' => '07-01' ), $presets['canada-day']['window'] );
  		$this->assertSame( 'maple-leaf', $presets['canada-day']['motif'] );

  		$this->assertSame( 'commemorative', $presets['remembrance-day']['type'] );
  		$this->assertSame( array( 'start_md' => '11-11', 'end_md' => '11-11' ), $presets['remembrance-day']['window'] );
  		$this->assertSame( 'poppy', $presets['remembrance-day']['motif'] );

  		$this->assertSame( 'decorative', $presets['christmas']['type'] );
  		$this->assertSame( array( 'start_md' => '12-01', 'end_md' => '12-26' ), $presets['christmas']['window'] );
  		$this->assertSame( 'snowflake', $presets['christmas']['motif'] );

  		$this->assertSame( 'decorative', $presets['new-year']['type'] );
  		// Crosses the year boundary deliberately -- this is the case Task
  		// 3's resolver and Task 6's cron boundary calculation both have to
  		// handle correctly, not hypothetically.
  		$this->assertSame( array( 'start_md' => '12-27', 'end_md' => '01-02' ), $presets['new-year']['window'] );
  		$this->assertSame( 'sparkle', $presets['new-year']['motif'] );
  	}

  	/**
  	 * Every preset must itself be a well-formed Occasion by
  	 * blueline_sanitize_occasions()'s own rules -- a regression guard: if
  	 * the sanitizer's rules ever tighten in a way a shipped preset would
  	 * fail, this fails loudly instead of shipping a preset that silently
  	 * can't be saved once a future admin UI copies it into the real
  	 * stored array.
  	 */
  	public function test_presets_round_trip_through_the_sanitizer_unchanged(): void {
  		$presets = blueline_occasion_presets();

  		$this->assertSame( $presets, blueline_sanitize_occasions( $presets ) );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_occasion_presets()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php`:

  ```php
  /**
   * The four shipped occasion templates -- design spec §5's second ruling:
   * a READ-ONLY catalog for a future admin UI's "add from preset"
   * affordance (2.1b), never pre-populated into the real stored
   * `occasions` array. blueline_resolve_active_occasion() (Task 3) never
   * calls this function; nothing here is "live" until something else (a
   * future panel save, or `wp blueline settings occasions` in Phase 2.2)
   * copies one of these into blueline_settings( 'occasions' )'s real
   * stored value.
   *
   * Every preset ships `mode => 'auto'`, deliberately: there is no
   * `enabled` field in the model at all (an `auto` entry sitting in the
   * REAL stored array during its calendar window would activate whether
   * or not an admin ever opened the panel -- the model has no separate
   * on/off switch, by design), so "shipped but inert" is achieved entirely
   * by these presets living here instead of in the real stored array, not
   * by any flag on the entries themselves.
   *
   * Every `accent` is '' (use the resolved default): 2.1a ships no
   * contrast-vetted festive palette, and an admin choosing one is exactly
   * 2.1b's job.
   *
   * @return array<string, array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string}>
   */
  function blueline_occasion_presets(): array {
  	return array(
  		'canada-day'      => array(
  			'id'     => 'canada-day',
  			'label'  => 'Canada Day',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  			'accent' => '',
  			'motif'  => 'maple-leaf',
  			'line'   => '',
  			'mode'   => 'auto',
  		),
  		'remembrance-day' => array(
  			'id'     => 'remembrance-day',
  			'label'  => 'Remembrance Day',
  			'type'   => 'commemorative',
  			'window' => array( 'start_md' => '11-11', 'end_md' => '11-11' ),
  			'accent' => '',
  			'motif'  => 'poppy',
  			'line'   => 'Lest we forget.',
  			'mode'   => 'auto',
  		),
  		'christmas'       => array(
  			'id'     => 'christmas',
  			'label'  => 'Christmas',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '12-01', 'end_md' => '12-26' ),
  			'accent' => '',
  			'motif'  => 'snowflake',
  			'line'   => '',
  			'mode'   => 'auto',
  		),
  		// Crosses the year boundary (start_md > end_md) by design -- see
  		// Task 3's blueline_occasion_window_contains() and Task 6's
  		// blueline_occasion_next_occurrence_timestamp() for the two places
  		// that must, and do, handle this correctly.
  		'new-year'        => array(
  			'id'     => 'new-year',
  			'label'  => 'New Year',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '12-27', 'end_md' => '01-02' ),
  			'accent' => '',
  			'motif'  => 'sparkle',
  			'line'   => '',
  			'mode'   => 'auto',
  		),
  	);
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php tests/OccasionsTest.php
  git commit -m "Add blueline_occasion_presets(), the read-only preset catalog"
  ```

---

### Task 3: Window matching, precedence, and the resolution engine

**Files:**
- Modify: `inc/occasions.php` (add `blueline_occasion_today_md()`, `blueline_occasion_window_contains()`, `blueline_occasion_compare()`, `blueline_resolve_active_occasion()`)
- Modify: `inc/settings/acknowledgements.php` (add `blueline_stored_acknowledgements()`)
- Test: `tests/OccasionsResolverTest.php` (new)

**Interfaces:**
- Consumes: `blueline_settings( 'occasions' ): array` (Task 1's shape, via `inc/settings/store.php`, pre-existing), `blueline_settings_inputs_hash(): string` (`inc/settings/validation.php`, pre-existing), `blueline_acknowledgement_covers( array $acknowledgements, string $scope, string $rule_id, string $value, string $current_inputs_hash ): bool` (`inc/settings/acknowledgements.php`, pre-existing), `blueline_record_acknowledgement( array $acknowledgements, string $scope, string $rule_id, string $value, float $ratio, string $inputs_hash, int $user_id ): array` (`inc/settings/acknowledgements.php`, pre-existing, used only by this task's own tests to seed a fixture), `blueline_contrast_ratio( string $a, string $b ): float`, `blueline_contrast_threshold( string $which ): float`, `BLUELINE_TOKEN_INK` (`inc/team-colors.php`, pre-existing), `blueline_occasion_accent_default(): string` (Phase 2.0, `inc/occasions.php`).
- Produces: `blueline_occasion_today_md( int $timestamp ): string`, `blueline_occasion_window_contains( string $start_md, string $end_md, string $today_md ): bool`, `blueline_occasion_compare( array $a, array $b ): int`, `blueline_resolve_active_occasion( ?int $now_override = null ): ?array` (the returned array is a Task-1-shaped Occasion plus one extra key, `resolved_accent` — a validated `#rrggbb` string), and `blueline_stored_acknowledgements(): array` (`inc/settings/acknowledgements.php`) — the exact map `blueline_acknowledgement_covers()`'s first parameter expects, read directly since `aa_acknowledgements` is deliberately absent from `blueline_settings()`'s return.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsResolverTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by blueline_settings_inputs_hash().
  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers the pure window/precedence helpers and blueline_resolve_active_occasion()
   * itself, including the AA-override integration (design spec §4.5).
   *
   * Seeds `blueline_settings( 'occasions' )` via a direct update_option()
   * call with NO `_tab` -- exactly tests/SettingsLinksTest.php's own
   * precedent for "resolver-only" tests -- deliberately WITHOUT requiring
   * inc/settings/page.php, so the value is stored exactly as given, never
   * run through blueline_sanitize_occasions() (Task 1's own test file
   * already covers that pipeline).
   */
  final class OccasionsResolverTest extends TestCase {

  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  	}

  	/**
  	 * A minimal, valid occasion fixture, reused across several tests.
  	 *
  	 * @param array<string, mixed> $overrides Keys to override.
  	 * @return array<string, mixed>
  	 */
  	private function occasion( array $overrides = array() ): array {
  		return array_merge(
  			array(
  				'id'     => 'test-occasion',
  				'label'  => 'Test Occasion',
  				'type'   => 'decorative',
  				'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  				'accent' => '',
  				'motif'  => 'none',
  				'line'   => '',
  				'mode'   => 'auto',
  			),
  			$overrides
  		);
  	}

  	/* ---------------------------------------------------------- today_md */

  	/**
  	 * Asserts today's calendar date is computed in SITE timezone, not UTC
  	 * -- a timestamp just after UTC midnight on the 2nd is still the 1st
  	 * in a timezone west of UTC.
  	 */
  	public function test_today_md_uses_site_timezone(): void {
  		$state             = &blueline_test_state();
  		$state['timezone'] = 'America/Vancouver'; // UTC-8/-7.

  		// 2026-03-02 00:30 UTC -- still 2026-03-01 evening in Vancouver.
  		$now = ( new DateTimeImmutable( '2026-03-02T00:30:00+00:00' ) )->getTimestamp();

  		$this->assertSame( '03-01', blueline_occasion_today_md( $now ) );
  	}

  	/* ------------------------------------------------------ window_contains */

  	/**
  	 * Asserts a normal (non-wrapping) window is inclusive of both bounds
  	 * and excludes a date just outside either.
  	 */
  	public function test_window_contains_normal_range_is_inclusive(): void {
  		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-01' ) );
  		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-26' ) );
  		$this->assertTrue( blueline_occasion_window_contains( '12-01', '12-26', '12-15' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '12-01', '12-26', '11-30' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '12-01', '12-26', '12-27' ) );
  	}

  	/**
  	 * Asserts a single-day window (start === end) matches exactly that
  	 * one day.
  	 */
  	public function test_window_contains_single_day(): void {
  		$this->assertTrue( blueline_occasion_window_contains( '07-01', '07-01', '07-01' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '07-01', '07-01', '07-02' ) );
  	}

  	/**
  	 * Asserts a year-boundary-crossing window (New Year's own
  	 * 12-27..01-02) matches both sides of the wraparound and excludes a
  	 * date in between.
  	 */
  	public function test_window_contains_year_boundary_wraparound(): void {
  		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '12-27' ) );
  		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '12-31' ) );
  		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '01-01' ) );
  		$this->assertTrue( blueline_occasion_window_contains( '12-27', '01-02', '01-02' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '06-15' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '12-26' ) );
  		$this->assertFalse( blueline_occasion_window_contains( '12-27', '01-02', '01-03' ) );
  	}

  	/* --------------------------------------------------------- compare */

  	/**
  	 * Asserts force_on beats auto, regardless of type or window.
  	 */
  	public function test_compare_force_on_beats_auto(): void {
  		$forced = $this->occasion( array( 'id' => 'a', 'mode' => 'force_on' ) );
  		$auto   = $this->occasion( array( 'id' => 'b', 'mode' => 'auto', 'type' => 'commemorative' ) );

  		$ranked = array( $auto, $forced );
  		usort( $ranked, 'blueline_occasion_compare' );

  		$this->assertSame( 'a', $ranked[0]['id'] );
  	}

  	/**
  	 * Asserts commemorative beats decorative among occasions of the same
  	 * mode tier.
  	 */
  	public function test_compare_commemorative_beats_decorative(): void {
  		$commemorative = $this->occasion( array( 'id' => 'a', 'type' => 'commemorative' ) );
  		$decorative    = $this->occasion( array( 'id' => 'b', 'type' => 'decorative' ) );

  		$ranked = array( $decorative, $commemorative );
  		usort( $ranked, 'blueline_occasion_compare' );

  		$this->assertSame( 'a', $ranked[0]['id'] );
  	}

  	/**
  	 * Asserts, among occasions tied on mode tier and type, the earliest
  	 * start_md wins.
  	 */
  	public function test_compare_earliest_start_wins(): void {
  		$later   = $this->occasion( array( 'id' => 'a', 'window' => array( 'start_md' => '12-01', 'end_md' => '12-26' ) ) );
  		$earlier = $this->occasion( array( 'id' => 'b', 'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ) ) );

  		$ranked = array( $later, $earlier );
  		usort( $ranked, 'blueline_occasion_compare' );

  		$this->assertSame( 'b', $ranked[0]['id'] );
  	}

  	/**
  	 * Asserts, among occasions tied on every other criterion, id ascending
  	 * is the final, deterministic tiebreak.
  	 */
  	public function test_compare_id_is_the_final_tiebreak(): void {
  		$z = $this->occasion( array( 'id' => 'zebra' ) );
  		$a = $this->occasion( array( 'id' => 'alpha' ) );

  		$ranked = array( $z, $a );
  		usort( $ranked, 'blueline_occasion_compare' );

  		$this->assertSame( 'alpha', $ranked[0]['id'] );
  	}

  	/* ------------------------------------------------ resolve_active_occasion */

  	/**
  	 * Asserts nothing stored resolves to null.
  	 */
  	public function test_resolver_returns_null_when_nothing_stored(): void {
  		$this->assertNull( blueline_resolve_active_occasion( time() ) );
  	}

  	/**
  	 * Asserts an auto-mode occasion whose window does not cover today
  	 * resolves to null.
  	 */
  	public function test_resolver_returns_null_when_no_window_matches(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => $this->occasion( array( 'id' => 'canada-day', 'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ) ) ),
  				),
  			)
  		);

  		$now = ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp();

  		$this->assertNull( blueline_resolve_active_occasion( $now ) );
  	}

  	/**
  	 * Asserts an auto-mode occasion whose window DOES cover today
  	 * resolves, with its own accent ('' here) resolved to the real
  	 * default.
  	 */
  	public function test_resolver_returns_a_matching_auto_occasion(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => $this->occasion( array( 'id' => 'canada-day', 'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ) ) ),
  				),
  			)
  		);

  		$now      = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();
  		$resolved = blueline_resolve_active_occasion( $now );

  		$this->assertNotNull( $resolved );
  		$this->assertSame( 'canada-day', $resolved['id'] );
  		$this->assertSame( blueline_occasion_accent_default(), $resolved['resolved_accent'] );
  	}

  	/**
  	 * Asserts force_on activates regardless of the window.
  	 */
  	public function test_resolver_force_on_ignores_the_window(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => $this->occasion( array( 'id' => 'canada-day', 'mode' => 'force_on', 'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ) ) ),
  				),
  			)
  		);

  		$now = ( new DateTimeImmutable( '2026-03-15 12:00:00', wp_timezone() ) )->getTimestamp();

  		$this->assertSame( 'canada-day', blueline_resolve_active_occasion( $now )['id'] );
  	}

  	/**
  	 * Asserts force_off is never eligible, even during its own window.
  	 */
  	public function test_resolver_force_off_is_never_eligible(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => $this->occasion( array( 'id' => 'canada-day', 'mode' => 'force_off', 'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ) ) ),
  				),
  			)
  		);

  		$now = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();

  		$this->assertNull( blueline_resolve_active_occasion( $now ) );
  	}

  	/**
  	 * Asserts that, of two occasions both matching today's window, the
  	 * commemorative one wins -- design spec §5/§7.6's "suppresses any
  	 * decorative occasion".
  	 */
  	public function test_resolver_commemorative_beats_decorative_when_both_match(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'decorative-one'   => $this->occasion( array( 'id' => 'decorative-one', 'type' => 'decorative', 'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ) ) ),
  					'commemorative-one' => $this->occasion( array( 'id' => 'commemorative-one', 'type' => 'commemorative', 'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ) ) ),
  				),
  			)
  		);

  		$now = ( new DateTimeImmutable( '2026-07-01 12:00:00', wp_timezone() ) )->getTimestamp();

  		$this->assertSame( 'commemorative-one', blueline_resolve_active_occasion( $now )['id'] );
  	}

  	/**
  	 * The fail-closed case (design spec §4.5): an occasion whose accent
  	 * fails contrast against BLUELINE_TOKEN_INK, with NO acknowledgement
  	 * on record, must not activate.
  	 */
  	public function test_resolver_falls_closed_on_an_unacknowledged_failing_accent(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'failing' => $this->occasion( array( 'id' => 'failing', 'mode' => 'force_on', 'accent' => '#274a63' ) ),
  				),
  			)
  		);

  		// Sanity-check the fixture's premise before trusting the assertion below.
  		$this->assertLessThan( blueline_contrast_threshold( 'body' ), blueline_contrast_ratio( BLUELINE_TOKEN_INK, '#274a63' ) );

  		$this->assertNull( blueline_resolve_active_occasion( time() ) );
  	}

  	/**
  	 * The fail-open case (design spec §4.5): the identical failing accent,
  	 * but with a live acknowledgement on record whose rule id, value, and
  	 * inputs hash all match current reality -- must activate.
  	 */
  	public function test_resolver_falls_open_on_an_acknowledged_failing_accent(): void {
  		$hash             = blueline_settings_inputs_hash();
  		$acknowledgements = blueline_record_acknowledgement(
  			array(),
  			'occasion:failing',
  			'ink-on-occasion-accent',
  			'#274a63',
  			blueline_contrast_ratio( BLUELINE_TOKEN_INK, '#274a63' ),
  			$hash,
  			1
  		);

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(
  					'failing' => $this->occasion( array( 'id' => 'failing', 'mode' => 'force_on', 'accent' => '#274a63' ) ),
  				),
  				'aa_acknowledgements' => $acknowledgements,
  			)
  		);

  		$resolved = blueline_resolve_active_occasion( time() );

  		$this->assertNotNull( $resolved );
  		$this->assertSame( 'failing', $resolved['id'] );
  		$this->assertSame( '#274a63', $resolved['resolved_accent'] );
  	}

  	/**
  	 * Asserts an unacknowledged failing candidate is skipped, falling
  	 * through to the next-best candidate, rather than the whole resolution
  	 * returning null just because the FIRST candidate failed.
  	 */
  	public function test_resolver_falls_through_to_the_next_candidate_past_an_unacknowledged_failure(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					// Ranks first (commemorative beats decorative) but fails contrast, unacknowledged.
  					'failing-commemorative' => $this->occasion( array( 'id' => 'failing-commemorative', 'mode' => 'force_on', 'type' => 'commemorative', 'accent' => '#274a63' ) ),
  					// Ranks second, but passes outright.
  					'passing-decorative'    => $this->occasion( array( 'id' => 'passing-decorative', 'mode' => 'force_on', 'type' => 'decorative' ) ),
  				),
  			)
  		);

  		$resolved = blueline_resolve_active_occasion( time() );

  		$this->assertNotNull( $resolved );
  		$this->assertSame( 'passing-decorative', $resolved['id'] );
  	}

  	/**
  	 * Asserts blueline_resolve_active_occasion()'s own source never calls
  	 * blueline_occasion_presets() -- design spec §5's second ruling: the
  	 * preset catalog has no bearing on what is actually live.
  	 */
  	public function test_resolver_source_never_reads_the_preset_catalog(): void {
  		$source = file_get_contents( __DIR__ . '/../inc/occasions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

  		if ( ! preg_match( '/function blueline_resolve_active_occasion\([^)]*\)\s*:\s*\?array\s*\{/', $source, $start, PREG_OFFSET_CAPTURE ) ) {
  			$this->fail( 'could not locate blueline_resolve_active_occasion()\'s definition in inc/occasions.php' );
  		}

  		$offset = $start[0][1];
  		$depth  = 0;
  		$body   = '';

  		for ( $i = $offset; $i < strlen( $source ); $i++ ) {
  			$char = $source[ $i ];

  			if ( '{' === $char ) {
  				++$depth;
  			}
  			if ( '}' === $char ) {
  				--$depth;
  				if ( 0 === $depth ) {
  					$body = substr( $source, $offset, $i - $offset + 1 );
  					break;
  				}
  			}
  		}

  		$this->assertNotSame( '', $body, 'failed to extract the function body' );
  		$this->assertStringNotContainsString( 'blueline_occasion_presets', $body );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsResolverTest` — Expected: FAIL with "Call to undefined function blueline_occasion_today_md()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/settings/acknowledgements.php`:

  ```php
  /**
   * The `aa_acknowledgements` map as currently stored.
   *
   * Reads get_option() directly rather than blueline_settings(
   * 'aa_acknowledgements' ): blueline_settings()'s returned key set is
   * exactly blueline_settings_defaults()'s key set (see that function's own
   * docblock), and `aa_acknowledgements` is deliberately excluded from it --
   * so that accessor can never return it, by design.
   *
   * @return array<string, array<string, mixed>>
   */
  function blueline_stored_acknowledgements(): array {
  	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
  	$stored = is_array( $stored ) ? $stored : array();

  	return is_array( $stored['aa_acknowledgements'] ?? null ) ? $stored['aa_acknowledgements'] : array();
  }
  ```

  Append to `inc/occasions.php`:

  ```php
  /**
   * Today's calendar date, in SITE timezone (not UTC), as 'MM-DD' --
   * design spec §5/§7.5: "Dates compare in site timezone ... not UTC."
   *
   * @param int $timestamp Unix timestamp.
   * @return string
   */
  function blueline_occasion_today_md( int $timestamp ): string {
  	return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( wp_timezone() )->format( 'm-d' );
  }

  /**
   * Whether $today_md falls within the inclusive, annually-recurring
   * window [$start_md, $end_md].
   *
   * Handles a window that crosses the year boundary (start_md > end_md,
   * e.g. New Year's own 12-27..01-02 -- blueline_occasion_presets()'s
   * `new-year` entry forces this case to be real, not hypothetical) by
   * treating it as "today >= start OR today <= end" instead of the normal
   * "start <= today <= end". String comparison is safe here because every
   * `MM-DD` value is exactly two zero-padded two-digit fields, which sort
   * identically to calendar order.
   *
   * @param string $start_md 'MM-DD'.
   * @param string $end_md   'MM-DD'.
   * @param string $today_md 'MM-DD'.
   * @return bool
   */
  function blueline_occasion_window_contains( string $start_md, string $end_md, string $today_md ): bool {
  	if ( $start_md <= $end_md ) {
  		return $today_md >= $start_md && $today_md <= $end_md;
  	}

  	return $today_md >= $start_md || $today_md <= $end_md;
  }

  /**
   * Precedence comparator for usort(): `force_on` beats `auto`;
   * `commemorative` beats `decorative`; then earliest `start_md`; then
   * `id` ascending -- design spec §5/§7.5, in that exact order, so the
   * winner among several eligible candidates is always deterministic.
   *
   * @param array<string, mixed> $a One Occasion.
   * @param array<string, mixed> $b Another Occasion.
   * @return int
   */
  function blueline_occasion_compare( array $a, array $b ): int {
  	$mode_rank = static function ( array $occasion ): int {
  		return 'force_on' === ( $occasion['mode'] ?? '' ) ? 0 : 1;
  	};

  	if ( $mode_rank( $a ) !== $mode_rank( $b ) ) {
  		return $mode_rank( $a ) <=> $mode_rank( $b );
  	}

  	$type_rank = static function ( array $occasion ): int {
  		return 'commemorative' === ( $occasion['type'] ?? '' ) ? 0 : 1;
  	};

  	if ( $type_rank( $a ) !== $type_rank( $b ) ) {
  		return $type_rank( $a ) <=> $type_rank( $b );
  	}

  	$start_a = $a['window']['start_md'] ?? '';
  	$start_b = $b['window']['start_md'] ?? '';

  	if ( $start_a !== $start_b ) {
  		return $start_a <=> $start_b;
  	}

  	return ( $a['id'] ?? '' ) <=> ( $b['id'] ?? '' );
  }

  /**
   * The one active occasion right now, or null.
   *
   * Reads ONLY blueline_settings( 'occasions' ) -- never
   * blueline_occasion_presets(), which is a read-only catalog with no
   * bearing on what is actually live (design spec §5's second ruling; see
   * tests/OccasionsResolverTest.php's own source-scan test for the
   * enforcement).
   *
   * Eligibility: `force_off` is never eligible, regardless of window.
   * `force_on` is always eligible, regardless of window. `auto` (or an
   * unset mode) is eligible only while blueline_occasion_today_md()
   * currently falls inside its window.
   *
   * Among eligible candidates, blueline_occasion_compare() orders by
   * precedence. Each candidate is then checked, in that order, against the
   * AA-override mechanism (design spec §4.5): resolve its effective accent
   * (its own `accent`, or blueline_occasion_accent_default() when empty),
   * compute that accent's real contrast ratio against BLUELINE_TOKEN_INK,
   * and -- only if it fails blueline_contrast_threshold( 'body' ) --
   * require a live, hash-matching acknowledgement scoped to
   * `occasion:{id}` (blueline_acknowledgement_covers()) before accepting
   * it. A candidate that fails this gate is skipped entirely, falling
   * through to the next-best candidate; the first candidate that either
   * passes contrast outright or is validly acknowledged wins. null if none
   * does (including when nothing is stored at all).
   *
   * @param int|null $now_override Unix timestamp to evaluate against;
   *                                defaults to the current time. Tests pass
   *                                this so an assertion about a window
   *                                keeps meaning the same thing later.
   * @return array{id:string, label:string, type:string, window:array{start_md:string, end_md:string}, accent:string, motif:string, line:string, mode:string, resolved_accent:string}|null
   */
  function blueline_resolve_active_occasion( ?int $now_override = null ): ?array {
  	$now      = $now_override ?? time();
  	$today_md = blueline_occasion_today_md( $now );

  	$occasions = blueline_settings( 'occasions' );
  	$occasions = is_array( $occasions ) ? $occasions : array();

  	$candidates = array();

  	foreach ( $occasions as $occasion ) {
  		if ( ! is_array( $occasion ) ) {
  			continue;
  		}

  		$mode = $occasion['mode'] ?? 'auto';

  		if ( 'force_off' === $mode ) {
  			continue;
  		}

  		if ( 'force_on' !== $mode ) {
  			$window = $occasion['window'] ?? array();
  			$start  = $window['start_md'] ?? '';
  			$end    = $window['end_md'] ?? '';

  			if ( ! blueline_occasion_window_contains( $start, $end, $today_md ) ) {
  				continue;
  			}
  		}

  		$candidates[] = $occasion;
  	}

  	if ( array() === $candidates ) {
  		return null;
  	}

  	usort( $candidates, 'blueline_occasion_compare' );

  	$inputs_hash      = blueline_settings_inputs_hash();
  	$acknowledgements = blueline_stored_acknowledgements();

  	foreach ( $candidates as $candidate ) {
  		$accent = '' !== ( $candidate['accent'] ?? '' ) ? $candidate['accent'] : blueline_occasion_accent_default();

  		if ( '' === $accent ) {
  			// The default itself could not be resolved (Phase 2.0's own
  			// blueline_occasion_accent_default() already logs why) --
  			// nothing to apply for this candidate. Skip, never fatal.
  			continue;
  		}

  		$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $accent );
  		$passes = $ratio >= blueline_contrast_threshold( 'body' );

  		if ( ! $passes ) {
  			$covers = blueline_acknowledgement_covers(
  				$acknowledgements,
  				'occasion:' . $candidate['id'],
  				'ink-on-occasion-accent',
  				$accent,
  				$inputs_hash
  			);

  			if ( ! $covers ) {
  				continue; // Unacknowledged failure: fail closed, try the next candidate.
  			}
  		}

  		$candidate['resolved_accent'] = $accent;

  		return $candidate;
  	}

  	return null;
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter "OccasionsResolverTest|SettingsAcknowledgementsTest"` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php inc/settings/acknowledgements.php tests/OccasionsResolverTest.php
  git commit -m "Add the occasion resolution engine: window matching, precedence, and the AA-override gate"
  ```

---

### Task 4: Motifs — the inline SVG set

**Files:**
- Modify: `inc/occasions.php` (add `blueline_render_occasion_motif_*()` + `blueline_render_occasion_motif()`)
- Test: `tests/OccasionsMotifsTest.php` (new)

**Interfaces:**
- Consumes: `blueline_occasion_motifs(): array` (Task 1), `BLUELINE_TOKEN_INK` (`inc/team-colors.php`, pre-existing).
- Produces: `blueline_render_occasion_motif_maple_leaf(): void`, `blueline_render_occasion_motif_poppy(): void`, `blueline_render_occasion_motif_snowflake(): void`, `blueline_render_occasion_motif_sparkle(): void`, and the dispatcher `blueline_render_occasion_motif( string $motif ): void`. Later tasks in this plan do not call these directly — they exist for a future template (2.1b, or a follow-up wiring task outside this plan's scope) to call once an occasion is resolved-active.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsMotifsTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers the shipped motif SVG set (design spec §5/§7.7): static,
   * enumerated, decorative motifs aria-hidden, the poppy carrying a real
   * accessible name instead.
   */
  final class OccasionsMotifsTest extends TestCase {

  	/**
  	 * Capture a render function's printed output.
  	 *
  	 * @param callable $render A no-argument render function.
  	 * @return string
  	 */
  	private function captured( callable $render ): string {
  		ob_start();
  		$render();
  		return (string) ob_get_clean();
  	}

  	/**
  	 * Asserts 'none' and an unrecognised motif name both render nothing.
  	 */
  	public function test_none_and_unknown_render_nothing(): void {
  		$this->assertSame( '', $this->captured( static fn() => blueline_render_occasion_motif( 'none' ) ) );
  		$this->assertSame( '', $this->captured( static fn() => blueline_render_occasion_motif( 'fireworks' ) ) );
  	}

  	/**
  	 * Asserts each of the three purely decorative motifs renders an
  	 * aria-hidden, non-focusable inline <svg>.
  	 */
  	public function test_decorative_motifs_are_aria_hidden_and_not_focusable(): void {
  		foreach ( array( 'maple-leaf', 'snowflake', 'sparkle' ) as $motif ) {
  			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

  			$this->assertStringContainsString( '<svg', $markup, "$motif did not render an <svg>" );
  			$this->assertStringContainsString( 'aria-hidden="true"', $markup, "$motif is missing aria-hidden" );
  			$this->assertStringContainsString( 'focusable="false"', $markup, "$motif is missing focusable=\"false\"" );
  			$this->assertStringContainsString( "bl-occasion-motif--$motif", $markup, "$motif is missing its own modifier class" );
  		}
  	}

  	/**
  	 * Asserts the poppy is NOT aria-hidden and carries a real accessible
  	 * name via a <title> element -- design spec §5/§7.7: "the poppy
  	 * carries an accessible name."
  	 */
  	public function test_poppy_carries_an_accessible_name(): void {
  		$markup = $this->captured( 'blueline_render_occasion_motif_poppy' );

  		$this->assertStringContainsString( '<svg', $markup );
  		$this->assertStringNotContainsString( 'aria-hidden', $markup );
  		$this->assertStringContainsString( 'role="img"', $markup );
  		$this->assertMatchesRegularExpression( '#<title>[^<]+</title>#', $markup );
  	}

  	/**
  	 * Asserts none of the four real motifs contains any animation --
  	 * design spec §5/§7.7: "static only, no animation" -- checked as the
  	 * absence of the element/attribute names that would introduce motion.
  	 */
  	public function test_motifs_are_static_no_animation(): void {
  		foreach ( array( 'maple-leaf', 'poppy', 'snowflake', 'sparkle' ) as $motif ) {
  			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

  			foreach ( array( '<animate', '<animateTransform', '<animateMotion', 'style="animation' ) as $forbidden ) {
  				$this->assertStringNotContainsString( $forbidden, $markup, "$motif must not animate" );
  			}
  		}
  	}

  	/**
  	 * Asserts the dispatcher's branches match blueline_occasion_motifs()'s
  	 * enumeration exactly -- every real motif (excluding 'none') renders
  	 * something, and there is no motif in the enum this dispatcher forgot.
  	 */
  	public function test_dispatcher_covers_every_enumerated_motif(): void {
  		foreach ( blueline_occasion_motifs() as $motif ) {
  			if ( 'none' === $motif ) {
  				continue;
  			}

  			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

  			$this->assertNotSame( '', $markup, "the dispatcher has no branch for '$motif'" );
  		}
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsMotifsTest` — Expected: FAIL with "Call to undefined function blueline_render_occasion_motif()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php`:

  ```php
  /**
   * The maple leaf motif (Canada Day). Purely decorative: aria-hidden, no
   * text alternative needed -- matches the existing house style
   * (inc/template-tags.php's blueline_leaf_mark(), inc/homepage-modules.php's
   * blueline_render_faceoff_rings()): `stroke`/`fill="currentColor"` so CSS
   * (specifically --bl-occasion-accent, once a template applies it as the
   * motif's colour) controls the rendered colour, not this markup.
   *
   * @return void
   */
  function blueline_render_occasion_motif_maple_leaf(): void {
  	?>
  	<svg class="bl-occasion-motif bl-occasion-motif--maple-leaf" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 3l4 9 9-4-3 9 8 5-9 3 2 9-9-4-2 9-2-9-9 4 2-9-9-3 8-5-3-9 9 4Z"/></svg>
  	<?php
  }

  /**
   * The poppy motif (Remembrance Day). NOT aria-hidden: design spec
   * §5/§7.7 requires it carry a real accessible name, so it gets a
   * `<title>` (announced by assistive technology as the element's
   * accessible name for a `role="img"` SVG) instead of the `aria-hidden`
   * every other motif here uses.
   *
   * @return void
   */
  function blueline_render_occasion_motif_poppy(): void {
  	?>
  	<svg class="bl-occasion-motif bl-occasion-motif--poppy" viewBox="0 0 48 48" role="img" focusable="false"><title><?php esc_html_e( 'Remembrance poppy', 'blueline' ); ?></title><path fill="currentColor" d="M24 22c-4-6-12-8-14-2-2 6 6 10 14 6 8 4 16 0 14-6-2-6-10-4-14 2Z"/><path fill="currentColor" d="M24 26c-2 6-8 12-4 16 4 4 8-4 4-10-4-4-4-2 0-6Z"/><circle cx="24" cy="24" r="4" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/></svg>
  	<?php
  }

  /**
   * The snowflake motif (Christmas). Purely decorative: aria-hidden, no
   * text alternative needed.
   *
   * @return void
   */
  function blueline_render_occasion_motif_snowflake(): void {
  	?>
  	<svg class="bl-occasion-motif bl-occasion-motif--snowflake" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="24" y1="4" x2="24" y2="44"></line><line x1="4" y1="24" x2="44" y2="24"></line><line x1="10" y1="10" x2="38" y2="38"></line><line x1="38" y1="10" x2="10" y2="38"></line></g></svg>
  	<?php
  }

  /**
   * The sparkle motif (New Year). Purely decorative: aria-hidden, no text
   * alternative needed.
   *
   * @return void
   */
  function blueline_render_occasion_motif_sparkle(): void {
  	?>
  	<svg class="bl-occasion-motif bl-occasion-motif--sparkle" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 2c1 8 4 15 9 20 5 5 12 8 20 9-8 1-15 4-20 9-5 5-8 12-9 20-1-8-4-15-9-20-5-5-12-8-20-9 8-1 15-4 20-9 5-5 8-12 9-20Z"/></svg>
  	<?php
  }

  /**
   * Render the named motif, or nothing for 'none' or an unrecognised name
   * -- never fatal, matching every other read path in this file.
   *
   * @param string $motif One of blueline_occasion_motifs().
   * @return void
   */
  function blueline_render_occasion_motif( string $motif ): void {
  	switch ( $motif ) {
  		case 'maple-leaf':
  			blueline_render_occasion_motif_maple_leaf();
  			break;
  		case 'poppy':
  			blueline_render_occasion_motif_poppy();
  			break;
  		case 'snowflake':
  			blueline_render_occasion_motif_snowflake();
  			break;
  		case 'sparkle':
  			blueline_render_occasion_motif_sparkle();
  			break;
  		case 'none':
  		default:
  			break;
  	}
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsMotifsTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php tests/OccasionsMotifsTest.php
  git commit -m "Add the shipped occasion motif SVG set"
  ```

---

### Task 5: Commemorative announcement-severity suppression

**Files:**
- Modify: `inc/announcement.php` (`blueline_announcement_severity()`)
- Modify: `inc/occasions.php` (add `blueline_occasion_suppress_urgent_announcement()` + its `add_filter()`)
- Modify: `tests/AnnouncementTest.php` (add one test method)
- Test: `tests/OccasionsAnnouncementTest.php` (new)

**Interfaces:**
- Consumes: `blueline_resolve_active_occasion( ?int $now_override = null ): ?array` (Task 3), `BLUELINE_ANNOUNCEMENT_SEVERITIES` (`inc/announcement.php`, pre-existing).
- Produces: a new `blueline_announcement_severity` filter (applied inside `blueline_announcement_severity()`), and `blueline_occasion_suppress_urgent_announcement( string $severity ): string` hooked onto it.

- [ ] **Step 1: Write the failing test**

  Add to `tests/AnnouncementTest.php`:

  ```php
  	/**
  	 * Asserts blueline_announcement_severity()'s return value passes
  	 * through the `blueline_announcement_severity` filter -- the hook
  	 * point inc/occasions.php's commemorative-suppression callback (a
  	 * separate test suite) attaches to. Proven here with a throwaway
  	 * callback so this test does not depend on inc/occasions.php at all.
  	 */
  	public function test_severity_passes_through_the_blueline_announcement_severity_filter(): void {
  		$this->set_announcement( array( 'announcement_severity' => 'urgent' ) );

  		add_filter(
  			'blueline_announcement_severity',
  			static function ( $severity ) {
  				return 'info';
  			}
  		);

  		$this->assertSame( 'info', blueline_announcement_severity() );
  	}
  ```

  (`set_announcement()` is this file's own existing private helper, defined at the top of the `AnnouncementTest` class — it lays down the "off" baseline and layers the given overrides via `update_option( BLUELINE_SETTINGS_OPTION, ... )`.)

  Create `tests/OccasionsAnnouncementTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/cli-stubs.php';
  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/enqueue.php';
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/announcement.php';
  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers blueline_occasion_suppress_urgent_announcement(): design spec
   * §5/§7.6's "suppresses ... the announcement banner's urgent styling"
   * for the duration of a commemorative occasion.
   */
  final class OccasionsAnnouncementTest extends TestCase {

  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  	}

  	/**
  	 * A force_on occasion of the given type, so window matching is not a
  	 * variable in these tests.
  	 *
  	 * @param string $type 'decorative' or 'commemorative'.
  	 * @return array<string, mixed>
  	 */
  	private function occasion( string $type ): array {
  		return array(
  			'id'     => 'test-occasion',
  			'label'  => 'Test Occasion',
  			'type'   => $type,
  			'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  			'accent' => '',
  			'motif'  => 'none',
  			'line'   => '',
  			'mode'   => 'force_on',
  		);
  	}

  	/**
  	 * Asserts 'info' is never touched -- only 'urgent' is ever subject to
  	 * this clamp.
  	 */
  	public function test_info_is_never_suppressed(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'announcement_severity' => 'info',
  				'occasions'             => array( 'test-occasion' => $this->occasion( 'commemorative' ) ),
  			)
  		);

  		$this->assertSame( 'info', blueline_announcement_severity() );
  	}

  	/**
  	 * Asserts 'urgent' is suppressed to 'info' while a commemorative
  	 * occasion is the currently resolved-active one.
  	 */
  	public function test_urgent_is_suppressed_while_a_commemorative_occasion_is_active(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'announcement_severity' => 'urgent',
  				'occasions'             => array( 'test-occasion' => $this->occasion( 'commemorative' ) ),
  			)
  		);

  		$this->assertSame( 'info', blueline_announcement_severity() );
  	}

  	/**
  	 * Asserts 'urgent' is left alone when no occasion is active at all.
  	 */
  	public function test_urgent_is_not_suppressed_when_no_occasion_is_active(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'announcement_severity' => 'urgent' ) );

  		$this->assertSame( 'urgent', blueline_announcement_severity() );
  	}

  	/**
  	 * Asserts 'urgent' is left alone when the active occasion is
  	 * decorative, not commemorative -- the suppression is specific to the
  	 * commemorative type, not "any active occasion".
  	 */
  	public function test_urgent_is_not_suppressed_by_a_decorative_occasion(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'announcement_severity' => 'urgent',
  				'occasions'             => array( 'test-occasion' => $this->occasion( 'decorative' ) ),
  			)
  		);

  		$this->assertSame( 'urgent', blueline_announcement_severity() );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter "AnnouncementTest|OccasionsAnnouncementTest"` — Expected: FAIL — `AnnouncementTest`'s new method fails because `blueline_announcement_severity()` does not yet apply any filter; `OccasionsAnnouncementTest` fails with "Call to undefined function blueline_occasion_suppress_urgent_announcement()".

- [ ] **Step 3: Write minimal implementation**

  Modify `inc/announcement.php`'s `blueline_announcement_severity()`:

  ```php
  function blueline_announcement_severity(): string {
  	$stored   = (string) blueline_settings( 'announcement_severity' );
  	$severity = in_array( $stored, BLUELINE_ANNOUNCEMENT_SEVERITIES, true )
  		? $stored
  		: BLUELINE_ANNOUNCEMENT_SEVERITIES[0];

  	/**
  	 * Filters the announcement's resolved severity, after the choices-list
  	 * clamp above but before it is used anywhere. inc/occasions.php hooks
  	 * this to clamp 'urgent' down to 'info' while a commemorative occasion
  	 * is the currently resolved-active one -- design spec §5/§7.6: a
  	 * festive "urgent" treatment would read as tone-deaf during an
  	 * observance like Remembrance Day.
  	 *
  	 * @param string $severity One of BLUELINE_ANNOUNCEMENT_SEVERITIES.
  	 */
  	return apply_filters( 'blueline_announcement_severity', $severity );
  }
  ```

  Append to `inc/occasions.php`:

  ```php
  /**
   * Clamp the announcement's severity from 'urgent' down to 'info' while a
   * commemorative occasion is the currently resolved-active one -- design
   * spec §5/§7.6. Hooked onto the `blueline_announcement_severity` filter
   * inc/announcement.php's blueline_announcement_severity() applies its
   * return value through.
   *
   * @param string $severity One of BLUELINE_ANNOUNCEMENT_SEVERITIES.
   * @return string
   */
  function blueline_occasion_suppress_urgent_announcement( string $severity ): string {
  	if ( 'urgent' !== $severity ) {
  		return $severity;
  	}

  	$active = blueline_resolve_active_occasion();

  	if ( null === $active || 'commemorative' !== ( $active['type'] ?? '' ) ) {
  		return $severity;
  	}

  	return 'info';
  }
  add_filter( 'blueline_announcement_severity', 'blueline_occasion_suppress_urgent_announcement' );
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter "AnnouncementTest|OccasionsAnnouncementTest"` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/announcement.php inc/occasions.php tests/AnnouncementTest.php tests/OccasionsAnnouncementTest.php
  git commit -m "Suppress the announcement banner's urgent styling during a commemorative occasion"
  ```

---

### Task 6: The WP-Cron boundary purge

**Files:**
- Modify: `tests/bootstrap.php` (add `wp_next_scheduled()`, `wp_schedule_single_event()`, `wp_unschedule_event()` stubs, `blueline_test_reset_cron()`, and wire it into `blueline_test_reset()`)
- Modify: `inc/occasions.php` (add the boundary-timestamp calculator, the scheduler, the cron callback, the clear-on-switch callback, and their hook registrations)
- Test: `tests/OccasionsCronTest.php` (new)

**Interfaces:**
- Consumes: `blueline_settings( 'occasions' ): array` (Task 1), `blueline_occasion_valid_md( $value ): bool` (Task 1), `blueline_maybe_purge_page_cache(): void` (`inc/settings/cache.php:153`, pre-existing), `wp_timezone(): DateTimeZone` (WordPress core / test stub).
- Produces: `BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK` (a string constant), `blueline_occasion_next_occurrence_timestamp( string $md, int $after ): int`, `blueline_occasion_next_boundary_timestamp( array $occasions, int $now ): ?int`, `blueline_occasion_schedule_next_boundary_purge( ?int $now_override = null ): void`, `blueline_occasion_cron_boundary_purge( ?int $now_override = null ): void`, `blueline_occasion_clear_scheduled_boundary_purge(): void`. The `$now_override` parameters exist purely for testability — matching Task 3's `blueline_resolve_active_occasion( ?int $now_override = null )` precedent — and are never passed by the real hook registrations below, which call each function with no arguments. Test-only: `wp_next_scheduled( string $hook, array $args = array() ): int|false`, `wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool`, `wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool`, `blueline_test_reset_cron(): void`.

- [ ] **Step 1: Write the failing test**

  Modify `tests/bootstrap.php`: add, near the other `$GLOBALS[...] = array();` initialisations (alongside `$GLOBALS['bl_test_cache'] = array();`):

  ```php
  $GLOBALS['bl_test_cron'] = array();
  ```

  Add a reset function, alongside `blueline_test_reset_cache()`/`blueline_test_reset_transients()`:

  ```php
  /**
   * Reset the in-memory WP-Cron store. Call from setUp() (directly, or via
   * the combined blueline_test_reset()) in any test that schedules or
   * checks a cron event.
   */
  function blueline_test_reset_cron(): void {
  	$GLOBALS['bl_test_cron'] = array();
  }
  ```

  Modify `blueline_test_reset()` to also call it:

  ```php
  function blueline_test_reset(): void {
  	blueline_test_reset_hooks();
  	blueline_test_reset_options();
  	blueline_test_reset_transients();
  	blueline_test_reset_cache();
  	blueline_test_reset_cron();
  	blueline_test_reset_settings_errors();
  	blueline_test_reset_admin_pages();
  	blueline_test_reset_inline_scripts();
  }
  ```

  Add the three cron stubs themselves, near `wp_cache_add()`/`wp_cache_delete()`:

  ```php
  if ( ! function_exists( 'wp_next_scheduled' ) ) {
  	/**
  	 * Minimal stand-in for WordPress' wp_next_scheduled(): the timestamp
  	 * currently scheduled for $hook, or false if none is. $args is
  	 * accepted for signature parity but not distinguished -- nothing in
  	 * this theme schedules the same hook with two different argument sets.
  	 *
  	 * @param string $hook Cron hook name.
  	 * @param array  $args Unused; signature parity with WP core.
  	 * @return int|false
  	 */
  	function wp_next_scheduled( $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; see docblock.
  		return $GLOBALS['bl_test_cron'][ $hook ] ?? false;
  	}
  }

  if ( ! function_exists( 'wp_schedule_single_event' ) ) {
  	/**
  	 * Minimal stand-in for WordPress' wp_schedule_single_event(): records
  	 * $timestamp as the next occurrence of $hook, replacing whatever was
  	 * previously scheduled for it.
  	 *
  	 * @param int    $timestamp Unix timestamp to schedule for.
  	 * @param string $hook      Cron hook name.
  	 * @param array  $args      Unused; signature parity with WP core.
  	 * @return bool
  	 */
  	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; see docblock.
  		$GLOBALS['bl_test_cron'][ $hook ] = $timestamp;
  		return true;
  	}
  }

  if ( ! function_exists( 'wp_unschedule_event' ) ) {
  	/**
  	 * Minimal stand-in for WordPress' wp_unschedule_event(): clears
  	 * whatever is scheduled for $hook. $timestamp is accepted for
  	 * signature parity but not checked against what is actually stored --
  	 * this stub only ever tracks one scheduled occurrence per hook.
  	 *
  	 * @param int    $timestamp Unused beyond signature parity; see docblock.
  	 * @param string $hook      Cron hook name.
  	 * @param array  $args      Unused; signature parity with WP core.
  	 * @return bool
  	 */
  	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; see docblock.
  		unset( $GLOBALS['bl_test_cron'][ $hook ] );
  		return true;
  	}
  }
  ```

  Create `tests/OccasionsCronTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/cache.php';
  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers the WP-Cron boundary purge (design spec §5/§7.8): cron is used
   * ONLY for the purge timing, never for correctness -- every assertion
   * here is about SCHEDULING, not about what is active (that is
   * tests/OccasionsResolverTest.php's job).
   */
  final class OccasionsCronTest extends TestCase {

  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  	}

  	/**
  	 * An auto-mode occasion fixture with the given window.
  	 *
  	 * @param string $start_md 'MM-DD'.
  	 * @param string $end_md   'MM-DD'.
  	 * @return array<string, mixed>
  	 */
  	private function auto_occasion( string $start_md, string $end_md ): array {
  		return array(
  			'id'     => 'test-occasion',
  			'label'  => 'Test Occasion',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => $start_md, 'end_md' => $end_md ),
  			'accent' => '',
  			'motif'  => 'none',
  			'line'   => '',
  			'mode'   => 'auto',
  		);
  	}

  	/* --------------------------------------------- next_occurrence_timestamp */

  	/**
  	 * Asserts an MD still ahead in the current calendar year resolves
  	 * within that same year.
  	 */
  	public function test_next_occurrence_still_ahead_this_year(): void {
  		$after = ( new DateTimeImmutable( '2026-01-01 00:00:00', wp_timezone() ) )->getTimestamp();

  		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $after );

  		$this->assertSame(
  			( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
  			$next
  		);
  	}

  	/**
  	 * Asserts an MD already passed this year rolls forward to next year.
  	 */
  	public function test_next_occurrence_rolls_to_next_year_once_passed(): void {
  		$after = ( new DateTimeImmutable( '2026-08-01 00:00:00', wp_timezone() ) )->getTimestamp();

  		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $after );

  		$this->assertSame(
  			( new DateTimeImmutable( '2027-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
  			$next
  		);
  	}

  	/**
  	 * Asserts the returned timestamp is always strictly after $after, even
  	 * when $after IS the boundary instant itself (the boundary that just
  	 * passed must roll to next year, not return itself again).
  	 */
  	public function test_next_occurrence_is_strictly_after_the_boundary_instant_itself(): void {
  		$boundary = ( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp();

  		$next = blueline_occasion_next_occurrence_timestamp( '07-01', $boundary );

  		$this->assertGreaterThan( $boundary, $next );
  	}

  	/* ----------------------------------------------- next_boundary_timestamp */

  	/**
  	 * Asserts no stored occasions at all resolves to null.
  	 */
  	public function test_next_boundary_null_when_nothing_stored(): void {
  		$this->assertNull( blueline_occasion_next_boundary_timestamp( array(), time() ) );
  	}

  	/**
  	 * Asserts a force_on/force_off occasion contributes no boundary --
  	 * its activation is not date-driven, so it has none to purge for.
  	 */
  	public function test_next_boundary_ignores_non_auto_occasions(): void {
  		$forced_on = $this->auto_occasion( '07-01', '07-01' );
  		$forced_on['mode'] = 'force_on';
  		$forced_off = $this->auto_occasion( '08-01', '08-01' );
  		$forced_off['mode'] = 'force_off';

  		$this->assertNull(
  			blueline_occasion_next_boundary_timestamp(
  				array( 'a' => $forced_on, 'b' => $forced_off ),
  				time()
  			)
  		);
  	}

  	/**
  	 * Asserts BOTH the start and end of a single auto occasion's window
  	 * are considered, and the soonest of the two (here, the end, since
  	 * $now already falls inside the window) is what is returned.
  	 */
  	public function test_next_boundary_considers_both_start_and_end(): void {
  		$now = ( new DateTimeImmutable( '2026-12-10 00:00:00', wp_timezone() ) )->getTimestamp();

  		$boundary = blueline_occasion_next_boundary_timestamp(
  			array( 'christmas' => $this->auto_occasion( '12-01', '12-26' ) ),
  			$now
  		);

  		$this->assertSame(
  			( new DateTimeImmutable( '2026-12-26 00:00:00', wp_timezone() ) )->getTimestamp(),
  			$boundary
  		);
  	}

  	/**
  	 * Asserts the SOONEST boundary across multiple stored occasions wins.
  	 */
  	public function test_next_boundary_picks_the_soonest_across_multiple_occasions(): void {
  		$now = ( new DateTimeImmutable( '2026-01-01 00:00:00', wp_timezone() ) )->getTimestamp();

  		$boundary = blueline_occasion_next_boundary_timestamp(
  			array(
  				'canada-day' => $this->auto_occasion( '07-01', '07-01' ),
  				'christmas'  => $this->auto_occasion( '12-01', '12-26' ),
  			),
  			$now
  		);

  		$this->assertSame(
  			( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
  			$boundary
  		);
  	}

  	/* ----------------------------------------- schedule_next_boundary_purge */

  	/**
  	 * Asserts scheduling with an auto occasion stored actually schedules
  	 * the cron hook for its computed boundary.
  	 */
  	public function test_schedule_next_boundary_purge_schedules_when_an_auto_occasion_exists(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01' ) ) ) );

  		blueline_occasion_schedule_next_boundary_purge();

  		$this->assertNotFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
  	}

  	/**
  	 * Asserts scheduling with NO auto occasion stored leaves nothing
  	 * scheduled, and clears a previously scheduled event if one exists
  	 * (e.g. the last auto occasion was just removed).
  	 */
  	public function test_schedule_next_boundary_purge_clears_when_no_auto_occasion_remains(): void {
  		wp_schedule_single_event( time() + 3600, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array() ) );

  		blueline_occasion_schedule_next_boundary_purge();

  		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
  	}

  	/**
  	 * Asserts changing which occasion is stored reschedules to the NEW
  	 * boundary rather than leaving the stale one in place.
  	 */
  	public function test_schedule_next_boundary_purge_reschedules_when_the_boundary_changes(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01' ) ) ) );
  		blueline_occasion_schedule_next_boundary_purge();
  		$first = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'remembrance-day' => $this->auto_occasion( '11-11', '11-11' ) ) ) );
  		blueline_occasion_schedule_next_boundary_purge();
  		$second = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  		$this->assertNotSame( $first, $second );
  	}

  	/* ------------------------------------------------------- cron callback */

  	/**
  	 * Asserts the cron callback purges the page cache (observed via the
  	 * "manual purge needed" flag it sets when BLUELINE_SRCACHE_PURGE is
  	 * off, the shipped default) and reschedules for the FOLLOWING
  	 * boundary, not the one that just fired.
  	 *
  	 * Both scheduling calls pass an explicit $now_override, one hour
  	 * before and then exactly AT this year's 07-01 boundary respectively
  	 * -- simulating real WP-Cron firing at (or just after) the instant it
  	 * was scheduled for. Without pinning $now like this, both calls would
  	 * run against the real system clock a fraction of a second apart,
  	 * both computing the SAME "next 07-01" target (whichever one is still
  	 * ahead of today) and making the "reschedules for the FOLLOWING
  	 * boundary" claim untestable.
  	 */
  	public function test_cron_callback_purges_and_reschedules_for_the_following_boundary(): void {
  		$this_years_boundary = ( new DateTimeImmutable( '2026-07-01 00:00:00', wp_timezone() ) )->getTimestamp();

  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->auto_occasion( '07-01', '07-01' ) ) ) );
  		blueline_occasion_schedule_next_boundary_purge( $this_years_boundary - 3600 );
  		$before = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  		$this->assertSame( $this_years_boundary, $before );

  		blueline_occasion_cron_boundary_purge( $this_years_boundary );

  		$this->assertNotFalse( get_option( BLUELINE_CACHE_PURGE_NEEDED_OPTION, false ) );

  		$after = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  		$this->assertNotFalse( $after );
  		$this->assertGreaterThan( $before, $after );
  		$this->assertSame(
  			( new DateTimeImmutable( '2027-07-01 00:00:00', wp_timezone() ) )->getTimestamp(),
  			$after
  		);
  	}

  	/* -------------------------------------- clear_scheduled_boundary_purge */

  	/**
  	 * Asserts the clear function removes a scheduled event outright.
  	 */
  	public function test_clear_scheduled_boundary_purge_removes_the_event(): void {
  		wp_schedule_single_event( time() + 3600, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  		blueline_occasion_clear_scheduled_boundary_purge();

  		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
  	}

  	/**
  	 * Asserts the clear function is a harmless no-op when nothing is
  	 * scheduled.
  	 */
  	public function test_clear_scheduled_boundary_purge_is_a_noop_when_nothing_is_scheduled(): void {
  		blueline_occasion_clear_scheduled_boundary_purge();

  		$this->assertFalse( wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK ) );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsCronTest` — Expected: FAIL with "Call to undefined function blueline_occasion_next_occurrence_timestamp()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php`:

  ```php
  /**
   * The WP-Cron hook name for the boundary purge. A single event is ever
   * scheduled against this hook at a time (design spec §5/§7.8: "a single
   * WP-Cron event").
   */
  const BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK = 'blueline_occasion_boundary_purge';

  /**
   * The next annual occurrence of calendar date $md (00:00:00, site
   * timezone) strictly after $after.
   *
   * A `02-29` $md in a target year that is not itself a leap year rolls
   * forward to March 1st -- PHP's own DateTimeImmutable date-parsing
   * behaviour for an out-of-range day, accepted here rather than worked
   * around: none of the four shipped presets (blueline_occasion_presets())
   * uses `02-29`, and this is a purge-TIMING calculation only (design spec
   * §5/§7.8) -- a purge landing a day off in a leap-adjacent year for a
   * hypothetical future `02-29` occasion delays cache visibility by at
   * most a day, never producing a wrong resolved result (the resolver,
   * Task 3, is unaffected by this function entirely).
   *
   * @param string $md    'MM-DD'.
   * @param int    $after Unix timestamp; the returned timestamp is always
   *                       strictly greater than this.
   * @return int Unix timestamp.
   */
  function blueline_occasion_next_occurrence_timestamp( string $md, int $after ): int {
  	$tz   = wp_timezone();
  	$year = (int) ( new DateTimeImmutable( '@' . $after ) )->setTimezone( $tz )->format( 'Y' );

  	list( $month, $day ) = array_map( 'intval', explode( '-', $md ) );

  	$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), $tz );

  	if ( $candidate->getTimestamp() <= $after ) {
  		$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year + 1, $month, $day ), $tz );
  	}

  	return $candidate->getTimestamp();
  }

  /**
   * The soonest "something changes" instant across every stored auto-mode
   * occasion's start AND end boundary -- the moment WP-Cron should next
   * fire blueline_occasion_cron_boundary_purge(). `force_on`/`force_off`
   * occasions are excluded: their activation is not date-driven, so they
   * have no boundary to purge for.
   *
   * Never the correctness mechanism itself (design spec §5/§7.8) -- only
   * ever a purge-timing hint. blueline_resolve_active_occasion() (Task 3)
   * always recomputes from scratch on every request regardless of whether
   * this ever ran.
   *
   * @param array<string, array<string, mixed>> $occasions blueline_settings( 'occasions' )'s stored value.
   * @param int                                  $now       Unix timestamp to measure "next" from.
   * @return int|null The soonest boundary strictly after $now, or null if
   *                   there are no auto-mode occasions stored at all.
   */
  function blueline_occasion_next_boundary_timestamp( array $occasions, int $now ): ?int {
  	$boundaries = array();

  	foreach ( $occasions as $occasion ) {
  		if ( ! is_array( $occasion ) ) {
  			continue;
  		}

  		$mode = $occasion['mode'] ?? 'auto';

  		if ( 'auto' !== $mode ) {
  			continue;
  		}

  		$window = $occasion['window'] ?? array();
  		$start  = $window['start_md'] ?? '';
  		$end    = $window['end_md'] ?? '';

  		if ( ! blueline_occasion_valid_md( $start ) || ! blueline_occasion_valid_md( $end ) ) {
  			continue;
  		}

  		$boundaries[] = blueline_occasion_next_occurrence_timestamp( $start, $now );
  		$boundaries[] = blueline_occasion_next_occurrence_timestamp( $end, $now );
  	}

  	if ( array() === $boundaries ) {
  		return null;
  	}

  	return min( $boundaries );
  }

  /**
   * (Re)schedule the single WP-Cron event that purges the page cache at
   * the next occasion window boundary (design spec §5/§7.8). A no-op when
   * no auto-mode occasion is stored (the common case today: `occasions`
   * defaults to an empty array, and 2.1a ships no UI to populate it) --
   * any previously scheduled event is cleared in that case. Also a no-op
   * when the correct boundary is ALREADY scheduled, mirroring
   * blueline_settings_migrate()'s own early-exit shape
   * (inc/settings/store.php).
   *
   * @param int|null $now_override Unix timestamp to measure "next" from;
   *                                defaults to the current time. Exists
   *                                purely for testability, matching Task
   *                                3's `blueline_resolve_active_occasion()`
   *                                precedent -- never passed by the real
   *                                hook registrations below.
   * @return void
   */
  function blueline_occasion_schedule_next_boundary_purge( ?int $now_override = null ): void {
  	$now = $now_override ?? time();

  	$occasions = blueline_settings( 'occasions' );
  	$occasions = is_array( $occasions ) ? $occasions : array();

  	$next      = blueline_occasion_next_boundary_timestamp( $occasions, $now );
  	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  	if ( null === $next ) {
  		if ( false !== $scheduled ) {
  			wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  		}
  		return;
  	}

  	if ( $scheduled === $next ) {
  		return; // Already scheduled for the right instant.
  	}

  	if ( false !== $scheduled ) {
  		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  	}

  	wp_schedule_single_event( $next, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  }
  // Scheduled on activation, and re-derived on every write to the settings
  // option -- exactly the two hooks inc/settings/cache.php's own purge
  // trigger already uses for "react to a settings write" (add_option_/
  // update_option_ -- see that file's own add_action() pair), rather than
  // polling on `init` for every ordinary page view.
  add_action( 'after_switch_theme', 'blueline_occasion_schedule_next_boundary_purge' );
  add_action( 'add_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );
  add_action( 'update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );

  /**
   * The cron callback itself: purge, then reschedule for the FOLLOWING
   * boundary (never the same one twice) -- design spec §5/§7.8's "purges
   * ... and reschedules".
   *
   * @param int|null $now_override Forwarded to
   *                                blueline_occasion_schedule_next_boundary_purge();
   *                                see that function's own docblock.
   * @return void
   */
  function blueline_occasion_cron_boundary_purge( ?int $now_override = null ): void {
  	blueline_maybe_purge_page_cache();
  	blueline_occasion_schedule_next_boundary_purge( $now_override );
  }
  add_action( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK, 'blueline_occasion_cron_boundary_purge' );

  /**
   * Clear the scheduled boundary-purge event when this theme is switched
   * away from. `switch_theme` fires on the OUTGOING theme -- the closest
   * thing a theme has to a deactivation hook, and the same convention
   * inc/account/endpoints.php already establishes for `after_switch_theme`
   * (its own flush_rewrite_rules() registration) on the activation side.
   * Tidiness, not a correctness requirement: a stray scheduled event on a
   * theme that is no longer active simply never finds this callback again
   * once it is deactivated, since add_action() above is registered only
   * while this theme's functions.php actually runs.
   *
   * @return void
   */
  function blueline_occasion_clear_scheduled_boundary_purge(): void {
  	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

  	if ( false !== $scheduled ) {
  		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
  	}
  }
  add_action( 'switch_theme', 'blueline_occasion_clear_scheduled_boundary_purge' );
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsCronTest` — Expected: PASS
  Run: `composer test` — Expected: PASS (confirms the new `tests/bootstrap.php` stubs and reset function did not disturb any other suite).

- [ ] **Step 5: Commit**
  ```bash
  git add tests/bootstrap.php inc/occasions.php tests/OccasionsCronTest.php
  git commit -m "Add the WP-Cron boundary purge for scheduled occasion activation"
  ```

---

### Task 7: Block-editor CSS parity

**Files:**
- Modify: `inc/occasions.php` (add `blueline_occasion_editor_styles()` + its `add_filter()`)
- Test: `tests/OccasionsEditorParityTest.php` (new)

**Interfaces:**
- Consumes: `blueline_resolve_active_occasion( ?int $now_override = null ): ?array` (Task 3), specifically its `resolved_accent` key.
- Produces: `blueline_occasion_editor_styles( array $settings ): array`, hooked onto `block_editor_settings_all`.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsEditorParityTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/cli-stubs.php';
  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/enqueue.php';
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers blueline_occasion_editor_styles(): design spec §5/§7.9's
   * `block_editor_settings_all` mechanism, selector
   * ':root, .editor-styles-wrapper', emitting only --bl-occasion-accent.
   */
  final class OccasionsEditorParityTest extends TestCase {

  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  	}

  	/**
  	 * A force_on occasion with a valid, PASSING accent (never the empty
  	 * default -- so the appended CSS's value is provably the occasion's
  	 * own, not a coincidence).
  	 *
  	 * @return array<string, mixed>
  	 */
  	private function occasion_with_accent( string $accent ): array {
  		return array(
  			'id'     => 'test-occasion',
  			'label'  => 'Test Occasion',
  			'type'   => 'decorative',
  			'window' => array( 'start_md' => '01-01', 'end_md' => '12-31' ),
  			'accent' => $accent,
  			'motif'  => 'none',
  			'line'   => '',
  			'mode'   => 'force_on',
  		);
  	}

  	/**
  	 * Asserts nothing is added when no occasion is active -- the token's
  	 * own CSS default already applies, so there is nothing to override.
  	 */
  	public function test_no_change_when_no_occasion_is_active(): void {
  		$settings = array( 'styles' => array( array( 'css' => 'body{color:red}' ) ) );

  		$this->assertSame( $settings, blueline_occasion_editor_styles( $settings ) );
  	}

  	/**
  	 * Asserts a missing `styles` key is also left untouched when nothing
  	 * is active, rather than this filter inventing the key.
  	 */
  	public function test_no_styles_key_invented_when_no_occasion_is_active(): void {
  		$this->assertSame( array(), blueline_occasion_editor_styles( array() ) );
  	}

  	/**
  	 * Asserts an active occasion appends exactly the documented CSS shape:
  	 * selector ':root, .editor-styles-wrapper', only --bl-occasion-accent,
  	 * the occasion's own resolved accent value, and '__unstableType' =>
  	 * 'theme'.
  	 */
  	public function test_appends_the_resolved_accent_for_the_active_occasion(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'test-occasion' => $this->occasion_with_accent( '#ffd700' ) ) ) );

  		// Sanity-check the fixture actually resolves before trusting the
  		// filter's own assertion below.
  		$resolved = blueline_resolve_active_occasion();
  		$this->assertNotNull( $resolved );
  		$this->assertSame( '#ffd700', $resolved['resolved_accent'] );

  		$result = blueline_occasion_editor_styles( array() );

  		$this->assertCount( 1, $result['styles'] );
  		$this->assertSame( 'theme', $result['styles'][0]['__unstableType'] );
  		$this->assertSame(
  			':root, .editor-styles-wrapper { --bl-occasion-accent: #ffd700; }',
  			$result['styles'][0]['css']
  		);
  	}

  	/**
  	 * Asserts an existing `styles` list is appended to, not replaced.
  	 */
  	public function test_preserves_existing_styles_entries(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'test-occasion' => $this->occasion_with_accent( '#ffd700' ) ) ) );

  		$result = blueline_occasion_editor_styles( array( 'styles' => array( array( 'css' => 'body{color:red}' ) ) ) );

  		$this->assertCount( 2, $result['styles'] );
  		$this->assertSame( 'body{color:red}', $result['styles'][0]['css'] );
  		$this->assertStringContainsString( '--bl-occasion-accent: #ffd700', $result['styles'][1]['css'] );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsEditorParityTest` — Expected: FAIL with "Call to undefined function blueline_occasion_editor_styles()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php`:

  ```php
  /**
   * Append occasion CSS to the block editor canvas's own style list, via
   * the one filter core actually clones into `.editor-styles-wrapper` --
   * design spec §5/§7.9, and see inc/enqueue.php's own docblock
   * (immediately preceding its `add_theme_support( 'editor-styles' )` /
   * `add_editor_style()` pair in inc/setup.php) for why
   * `enqueue_block_editor_assets()` was tried and reverted instead.
   *
   * Emits ONLY `--bl-occasion-accent` -- the sole occasion-related custom
   * property that exists -- and only when an occasion is actually
   * resolved-active right now. When none is, the token's own CSS default
   * (`var(--bl-ice)`, already present in both style.css and
   * assets/src/css/editor.css) already applies, so there is nothing to
   * override and this filter changes nothing.
   *
   * @param array<string, mixed> $settings Block editor settings.
   * @return array<string, mixed>
   */
  function blueline_occasion_editor_styles( array $settings ): array {
  	$active = blueline_resolve_active_occasion();

  	if ( null === $active ) {
  		return $settings;
  	}

  	$accent = $active['resolved_accent'] ?? '';

  	if ( ! preg_match( '/^#[0-9a-f]{6}$/', $accent ) ) {
  		// Defensive: never emit anything that is not exactly the validated
  		// hex shape the sanitizer/resolver already guarantee -- this is
  		// the last point before the value reaches raw CSS text.
  		return $settings;
  	}

  	$styles   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
  	$styles[] = array(
  		'css'            => ':root, .editor-styles-wrapper { --bl-occasion-accent: ' . $accent . '; }',
  		'__unstableType' => 'theme',
  	);

  	$settings['styles'] = $styles;

  	return $settings;
  }
  add_filter( 'block_editor_settings_all', 'blueline_occasion_editor_styles' );
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsEditorParityTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php tests/OccasionsEditorParityTest.php
  git commit -m "Add block-editor CSS parity for the active occasion's accent"
  ```

---

### Task 8: Full verification

**Files:**
- (none — verification only)

**Interfaces:**
- Consumes: everything produced by Tasks 1-7.
- Produces: a confirmed-green `npm run check` for this phase.

- [ ] **Step 1: Run the full gate**

  Run: `npm run check` — Expected: PASS (lint:css, lint:js, test:js, tokens:check, `composer test`, `composer lint` all green). This phase adds no CSS/JS/JSON, so `lint:css`/`lint:js`/`tokens:check` should be unaffected by anything in this plan; if any of them fails, the failure is a pre-existing condition on this branch, not something Tasks 1-7 introduced — investigate before assuming otherwise.

- [ ] **Step 2: If `composer lint` (phpcs) reports anything in the modified files**

  Fix any WordPress-Coding-Standards nit it finds (docblock alignment, spacing, array alignment) with a line-level `phpcs:ignore <sniff> -- <reason>` only where the sniff is flagging something deliberate (the `switch`/`case` fallthrough comment in `blueline_render_occasion_motif()`, the unused-parameter cron stubs in `tests/bootstrap.php`, both already carry their own justified ignores above) — never `phpcs:ignoreFile`, and never stack a second annotation on a line that already has a trailing one.

- [ ] **Step 3: Confirm no test from Tasks 1-7 regressed**

  Run: `composer test` — Expected: PASS, full suite (not just this phase's new files). This is the same class of check Phase 2.0's own final task ran: Task 1's new sanitizer branch on a shared write path (`blueline_settings_sanitize_callback()`) is exactly the kind of change the P1b decision record's §3.1 warns can retroactively break an existing test that reaches the same path.

- [ ] **Step 4: Confirm the suite passes with occasions actually populated, not only at defaults**

  Per the original spec's §10 (restated in the design spec's §7, "whole-suite, every phase" testing requirement): this plan's own tests already exercise the full suite with a non-empty `occasions` array and a live `aa_acknowledgements` entry (`tests/OccasionsResolverTest.php`, `tests/OccasionsAnnouncementTest.php`), so Step 3's full run already covers this — no separate action needed here beyond confirming Step 3 actually passed with those files included, not skipped by an over-narrow `--filter`.

- [ ] **Step 5: Commit** — nothing to commit; this task is verification-only. If Step 2 required a fix, that fix was already committed as part of its own task before reaching here; re-run Step 1 to confirm.
