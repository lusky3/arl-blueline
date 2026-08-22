# Blueline P2 Phase 2.2 — Correctness & Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the final P2 phase — deploy-drift revalidation for `aa_acknowledgements` (a `_validated_against` reserved key, one shared classification function, an `admin_init`-hooked check, and a one-time admin notice), two new Site Health fields (active occasion, formatted acknowledgements), and a `wp blueline settings occasions list|enable|disable|force` WP-CLI subcommand — with zero change to any already-shipped 2.0/2.1a/2.1b function's behavior and no admin-panel UI change.

**Architecture:** `_validated_against` joins `_schema`/`aa_acknowledgements` as a reserved settings key (`inc/settings/page.php`), with its own direct-read accessor in `inc/settings/validation.php` alongside the already-shipped `blueline_settings_inputs_hash()`. A single new pure function, `blueline_occasions_classify_acknowledgements()` (`inc/occasions.php`), is the one place that decides `valid`/`orphaned`/`stale` for a stored acknowledgement; the `admin_init`-hooked drift check, the `admin_notices`-hooked notice, and Site Health all read from it — nothing re-derives that logic independently. The WP-CLI `occasions` subcommand is a new method on the already-existing `Blueline_Settings_Command` class (`inc/cli/settings-command.php`), writing through the exact same `update_option()` → `sanitize_option_{$option}` path every other subcommand in that file already uses.

**Tech Stack:** PHP 8.1+ (PHPUnit 12 against `tests/bootstrap.php`'s WordPress stubs), plain WP-CLI (`inc/cli/settings-command.php`, loaded only under `defined( 'WP_CLI' ) && WP_CLI`).

**Spec:** docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md (§6.5 specifically)

## Global Constraints

- `phpcs:ignoreFile` is forbidden. Only line-level `phpcs:ignore <sniff> -- <reason>`, and a trailing annotation on a line REPLACES a preceding-line one silently — never stack two expecting both to apply.
- No admin notice may render as a bare `<div>` (`tests/NoticeDivGuardTest.php` scans every theme `.php` file, not just this plan's own additions). This plan's one new notice is a `<section class="notice notice-warning">`.
- Never write a comment or docblock claiming a test proves something that isn't actually true, or that code does something it doesn't — verify before asserting.
- Task 1 adds a new branch inside `blueline_settings_sanitize_callback()` — a single choke point every settings save passes through — so re-run the WHOLE suite after it, not just the new tests.
- `blueline_settings`'s storage shape stays flat. This plan adds no nesting.
- **This plan must NOT change the behavior of `blueline_sanitize_occasions()`, `blueline_resolve_active_occasion()`, `blueline_occasions_apply_aa_overrides()`, `blueline_record_acknowledgement()`, `blueline_remove_acknowledgement()`, `blueline_acknowledgement_covers()`, `blueline_stored_acknowledgements()`, or any other already-shipped function** — only new functions, new callers, and new hooks around them.
- **No admin-panel UI change of any kind.** 2.1b already shipped the full Occasions tab; this phase is CLI + Site Health + one admin notice only.
- Team colours (design spec §6.1) needs no task here — already fully implemented and tested in `tests/TeamColorsTest.php`. Do not touch `inc/team-colors.php`'s behavior.
- **Ruling (design spec §6.5, storage shape): `_validated_against` is a fifth reserved key, following `_schema`/`aa_acknowledgements`'s shape** — excluded from `blueline_settings_defaults()`'s return, its own branch in `blueline_settings_sanitize_callback()`'s reserved-key dispatch, read via a direct accessor (`blueline_validated_against()`) — **NOT** the `occasions` shape (which gets a real default because the resolver needs it back through `blueline_settings()`; nothing needs `_validated_against` back that way).
- **Ruling (design spec §6.5, one classification function): `blueline_occasions_classify_acknowledgements()` is the SOLE source both the drift notice and Site Health read from** — no duplicated classification logic anywhere else.
- **Ruling (design spec §6.5, hook choice): the drift check hooks `admin_init`, not the spec's literal "on init" wording, and not `admin_notices`/`update_option_*` either** — deliberately narrower than `blueline_settings_migrate()`'s `init` hook, because this check has zero front-end/cron/REST/WP-CLI consumer (the resolver's own fail-closed guarantee already holds unconditionally on every request regardless of whether this check has ever run) and computing `blueline_settings_inputs_hash()` is not free (a real filesystem stat + hash), unlike `blueline_settings_migrate()`'s O(1) integer-compare guard.
- **Ruling (design spec §6.5, one-time notice): `_validated_against` updates to the current hash immediately after classification, whether or not anything classified non-`valid`** — the notice is a one-time surface for the request that detected drift, not a recurring nag.
- **Ruling (design spec §6.5, notice markup): the drift notice renders a `<ul>` inside its `<section class="notice notice-warning">`** — the first admin notice in this codebase naming a variable-length list.
- **Ruling (design spec §6.5, Site Health field shape): the "AA acknowledgements" field is ONE multi-line string, one line per entry** — never a nested array, matching every other field in `inc/settings/site-health.php`.
- **Ruling (design spec §6.5, WP-CLI shape): `wp blueline settings occasions` is ONE subcommand taking an action positional argument (`list`, `enable <id>`, `disable <id>`, `force <id>`)**, not four separately-registered subcommands. Verb-to-mode mapping, applied consistently everywhere it appears: `enable` → `mode = 'auto'`, `disable` → `mode = 'force_off'`, `force` → `mode = 'force_on'`. `enable`/`disable`/`force` on an id present only in `blueline_occasion_presets()` materializes that preset into the real stored `occasions` map first, with the requested mode applied.
- **Read the actual current line numbers/text yourself** before editing `inc/settings/page.php`, `inc/settings/site-health.php`, or `inc/cli/settings-command.php` — this plan's own anchors were captured directly from the live file during research (branch `p2-phase-2.2-correctness-observability`), but confirm they still match before applying an edit.

---

### Task 1: `_validated_against` reserved key and its direct-read accessor

**Files:**
- Modify: `inc/settings/page.php:132` (docblock cross-reference), `inc/settings/page.php:208-222` (the `BLUELINE_SETTINGS_RESERVED_KEYS` docblock and constant), `inc/settings/page.php:404-423` (the `blueline_settings_sanitize_callback()` docblock's responsibility-4 paragraph), `inc/settings/page.php:502-511` (the reserved-key branch, inserting a new `_validated_against` case between the existing `aa_acknowledgements` and `occasions` cases)
- Modify: `inc/settings/defaults.php:480-492` (a documentation-only addition; no behavior change)
- Modify: `inc/settings/validation.php` (append `blueline_validated_against()`)
- Test: `tests/SettingsPageTest.php`
- Test: `tests/SettingsInputsHashTest.php`

**Interfaces:**
- Consumes: nothing from a later task.
- Produces: `BLUELINE_SETTINGS_RESERVED_KEYS` now includes `'_validated_against'`; `blueline_settings_sanitize_callback()` accepts a `_validated_against` string on a programmatic write and repairs anything else to `''`; `blueline_validated_against(): string` (`inc/settings/validation.php`) — Task 3 both reads this and is the only production caller that writes `_validated_against` (via a direct `update_option()` call, exactly like `blueline_settings_migrate()`'s own `_schema` write).

- [ ] **Step 1: Write the failing test**

  Add to `tests/SettingsPageTest.php`, immediately after the existing `test_sanitize_callback_validates_occasions_shape()` method (i.e. right after its closing `}`, before the next docblock comment):

  ```php
  	/**
  	 * `_validated_against` (design spec §6.5's storage-shape ruling) is a
  	 * reserved key following `_schema`/`aa_acknowledgements`'s shape: a
  	 * plain string survives a programmatic write.
  	 */
  	public function test_sanitize_callback_lets_validated_against_survive_a_programmatic_write(): void {
  		$output = blueline_settings_sanitize_callback(
  			array( '_validated_against' => 'abc123hash' )
  		);

  		$this->assertSame( 'abc123hash', $output['_validated_against'] );
  	}

  	/**
  	 * Dropped outright from ANY tab-scoped submission, same as `_schema`/
  	 * `aa_acknowledgements` -- unlike `occasions`, no tab (including the
  	 * Occasions tab's own exception, which names `occasions` specifically)
  	 * is ever exempted for this key.
  	 */
  	public function test_sanitize_callback_drops_validated_against_from_a_form_submission(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'               => 'occasions',
  				'_validated_against' => 'abc123hash',
  			)
  		);

  		$this->assertArrayNotHasKey( '_validated_against', $output );
  	}

  	/**
  	 * A non-string value is repaired to '' rather than trusted verbatim.
  	 */
  	public function test_sanitize_callback_validates_validated_against_shape(): void {
  		$output = blueline_settings_sanitize_callback(
  			array( '_validated_against' => array( 'not' => 'a string' ) )
  		);

  		$this->assertSame( '', $output['_validated_against'] );
  	}
  ```

  Add to `tests/SettingsInputsHashTest.php`: first, add these three requires immediately after the existing `require_once __DIR__ . '/../inc/settings/validation.php';` line, and add a `setUp()` method (this file currently has none — none of its existing tests touch the options store):

  ```php
  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  ```

  ```php
  final class SettingsInputsHashTest extends TestCase {

  	/**
  	 * Reset the options store before each test -- needed starting with
  	 * this task's own blueline_validated_against() tests; harmless for
  	 * this file's pre-existing fixture-file-based tests, which never
  	 * touch the options store at all.
  	 */
  	protected function setUp(): void {
  		blueline_test_reset();
  	}

  ```

  Then add these test methods immediately before the final closing `}` of the class (after `test_real_no_argument_call_does_not_fatal()`):

  ```php

  	/* -------------------------------------------------- validated_against */

  	/**
  	 * Covers blueline_validated_against() (design spec §6.5's
  	 * storage-shape ruling): a fresh install (nothing stored at all)
  	 * reads as ''.
  	 */
  	public function test_validated_against_defaults_to_empty_string(): void {
  		$this->assertSame( '', blueline_validated_against() );
  	}

  	/**
  	 * Reads back whatever was actually stored.
  	 */
  	public function test_validated_against_reads_the_stored_value(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => 'abc123hash' ) );

  		$this->assertSame( 'abc123hash', blueline_validated_against() );
  	}

  	/**
  	 * A malformed stored value (not a string) reads back as '' rather
  	 * than being trusted verbatim -- matching
  	 * blueline_stored_acknowledgements()'s own defensive posture for the
  	 * same shape of bad data.
  	 */
  	public function test_validated_against_repairs_a_non_string_stored_value(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => array( 'not' => 'a string' ) ) );

  		$this->assertSame( '', blueline_validated_against() );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsPageTest` — Expected: FAIL with `Undefined array key "_validated_against"` on `test_sanitize_callback_lets_validated_against_survive_a_programmatic_write()`.

  Run: `composer test -- --filter SettingsInputsHashTest` — Expected: FAIL with "Call to undefined function blueline_validated_against()".

- [ ] **Step 3: Write minimal implementation**

  In `inc/settings/page.php`, locate this exact sentence inside the file's own top docblock (around line 132):

  ```php
   * The fix is an explicit reserved-key allow-list -- today
   * `array( '_schema', 'aa_acknowledgements', 'occasions' )` -- rather than
   * "forward anything unrecognised":
  ```

  Replace it with:

  ```php
   * The fix is an explicit reserved-key allow-list -- today
   * `array( '_schema', 'aa_acknowledgements', 'occasions', '_validated_against' )` --
   * rather than "forward anything unrecognised":
  ```

  Locate this exact block (the `BLUELINE_SETTINGS_RESERVED_KEYS` constant and its docblock):

  ```php
  /**
   * Top-level option keys that are neither a real schema field nor the two
   * request-scoped bookkeeping keys (`_posted_fields`, `_tab`) this file's
   * own form emits, but which a write still needs to be able to carry --
   * today three of them: inc/settings/store.php's `_schema` migration
   * version, inc/settings/acknowledgements.php's `aa_acknowledgements` map,
   * and inc/occasions.php's `occasions` map (the one reserved key that a
   * tab-scoped submission may also carry, and only from its OWN tab -- see
   * blueline_settings_sanitize_callback()'s docblock, rule 4).
   * blueline_settings_sanitize_callback() checks every unrecognised key
   * against this explicit allow-list rather than forwarding it merely for
   * being unrecognised -- see this file's own docblock's `_schema` section
   * for why that distinction is load-bearing.
   */
  const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements', 'occasions' );
  ```

  Replace it with:

  ```php
  /**
   * Top-level option keys that are neither a real schema field nor the two
   * request-scoped bookkeeping keys (`_posted_fields`, `_tab`) this file's
   * own form emits, but which a write still needs to be able to carry --
   * today four of them: inc/settings/store.php's `_schema` migration
   * version, inc/settings/acknowledgements.php's `aa_acknowledgements` map,
   * inc/occasions.php's `occasions` map (the one reserved key that a
   * tab-scoped submission may also carry, and only from its OWN tab -- see
   * blueline_settings_sanitize_callback()'s docblock, rule 4), and
   * inc/settings/validation.php's `_validated_against` deploy-drift
   * bookkeeping hash (design spec §6.5's storage-shape ruling: follows
   * `_schema`/`aa_acknowledgements`'s shape, not `occasions`'s -- nothing
   * reads it back through blueline_settings()).
   * blueline_settings_sanitize_callback() checks every unrecognised key
   * against this explicit allow-list rather than forwarding it merely for
   * being unrecognised -- see this file's own docblock's `_schema` section
   * for why that distinction is load-bearing.
   */
  const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements', 'occasions', '_validated_against' );
  ```

  Locate this exact sentence inside `blueline_settings_sanitize_callback()`'s own docblock (around line 404-406):

  ```php
   * 4. Every OTHER key is checked against an explicit reserved-key
   *    allow-list (BLUELINE_SETTINGS_RESERVED_KEYS, today `_schema`,
   *    `aa_acknowledgements`, and `occasions`), never forwarded merely for being
   *    unrecognised -- see this file's docblock's `_schema` section for why
  ```

  Replace it with:

  ```php
   * 4. Every OTHER key is checked against an explicit reserved-key
   *    allow-list (BLUELINE_SETTINGS_RESERVED_KEYS, today `_schema`,
   *    `aa_acknowledgements`, `occasions`, and `_validated_against`), never
   *    forwarded merely for being unrecognised -- see this file's docblock's
   *    `_schema` section for why
  ```

  Locate this exact block inside `blueline_settings_sanitize_callback()`'s body (the `aa_acknowledgements` branch, immediately followed by the `occasions` branch):

  ```php
  			if ( 'aa_acknowledgements' === $key ) {
  				// Not an integer like every other reserved key today -- a map
  				// of acknowledgement entries (inc/settings/acknowledgements.php).
  				// Its own validator drops anything malformed rather than
  				// corrupting the option or crashing a later reader.
  				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
  				continue;
  			}

  			if ( 'occasions' === $key ) {
  ```

  Replace it with:

  ```php
  			if ( 'aa_acknowledgements' === $key ) {
  				// Not an integer like every other reserved key today -- a map
  				// of acknowledgement entries (inc/settings/acknowledgements.php).
  				// Its own validator drops anything malformed rather than
  				// corrupting the option or crashing a later reader.
  				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
  				continue;
  			}

  			if ( '_validated_against' === $key ) {
  				// A hash string (blueline_settings_inputs_hash()'s own
  				// return shape), not an integer like every other reserved
  				// key -- validated as a plain string, defaulting to '' for
  				// anything else. No complex validation is needed here
  				// (design spec §6.5's storage-shape ruling): this key is
  				// never read back through blueline_settings() (see
  				// blueline_settings_defaults()'s own docblock for why),
  				// only through inc/settings/validation.php's
  				// blueline_validated_against(), which applies the
  				// identical is_string()-or-default fallback on read -- so a
  				// malformed stored value can never reach a caller as
  				// anything other than ''.
  				$output[ $key ] = is_string( $value ) ? $value : '';
  				continue;
  			}

  			if ( 'occasions' === $key ) {
  ```

  In `inc/settings/defaults.php`, locate this exact block (documentation only -- no return-value change):

  ```php
  		// `aa_acknowledgements` (design spec §4.4/§4.5) is deliberately NOT
  		// listed here, for the same reason `_schema` never has been:
  		// membership in blueline_settings()'s returned array is decided by
  		// presence in THIS array, so a bookkeeping key that must stay out of
  		// that return value -- see blueline_settings()'s own docblock
  		// (inc/settings/store.php) -- must stay out of this one too. It is
  		// still real, protected storage
  		// (BLUELINE_SETTINGS_RESERVED_KEYS, inc/settings/page.php;
  		// validated by inc/settings/acknowledgements.php's
  		// blueline_sanitize_acknowledgements()) -- just never a default
  		// value a schema field falls back to.

  		// `occasions` (design spec §5) IS listed here, unlike
  		// `aa_acknowledgements` immediately above -- the front-end resolver
  ```

  Replace it with:

  ```php
  		// `aa_acknowledgements` (design spec §4.4/§4.5) is deliberately NOT
  		// listed here, for the same reason `_schema` never has been:
  		// membership in blueline_settings()'s returned array is decided by
  		// presence in THIS array, so a bookkeeping key that must stay out of
  		// that return value -- see blueline_settings()'s own docblock
  		// (inc/settings/store.php) -- must stay out of this one too. It is
  		// still real, protected storage
  		// (BLUELINE_SETTINGS_RESERVED_KEYS, inc/settings/page.php;
  		// validated by inc/settings/acknowledgements.php's
  		// blueline_sanitize_acknowledgements()) -- just never a default
  		// value a schema field falls back to.

  		// `_validated_against` (design spec §6.5's storage-shape ruling,
  		// Phase 2.2) follows the exact same shape as `aa_acknowledgements`
  		// immediately above, for the identical reason: nothing reads it
  		// back through blueline_settings() (inc/settings/validation.php's
  		// blueline_validated_against() reads get_option() directly
  		// instead), so it stays out of this array too. Still real,
  		// protected storage (BLUELINE_SETTINGS_RESERVED_KEYS,
  		// inc/settings/page.php; validated by that same file's
  		// blueline_settings_sanitize_callback() reserved-key branch).

  		// `occasions` (design spec §5) IS listed here, unlike
  		// `aa_acknowledgements` immediately above -- the front-end resolver
  ```

  Append to `inc/settings/validation.php`, after `blueline_settings_inputs_hash()`'s closing `}` (the end of the file):

  ```php

  /**
   * The settings option's own `_validated_against` bookkeeping value: the
   * blueline_settings_inputs_hash() this option was last checked against
   * for deploy-drift revalidation (Phase 2.2,
   * blueline_occasions_maybe_revalidate_on_drift()). '' means either a
   * fresh install, or one whose drift check has genuinely never run yet.
   *
   * Reads get_option() directly, the same shape
   * blueline_stored_acknowledgements() (inc/settings/acknowledgements.php)
   * already uses for its own reserved key: `_validated_against` is
   * deliberately excluded from blueline_settings_defaults()'s return (see
   * that function's own docblock, inc/settings/defaults.php), so
   * blueline_settings() can never return it -- there is no ordinary caller
   * asking for a field VALUE that has any business reading migration/drift
   * bookkeeping back through that accessor.
   *
   * @return string
   */
  function blueline_validated_against(): string {
  	$stored = get_option( BLUELINE_SETTINGS_OPTION, array() );
  	$stored = is_array( $stored ) ? $stored : array();

  	return is_string( $stored['_validated_against'] ?? null ) ? $stored['_validated_against'] : '';
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsPageTest` — Expected: PASS.

  Run: `composer test -- --filter SettingsInputsHashTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS. This task's edit sits inside `blueline_settings_sanitize_callback()`, a choke point every settings save passes through — confirm nothing else that reaches it regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/settings/page.php inc/settings/defaults.php inc/settings/validation.php tests/SettingsPageTest.php tests/SettingsInputsHashTest.php
  git commit -m "Add the _validated_against reserved key and its accessor"
  ```

---

### Task 2: The shared classification function

**Files:**
- Modify: `inc/occasions.php` (append `blueline_occasions_classify_acknowledgements()`, immediately after `blueline_occasions_apply_aa_overrides()`)
- Test: `tests/OccasionsTest.php`

**Interfaces:**
- Consumes: `blueline_stored_acknowledgements(): array`, `blueline_acknowledgement_covers( array $acknowledgements, string $scope, string $rule_id, string $value, string $current_inputs_hash ): bool`, `blueline_record_acknowledgement(...)` (all pre-existing, `inc/settings/acknowledgements.php`, unchanged), `blueline_settings( 'occasions' ): array`, `blueline_occasion_accent_default(): string` (pre-existing, `inc/occasions.php`, unchanged), `blueline_sanitize_hex_color( $value ): string` (pre-existing, `inc/team-colors.php`), `blueline_settings_inputs_hash(): string` (pre-existing, `inc/settings/validation.php`).
- Produces: `blueline_occasions_classify_acknowledgements(): array<string, string>` — a map of acknowledgement scope => `'valid'` | `'orphaned'` | `'stale'`, covering only `occasion:*`-scoped entries. Task 3 (the drift check) and Task 4 (Site Health) both call this and only this.

- [ ] **Step 1: Write the failing test**

  First, add these requires to `tests/OccasionsTest.php`, immediately BEFORE the existing `require_once __DIR__ . '/../inc/team-colors.php';` line:

  ```php
  require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by blueline_settings_inputs_hash(), which blueline_occasions_classify_acknowledgements() calls.
  require_once __DIR__ . '/../inc/settings/defaults.php';
  require_once __DIR__ . '/../inc/settings/sections.php';
  require_once __DIR__ . '/../inc/settings/store.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  ```

  Then add a `setUp()` method to the `OccasionsTest` class, immediately after the `final class OccasionsTest extends TestCase {` line (this file currently has no `setUp()` — none of its pre-existing tests touch the options store):

  ```php

  	/**
  	 * Reset every in-memory store before each test -- needed starting with
  	 * this task's own classify_acknowledgements() tests; harmless for
  	 * this file's pre-existing fixture-file-based tests, which never touch
  	 * the options store at all.
  	 */
  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  	}
  ```

  Then add these test methods immediately before the final closing `}` of the class:

  ```php

  	/* ---------------------------------------- classify_acknowledgements */

  	/**
  	 * A minimal, valid occasion whose accent FAILS contrast against
  	 * BLUELINE_TOKEN_INK -- the same fixture value used throughout this
  	 * suite's other AA-override tests.
  	 *
  	 * @param array<string, mixed> $overrides Keys to override.
  	 * @return array<string, mixed>
  	 */
  	private function classify_fixture_occasion( array $overrides = array() ): array {
  		return array_merge(
  			array(
  				'id'     => 'canada-day',
  				'label'  => 'Canada Day',
  				'type'   => 'decorative',
  				'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  				'accent' => '#274a63',
  				'motif'  => 'none',
  				'line'   => '',
  				'mode'   => 'auto',
  			),
  			$overrides
  		);
  	}

  	/**
  	 * Nothing stored at all classifies nothing.
  	 */
  	public function test_classify_returns_empty_when_nothing_is_stored(): void {
  		$this->assertSame( array(), blueline_occasions_classify_acknowledgements() );
  	}

  	/**
  	 * An acknowledgement whose occasion id no longer exists AT ALL
  	 * classifies `orphaned`.
  	 */
  	public function test_classify_marks_an_acknowledgement_orphaned_when_its_occasion_is_gone(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(), // The occasion was deleted.
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, blueline_settings_inputs_hash(), 1 ),
  			)
  		);

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'orphaned' ),
  			blueline_occasions_classify_acknowledgements()
  		);
  	}

  	/**
  	 * An acknowledgement whose value and inputs hash both still match
  	 * current reality classifies `valid`.
  	 */
  	public function test_classify_marks_valid_when_everything_still_matches(): void {
  		$hash = blueline_settings_inputs_hash();

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array( 'canada-day' => $this->classify_fixture_occasion() ),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, $hash, 1 ),
  			)
  		);

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'valid' ),
  			blueline_occasions_classify_acknowledgements()
  		);
  	}

  	/**
  	 * An acknowledgement whose stored inputs hash no longer matches
  	 * current reality (a deploy touched style.css or contrast-rules.json)
  	 * classifies `stale`, even though the accent value itself is
  	 * unchanged.
  	 */
  	public function test_classify_marks_stale_when_the_inputs_hash_has_drifted(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array( 'canada-day' => $this->classify_fixture_occasion() ),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-now-stale-hash', 1 ),
  			)
  		);

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'stale' ),
  			blueline_occasions_classify_acknowledgements()
  		);
  	}

  	/**
  	 * An acknowledgement recorded for a DIFFERENT accent value than the
  	 * occasion currently stores classifies `stale` -- the admin's override
  	 * no longer covers what would actually render.
  	 */
  	public function test_classify_marks_stale_when_the_accent_value_changed(): void {
  		$hash = blueline_settings_inputs_hash();

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array( 'canada-day' => $this->classify_fixture_occasion( array( 'accent' => '#8b0000' ) ) ),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, $hash, 1 ),
  			)
  		);

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'stale' ),
  			blueline_occasions_classify_acknowledgements()
  		);
  	}

  	/**
  	 * A live acknowledgement scoped OUTSIDE the `occasion:` namespace is
  	 * not this function's business at all -- skipped entirely, never
  	 * reported as anything.
  	 */
  	public function test_classify_ignores_a_foreign_scope_entirely(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'something-else:entirely', 'some-other-rule', '#274a63', 1.66, blueline_settings_inputs_hash(), 1 ),
  			)
  		);

  		$this->assertSame( array(), blueline_occasions_classify_acknowledgements() );
  	}

  	/**
  	 * An occasion with an EMPTY `accent` (use the resolved default) is
  	 * classified against that resolved default, not against an empty
  	 * string.
  	 */
  	public function test_classify_resolves_an_empty_accent_to_the_default(): void {
  		$hash = blueline_settings_inputs_hash();

  		// The real stylesheet default (--bl-ice, '#74c0e1') passes contrast
  		// outright; an acknowledgement recorded against that SAME resolved
  		// value (an unusual but not impossible history -- e.g. one
  		// recorded while a since-reverted contrast-rules.json threshold
  		// made it fail) is classified against it, not against ''.
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array( 'canada-day' => $this->classify_fixture_occasion( array( 'accent' => '' ) ) ),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#74c0e1', 1.0, $hash, 1 ),
  			)
  		);

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'valid' ),
  			blueline_occasions_classify_acknowledgements()
  		);
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_occasions_classify_acknowledgements()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/occasions.php`, immediately after `blueline_occasions_apply_aa_overrides()`'s closing `}` (i.e. right after the `return $acknowledgements;` / `}` pair that ends that function, before `blueline_occasion_today_md()`'s docblock):

  ```php

  /**
   * Classify every `occasion:*`-scoped entry in `aa_acknowledgements` as
   * still-`valid`, `orphaned`, or `stale` against CURRENT reality -- design
   * spec §6.5's ruling that this is the SOLE function both the deploy-drift
   * notice (inc/settings/validation.php's
   * blueline_occasions_maybe_revalidate_on_drift()) and Site Health
   * (inc/settings/site-health.php) read from, so the two surfaces can never
   * independently drift on what counts as stale.
   *
   * For each `occasion:{id}` acknowledgement:
   *
   * - `{id}` absent from blueline_settings( 'occasions' ) entirely ->
   *   `orphaned` (the occasion was deleted or renamed since the
   *   acknowledgement was recorded).
   * - Otherwise, resolve that occasion's CURRENT effective accent (its own
   *   `accent`, or blueline_occasion_accent_default() when empty), sanitize
   *   it exactly as blueline_resolve_active_occasion() does, and check
   *   blueline_acknowledgement_covers() against it with the CURRENT
   *   blueline_settings_inputs_hash() -- `stale` on a `false` result (the
   *   accent value changed, or style.css/contrast-rules.json moved since
   *   the acknowledgement was recorded), `valid` otherwise. An unresolvable
   *   current accent (blueline_sanitize_hex_color() returns '') can never
   *   be covered by anything, so it classifies `stale` too.
   *
   * A scope outside the `occasion:` namespace is not this function's
   * business at all and is skipped entirely -- not merely left `valid` --
   * so the returned map's own keys are exactly this function's domain.
   *
   * Pure and read-only: never calls blueline_record_acknowledgement() or
   * blueline_remove_acknowledgement(). A stale or orphaned acknowledgement
   * is REPORTED, never deleted -- blueline_remove_acknowledgement()'s own
   * docblock already establishes that a stale hash is not a valid reason
   * to call it, and drift discovery is not a stronger reason than
   * staleness itself (design spec §6.5).
   *
   * @return array<string, string> Map of acknowledgement scope => 'valid' | 'orphaned' | 'stale'.
   */
  function blueline_occasions_classify_acknowledgements(): array {
  	$acknowledgements = blueline_stored_acknowledgements();

  	$occasions = blueline_settings( 'occasions' );
  	$occasions = is_array( $occasions ) ? $occasions : array();

  	$inputs_hash = blueline_settings_inputs_hash();

  	$classifications = array();

  	foreach ( $acknowledgements as $scope => $entry ) {
  		if ( 0 !== strpos( $scope, 'occasion:' ) ) {
  			continue; // Not this mechanism's business -- e.g. a future non-occasion scope.
  		}

  		$id = substr( $scope, strlen( 'occasion:' ) );

  		if ( ! isset( $occasions[ $id ] ) || ! is_array( $occasions[ $id ] ) ) {
  			$classifications[ $scope ] = 'orphaned';
  			continue;
  		}

  		$occasion = $occasions[ $id ];

  		$raw_accent = '' !== ( $occasion['accent'] ?? '' )
  			? $occasion['accent']
  			: blueline_occasion_accent_default();

  		$accent = is_string( $raw_accent ) ? blueline_sanitize_hex_color( $raw_accent ) : '';

  		if ( '' === $accent ) {
  			// Unresolvable current accent -- nothing can cover this; the
  			// same treatment as a value that plainly changed.
  			$classifications[ $scope ] = 'stale';
  			continue;
  		}

  		$covers = blueline_acknowledgement_covers(
  			$acknowledgements,
  			$scope,
  			'ink-on-occasion-accent',
  			$accent,
  			$inputs_hash
  		);

  		$classifications[ $scope ] = $covers ? 'valid' : 'stale';
  	}

  	return $classifications;
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsTest` — Expected: PASS.

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php tests/OccasionsTest.php
  git commit -m "Add the shared occasions acknowledgement classification function"
  ```

---

### Task 3: The drift check and its one-time admin notice

**Files:**
- Modify: `inc/settings/validation.php` (append the notice-payload relay, the `admin_init`-hooked drift check, the label helper, and the `admin_notices`-hooked notice)
- Test: `tests/OccasionsDriftTest.php` (new)

**Interfaces:**
- Consumes: `blueline_settings_inputs_hash(): string`, `blueline_validated_against(): string` (Task 1), `blueline_occasions_classify_acknowledgements(): array` (Task 2), `blueline_settings( 'occasions' ): array` (pre-existing, `inc/settings/store.php`).
- Produces: `blueline_occasions_drift_notice_payload( $classifications = false ): ?array` — a same-request relay: pass `false` (the default) to read without writing, an array to set, `null` to explicitly clear (tests use this to guarantee no leakage between cases, since this static is NOT one of the stores `blueline_test_reset()` already resets). `blueline_occasions_maybe_revalidate_on_drift(): void` — hooked `admin_init`. `blueline_render_occasions_drift_notice(): void` — hooked `admin_notices`. `blueline_occasions_drift_notice_label( string $scope, array $occasions ): string` — a private-in-spirit helper the notice renderer uses; no later task depends on it.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsDriftTest.php`:

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
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';
  require_once __DIR__ . '/../inc/settings/validation.php';

  /**
   * Covers inc/settings/validation.php's deploy-drift check
   * (blueline_occasions_maybe_revalidate_on_drift(), hooked `admin_init`)
   * and its one-time notice (blueline_render_occasions_drift_notice(),
   * hooked `admin_notices`) -- design spec §6.5's hook-choice,
   * one-time-notice, and notice-markup rulings.
   *
   * Both are called DIRECTLY, never via do_action(), matching
   * tests/SettingsStoreTest.php's own precedent for
   * blueline_settings_migrate() (its closest structural analog): a
   * hook-fired test would only prove WordPress dispatches hooks, which is
   * not this suite's job to re-prove.
   */
  final class OccasionsDriftTest extends TestCase {

  	/**
  	 * Reset every in-memory store, AND explicitly clear the drift
  	 * notice's same-request relay -- blueline_test_reset() does not know
  	 * about that module-level static, since it predates this task.
  	 */
  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  		blueline_occasions_drift_notice_payload( null );
  	}

  	/**
  	 * Grant the fake current user `manage_options` -- the capability the
  	 * notice is gated on, matching every other notice in this codebase.
  	 */
  	private function grant_manage_options(): void {
  		$state                           = &blueline_test_state();
  		$state['caps']['manage_options'] = true;
  	}

  	/* ---------------------------------------- maybe_revalidate_on_drift */

  	/**
  	 * No drift at all (stored `_validated_against` already matches the
  	 * current hash) short-circuits before classifying anything: the
  	 * notice payload stays unset.
  	 */
  	public function test_no_drift_short_circuits(): void {
  		$hash = blueline_settings_inputs_hash();
  		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => $hash ) );

  		blueline_occasions_maybe_revalidate_on_drift();

  		$this->assertNull( blueline_occasions_drift_notice_payload() );
  	}

  	/**
  	 * Drift with nothing non-valid to report still updates
  	 * `_validated_against` to the current hash -- design spec §6.5's
  	 * ruling that the hash updates regardless of outcome.
  	 */
  	public function test_drift_with_nothing_to_report_still_updates_the_hash(): void {
  		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => 'a-stale-hash' ) );

  		blueline_occasions_maybe_revalidate_on_drift();

  		$this->assertNull( blueline_occasions_drift_notice_payload() );
  		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
  	}

  	/**
  	 * Drift with a stale acknowledgement sets the notice payload AND
  	 * updates the hash, in the same call.
  	 */
  	public function test_drift_with_a_stale_acknowledgement_sets_the_payload_and_updates_the_hash(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'_validated_against'  => 'a-stale-hash',
  				'occasions'           => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#274a63',
  						'motif'  => 'none',
  						'line'   => '',
  						'mode'   => 'auto',
  					),
  				),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-different-stale-hash', 1 ),
  			)
  		);

  		blueline_occasions_maybe_revalidate_on_drift();

  		$this->assertSame(
  			array( 'occasion:canada-day' => 'stale' ),
  			blueline_occasions_drift_notice_payload()
  		);
  		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
  	}

  	/* ---------------------------------------------- the notice itself */

  	/**
  	 * Nothing renders without `manage_options`, even with a payload set.
  	 */
  	public function test_notice_renders_nothing_without_manage_options(): void {
  		blueline_occasions_drift_notice_payload( array( 'occasion:canada-day' => 'stale' ) );

  		ob_start();
  		blueline_render_occasions_drift_notice();
  		$html = (string) ob_get_clean();

  		$this->assertSame( '', $html );
  	}

  	/**
  	 * Nothing renders when there is no payload to show, even with the
  	 * capability granted.
  	 */
  	public function test_notice_renders_nothing_when_the_payload_is_empty(): void {
  		$this->grant_manage_options();

  		ob_start();
  		blueline_render_occasions_drift_notice();
  		$html = (string) ob_get_clean();

  		$this->assertSame( '', $html );
  	}

  	/**
  	 * A `stale` entry names the occasion's own label (not its raw scope
  	 * string) and explains it needs re-review.
  	 */
  	public function test_notice_names_the_occasion_label_for_a_stale_entry(): void {
  		$this->grant_manage_options();

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#274a63',
  						'motif'  => 'none',
  						'line'   => '',
  						'mode'   => 'auto',
  					),
  				),
  			)
  		);
  		blueline_occasions_drift_notice_payload( array( 'occasion:canada-day' => 'stale' ) );

  		ob_start();
  		blueline_render_occasions_drift_notice();
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString( 'Canada Day', $html );
  		$this->assertStringContainsString( 're-review', $html );
  	}

  	/**
  	 * An `orphaned` entry (its occasion is no longer stored at all) names
  	 * the raw scope string instead, and says the occasion no longer
  	 * exists.
  	 */
  	public function test_notice_names_the_raw_scope_for_an_orphaned_entry(): void {
  		$this->grant_manage_options();

  		blueline_occasions_drift_notice_payload( array( 'occasion:ghost' => 'orphaned' ) );

  		ob_start();
  		blueline_render_occasions_drift_notice();
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString( 'occasion:ghost', $html );
  		$this->assertStringContainsString( 'no longer exists', $html );
  	}

  	/**
  	 * The markup is a `<ul>` inside a `<section class="notice ...">`,
  	 * never a `<div>` -- tests/NoticeDivGuardTest.php enforces the latter
  	 * project-wide; this test pins the former's positive shape directly.
  	 */
  	public function test_notice_markup_is_a_ul_inside_a_section(): void {
  		$this->grant_manage_options();
  		blueline_occasions_drift_notice_payload( array( 'occasion:ghost' => 'orphaned' ) );

  		ob_start();
  		blueline_render_occasions_drift_notice();
  		$html = (string) ob_get_clean();

  		$this->assertStringContainsString( '<section class="notice notice-warning">', $html );
  		$this->assertStringContainsString( '<ul>', $html );
  		$this->assertStringContainsString( '<li>', $html );
  		$this->assertStringNotContainsString( '<div', $html );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsDriftTest` — Expected: FAIL with "Call to undefined function blueline_occasions_drift_notice_payload()".

- [ ] **Step 3: Write minimal implementation**

  Append to `inc/settings/validation.php`, after `blueline_validated_against()` (added in Task 1):

  ```php

  /**
   * Relay a request's drift classification (whatever
   * blueline_occasions_maybe_revalidate_on_drift() found, filtered down to
   * non-'valid' entries) from the `admin_init` hook that computes it to the
   * `admin_notices` hook that renders it -- both fire within the SAME
   * request (design spec §6.5's ruling that the notice is a one-time,
   * same-request surface, never a persisted, recurring one), so a
   * module-level static is all this needs -- the identical pattern
   * blueline_settings_page_hook() (inc/settings/page.php) already uses for
   * an unrelated same-request handoff.
   *
   * @param array<string, string>|null|false $classifications Omit (or pass
   *                                                           `false`) to
   *                                                           read without
   *                                                           writing. Pass
   *                                                           an array to
   *                                                           set it, or
   *                                                           `null` to
   *                                                           explicitly
   *                                                           clear it
   *                                                           (used by
   *                                                           tests to
   *                                                           guarantee no
   *                                                           leakage
   *                                                           between
   *                                                           cases -- this
   *                                                           static is
   *                                                           NOT one of
   *                                                           the stores
   *                                                           blueline_test_reset()
   *                                                           already
   *                                                           clears).
   * @return array<string, string>|null
   */
  function blueline_occasions_drift_notice_payload( $classifications = false ): ?array {
  	static $stored = null;

  	if ( false !== $classifications ) {
  		$stored = $classifications;
  	}

  	return $stored;
  }

  add_action( 'admin_init', 'blueline_occasions_maybe_revalidate_on_drift' );
  /**
   * Deploy-drift revalidation (design spec §6.2, ruled on further in
   * §6.5): if blueline_settings_inputs_hash() has changed since the last
   * check (blueline_validated_against()), classify every stored
   * acknowledgement (blueline_occasions_classify_acknowledgements()) and,
   * if anything is no longer `valid`, hand that off to
   * blueline_render_occasions_drift_notice() via
   * blueline_occasions_drift_notice_payload() for THIS SAME request's
   * `admin_notices` to render. Either way, `_validated_against` is updated
   * to the current hash immediately -- design spec §6.5's ruling that this
   * is a one-time notice, not a recurring nag. The next request's cheap
   * hash-compare then short-circuits until the next real drift.
   *
   * Hooked to `admin_init`, deliberately narrower than the spec's own
   * prose ("on init") and deliberately NOT following
   * blueline_settings_migrate()'s choice of the universal `init` hook
   * (design spec §6.5): that migration's correctness has to hold before
   * ANYTHING reads the option, on every kind of request (anonymous, cron,
   * REST, WP-CLI) -- this check's correctness need is different in kind.
   * blueline_resolve_active_occasion() already recomputes contrast and
   * acknowledgement coverage from scratch on every single request
   * regardless of whether this check has ever run at all (the fail-closed
   * guarantee, design spec §4.5, holds unconditionally already); this
   * check's ONLY job is *surfacing* drift to an admin via a notice and
   * (inc/settings/site-health.php) a Site Health field -- pure
   * diagnostics, with zero front-end/cron/REST/WP-CLI consumer.
   * blueline_settings_inputs_hash() costs a real filesystem stat plus a
   * hash, unlike blueline_settings_migrate()'s O(1) integer-compare guard,
   * so paying that on every anonymous front-end request for a value
   * nothing on the front end ever reads back would be pure waste. Not
   * `update_option_*`/`add_option_*` either (2.1a's own boundary-purge
   * pattern), since this must ALSO catch drift from causes other than a
   * settings write -- a deploy touching style.css's mtime, an edited
   * contrast-rules.json -- which no options hook would ever fire for.
   *
   * @return void
   */
  function blueline_occasions_maybe_revalidate_on_drift(): void {
  	$current_hash = blueline_settings_inputs_hash();

  	if ( $current_hash === blueline_validated_against() ) {
  		// Cheap guard: nothing this mechanism cares about has changed
  		// since the last check. Skip the more expensive classification
  		// walk entirely.
  		return;
  	}

  	$classifications = blueline_occasions_classify_acknowledgements();

  	$non_valid = array_filter(
  		$classifications,
  		static function ( $status ) {
  			return 'valid' !== $status;
  		}
  	);

  	if ( array() !== $non_valid ) {
  		blueline_occasions_drift_notice_payload( $non_valid );
  	}

  	// Updated unconditionally, regardless of what the classification
  	// found -- a one-time notice, not a recurring nag. The NEXT request's
  	// cheap hash-compare above then short-circuits until the next real
  	// drift.
  	$stored                        = get_option( BLUELINE_SETTINGS_OPTION, array() );
  	$stored                        = is_array( $stored ) ? $stored : array();
  	$stored['_validated_against']  = $current_hash;

  	update_option( BLUELINE_SETTINGS_OPTION, $stored );
  }

  /**
   * A human-readable label for a drift-notice list item: the occasion's
   * own `label` when the scope names one that still exists (a `stale`
   * classification), or the raw scope string when it doesn't (an
   * `orphaned` classification, or -- defensively -- any future scope shape
   * this function does not specifically recognise).
   *
   * @param string                               $scope     A
   *                                                         blueline_occasions_classify_acknowledgements()
   *                                                         map key, e.g.
   *                                                         `occasion:canada-day`.
   * @param array<string, array<string, mixed>>  $occasions blueline_settings( 'occasions' ).
   * @return string
   */
  function blueline_occasions_drift_notice_label( string $scope, array $occasions ): string {
  	if ( 0 !== strpos( $scope, 'occasion:' ) ) {
  		return $scope;
  	}

  	$id = substr( $scope, strlen( 'occasion:' ) );

  	return isset( $occasions[ $id ]['label'] ) && is_string( $occasions[ $id ]['label'] ) && '' !== $occasions[ $id ]['label']
  		? $occasions[ $id ]['label']
  		: $scope;
  }

  add_action( 'admin_notices', 'blueline_render_occasions_drift_notice' );
  /**
   * Render the deploy-drift notice, IF
   * blueline_occasions_maybe_revalidate_on_drift() found anything
   * non-`valid` on THIS SAME request (see
   * blueline_occasions_drift_notice_payload()'s own docblock for why a
   * same-request static, not persisted storage, is what carries that
   * here).
   *
   * A `<section>`, never a `<div>` (tests/NoticeDivGuardTest.php) -- and
   * the first admin notice in this codebase naming a variable-length list
   * (design spec §6.5): a `<ul>` inside the `<section>`, one `<li>` per
   * non-valid acknowledgement, naming its occasion's label when resolvable
   * (blueline_occasions_drift_notice_label()) and stating whether it is
   * orphaned (the occasion no longer exists) or stale (it still exists,
   * but no longer covers current reality).
   *
   * @return void
   */
  function blueline_render_occasions_drift_notice(): void {
  	if ( ! current_user_can( 'manage_options' ) ) {
  		return;
  	}

  	$classifications = blueline_occasions_drift_notice_payload();

  	if ( empty( $classifications ) ) {
  		return;
  	}

  	$occasions = blueline_settings( 'occasions' );
  	$occasions = is_array( $occasions ) ? $occasions : array();
  	?>
  	<section class="notice notice-warning">
  		<p>
  			<?php
  			esc_html_e(
  				'Blueline detected a change to style.css or contrast-rules.json since the last check. The following accessibility acknowledgements no longer reflect current reality:',
  				'blueline'
  			);
  			?>
  		</p>
  		<ul>
  			<?php foreach ( $classifications as $scope => $status ) : ?>
  				<li>
  					<?php
  					echo esc_html(
  						sprintf(
  							/* translators: 1: the occasion's label (or its raw scope string, if it no longer exists), 2: why it needs attention. */
  							__( '%1$s — %2$s', 'blueline' ),
  							blueline_occasions_drift_notice_label( $scope, $occasions ),
  							'orphaned' === $status
  								? __( 'this occasion no longer exists; its acknowledgement is still on record but has nothing left to cover', 'blueline' )
  								: __( 'no longer matches its acknowledged value or the current contrast rules; it needs re-review', 'blueline' )
  						)
  					);
  					?>
  				</li>
  			<?php endforeach; ?>
  		</ul>
  	</section>
  	<?php
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsDriftTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS. `blueline_occasions_maybe_revalidate_on_drift()` calls `update_option()`, which re-enters `blueline_settings_sanitize_callback()` (Task 1's choke point) — confirm nothing regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/settings/validation.php tests/OccasionsDriftTest.php
  git commit -m "Add the deploy-drift revalidation check and its one-time admin notice"
  ```

---

### Task 4: Site Health extensions

**Files:**
- Modify: `inc/settings/site-health.php:49-115` (the `$fields` array inside `blueline_site_health_debug_information()`, plus two new helper functions)
- Test: `tests/SiteHealthTest.php`

**Interfaces:**
- Consumes: `blueline_resolve_active_occasion(): ?array` (pre-existing, `inc/occasions.php`, unchanged), `blueline_stored_acknowledgements(): array` (pre-existing, `inc/settings/acknowledgements.php`), `blueline_occasions_classify_acknowledgements(): array` (Task 2).
- Produces: two new entries in `blueline_site_health_debug_information()`'s `$fields` array (`active_occasion`, `aa_acknowledgements`), and `blueline_site_health_format_acknowledgements( array $acknowledgements, array $classifications ): string` — no later task depends on this function; it exists so the formatting logic is independently testable.

- [ ] **Step 1: Write the failing test**

  Add these requires to `tests/SiteHealthTest.php`, immediately after the existing `require_once __DIR__ . '/../inc/settings/site-health.php';` line:

  ```php
  require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by blueline_settings_inputs_hash().
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/occasions.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  ```

  Then add these test methods immediately before the final closing `}` of the `SiteHealthTest` class:

  ```php

  	/**
  	 * No occasion active reports "None".
  	 */
  	public function test_reports_no_active_occasion_as_none(): void {
  		$fields = $this->section()['fields'];

  		$this->assertSame( 'None', $fields['active_occasion']['value'] );
  	}

  	/**
  	 * A currently-active, force_on occasion reports its own label.
  	 */
  	public function test_reports_the_active_occasion_by_label(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#ffffff',
  						'motif'  => 'maple-leaf',
  						'line'   => '',
  						'mode'   => 'force_on',
  					),
  				),
  			)
  		);

  		$fields = $this->section()['fields'];

  		$this->assertSame( 'Canada Day', $fields['active_occasion']['value'] );
  	}

  	/**
  	 * No acknowledgements stored reports "None recorded.".
  	 */
  	public function test_reports_no_acknowledgements_recorded(): void {
  		$fields = $this->section()['fields'];

  		$this->assertSame( 'None recorded.', $fields['aa_acknowledgements']['value'] );
  	}

  	/**
  	 * A valid (still-covering) acknowledgement is reported with no
  	 * "(needs re-review)"/"(occasion no longer exists)" suffix.
  	 */
  	public function test_reports_a_valid_acknowledgement_with_no_suffix(): void {
  		$hash = blueline_settings_inputs_hash();

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#274a63',
  						'motif'  => 'none',
  						'line'   => '',
  						'mode'   => 'auto',
  					),
  				),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, $hash, 7 ),
  			)
  		);

  		$value = $this->section()['fields']['aa_acknowledgements']['value'];

  		$this->assertStringContainsString( 'occasion:canada-day', $value );
  		$this->assertStringContainsString( 'user #7', $value );
  		$this->assertStringNotContainsString( 'needs re-review', $value );
  		$this->assertStringNotContainsString( 'no longer exists', $value );
  	}

  	/**
  	 * A stale acknowledgement (inputs hash drifted) is marked
  	 * "(needs re-review)".
  	 */
  	public function test_reports_a_stale_acknowledgement_with_a_re_review_suffix(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#274a63',
  						'motif'  => 'none',
  						'line'   => '',
  						'mode'   => 'auto',
  					),
  				),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-now-stale-hash', 7 ),
  			)
  		);

  		$value = $this->section()['fields']['aa_acknowledgements']['value'];

  		$this->assertStringContainsString( 'needs re-review', $value );
  	}

  	/**
  	 * An orphaned acknowledgement (its occasion was deleted) is marked
  	 * "(occasion no longer exists)".
  	 */
  	public function test_reports_an_orphaned_acknowledgement_distinctly(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions'           => array(),
  				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:gone', 'ink-on-occasion-accent', '#274a63', 1.66, blueline_settings_inputs_hash(), 7 ),
  			)
  		);

  		$value = $this->section()['fields']['aa_acknowledgements']['value'];

  		$this->assertStringContainsString( 'occasion no longer exists', $value );
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SiteHealthTest` — Expected: FAIL with `Undefined array key "active_occasion"`.

- [ ] **Step 3: Write minimal implementation**

  In `inc/settings/site-health.php`, locate this exact block (the end of the `$fields` array literal inside `blueline_site_health_debug_information()`):

  ```php
  		'manual_purge_pending'  => array(
  			'label' => __( 'Manual cache purge pending', 'blueline' ),
  			'value' => blueline_cache_purge_needed() ? __( 'Yes', 'blueline' ) : __( 'No', 'blueline' ),
  		),
  	);
  ```

  Replace it with:

  ```php
  		'manual_purge_pending'  => array(
  			'label' => __( 'Manual cache purge pending', 'blueline' ),
  			'value' => blueline_cache_purge_needed() ? __( 'Yes', 'blueline' ) : __( 'No', 'blueline' ),
  		),
  		'active_occasion'       => array(
  			'label' => __( 'Active occasion', 'blueline' ),
  			'value' => blueline_site_health_active_occasion_label(),
  		),
  		'aa_acknowledgements'   => array(
  			'label' => __( 'AA acknowledgements', 'blueline' ),
  			'value' => blueline_site_health_format_acknowledgements(
  				blueline_stored_acknowledgements(),
  				blueline_occasions_classify_acknowledgements()
  			),
  		),
  	);
  ```

  Append these two new functions to the end of `inc/settings/site-health.php`:

  ```php

  /**
   * The currently-active occasion's own label, or "None" -- design spec
   * §6.3/§6.9's Site Health requirement, reading the SAME resolver the
   * front end uses (blueline_resolve_active_occasion(), inc/occasions.php,
   * unchanged), so this can never disagree with what the site is actually
   * showing right now.
   *
   * @return string
   */
  function blueline_site_health_active_occasion_label(): string {
  	$active = blueline_resolve_active_occasion();

  	if ( null === $active ) {
  		return __( 'None', 'blueline' );
  	}

  	return (string) ( $active['label'] ?? $active['id'] ?? '' );
  }

  /**
   * Format `aa_acknowledgements` as ONE multi-line string, one line per
   * entry -- design spec §6.5's Site Health field-shape ruling: matching
   * every OTHER field in this file, which are all plain strings; no field
   * here has ever carried a nested array, and inventing that shape now for
   * just this one field would be a bigger, riskier departure than
   * formatting a list as delimited text, which is exactly how WordPress'
   * own Site Health screen already expects a multi-line field value to
   * look.
   *
   * Every `occasion:*`-scoped entry gets $classifications' own verdict
   * appended: "(needs re-review)" for `stale` (the ruling's own exact
   * wording), "(occasion no longer exists)" for `orphaned` -- an addition
   * beyond the ruling's literal text, but a direct, non-contradicting
   * application of design spec §6.3's own framing ("every live AA
   * acknowledgement ... including ones currently invalidated by drift,
   * marked as such"): an orphaned entry is exactly as invalidated as a
   * stale one, just for a different reason (the occasion itself is gone,
   * not merely a hash mismatch), and leaving it printed as an unremarkable,
   * unmarked line would silently lose that distinction. A `valid` entry,
   * or one outside the `occasion:` namespace entirely (not present in
   * $classifications at all -- blueline_occasions_classify_acknowledgements()
   * only ever classifies scopes it owns), gets no suffix.
   *
   * An empty map renders as a single 'None recorded.' line, matching how
   * every other field's empty/off state in this file already reads as
   * plain, unremarkable text rather than an absent field.
   *
   * @param array<string, array<string, mixed>> $acknowledgements blueline_stored_acknowledgements().
   * @param array<string, string>               $classifications  blueline_occasions_classify_acknowledgements().
   * @return string
   */
  function blueline_site_health_format_acknowledgements( array $acknowledgements, array $classifications ): string {
  	if ( array() === $acknowledgements ) {
  		return __( 'None recorded.', 'blueline' );
  	}

  	$lines = array();

  	foreach ( $acknowledgements as $scope => $entry ) {
  		$suffix = '';

  		if ( isset( $classifications[ $scope ] ) ) {
  			if ( 'stale' === $classifications[ $scope ] ) {
  				$suffix = ' ' . __( '(needs re-review)', 'blueline' );
  			} elseif ( 'orphaned' === $classifications[ $scope ] ) {
  				$suffix = ' ' . __( '(occasion no longer exists)', 'blueline' );
  			}
  		}

  		$lines[] = sprintf(
  			/* translators: 1: acknowledgement scope, 2: contrast rule id, 3: contrast ratio (2 decimal places), 4: acknowledging user id, 5: acknowledgement date, 6: an optional "(needs re-review)"/"(occasion no longer exists)" suffix, or an empty string. */
  			__( '%1$s — rule "%2$s", ratio %3$s, user #%4$d, %5$s%6$s', 'blueline' ),
  			$scope,
  			(string) ( $entry['rule_id'] ?? '' ),
  			sprintf( '%.2f', (float) ( $entry['ratio'] ?? 0 ) ),
  			(int) ( $entry['user_id'] ?? 0 ),
  			gmdate( 'Y-m-d H:i:s', (int) ( $entry['date'] ?? 0 ) ),
  			$suffix
  		);
  	}

  	return implode( "\n", $lines );
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SiteHealthTest` — Expected: PASS.

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/site-health.php tests/SiteHealthTest.php
  git commit -m "Add active-occasion and acknowledgements fields to Site Health"
  ```

---

### Task 5: The WP-CLI `occasions` subcommand

**Files:**
- Modify: `inc/cli/settings-command.php:701-728` (append the `occasions()` public method and the `occasions_list()` private helper to `Blueline_Settings_Command`, immediately after `flush_cache()`, before the class's closing `}`)
- Test: `tests/OccasionsCliCommandTest.php` (new)

**Interfaces:**
- Consumes: `blueline_settings( 'occasions' ): array`, `blueline_occasion_presets(): array`, `blueline_sanitize_occasions( $value ): array` (all pre-existing, `inc/occasions.php`, unchanged, reached indirectly through `update_option()` → `blueline_settings_sanitize_callback()`), `BLUELINE_SETTINGS_OPTION` (pre-existing, `inc/settings/defaults.php`).
- Produces: `Blueline_Settings_Command::occasions( array $args, array $assoc_args ): void` — the public subcommand method WP-CLI dispatches `wp blueline settings occasions ...` to. No later task depends on this.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsCliCommandTest.php`:

  ```php
  <?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
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
  require_once __DIR__ . '/../inc/settings/snapshots.php';
  require_once __DIR__ . '/../inc/settings/sanitize.php';
  require_once __DIR__ . '/../inc/settings/import.php';
  require_once __DIR__ . '/../inc/settings/links.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
  require_once __DIR__ . '/../inc/team-colors.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/occasions.php';
  require_once __DIR__ . '/../inc/settings/page.php';

  if ( ! defined( 'WP_CLI' ) ) {
  	define( 'WP_CLI', true );
  }

  require_once __DIR__ . '/../inc/cli/settings-command.php';

  /**
   * Covers `wp blueline settings occasions list|enable|disable|force`
   * (Blueline_Settings_Command::occasions(), inc/cli/settings-command.php)
   * -- design spec §6.5's WP-CLI-shape ruling: one subcommand, one action
   * positional argument, and the enable/auto, disable/force_off,
   * force/force_on verb-to-mode mapping.
   */
  final class OccasionsCliCommandTest extends TestCase {

  	/**
  	 * Reset every in-memory store and this test's own CLI log before each
  	 * test.
  	 */
  	protected function setUp(): void {
  		blueline_test_reset();
  		blueline_test_reset_state();
  		$GLOBALS['bl_test_cli_log'] = array();
  	}

  	/**
  	 * Grant the in-memory current user `manage_options`.
  	 */
  	private function grant_manage_options(): void {
  		$state                           = &blueline_test_state();
  		$state['caps']['manage_options'] = true;
  	}

  	/**
  	 * Every message the CLI recorded, joined.
  	 *
  	 * @return string
  	 */
  	private function cli_output(): string {
  		return implode( "\n", array_column( $GLOBALS['bl_test_cli_log'], 'message' ) );
  	}

  	/**
  	 * `list` on a fresh install (nothing stored) still names every preset
  	 * -- and requires no capability at all, matching `export`'s own
  	 * read-only precedent.
  	 */
  	public function test_list_names_every_preset_when_nothing_is_stored(): void {
  		( new Blueline_Settings_Command() )->occasions( array( 'list' ), array() );

  		$output = $this->cli_output();

  		$this->assertStringContainsString( 'canada-day', $output );
  		$this->assertStringContainsString( 'remembrance-day', $output );
  		$this->assertStringContainsString( 'christmas', $output );
  		$this->assertStringContainsString( 'new-year', $output );
  	}

  	/**
  	 * `list` reports a stored occasion's id/label/type/mode, and no
  	 * longer lists that same id under "presets not yet stored".
  	 */
  	public function test_list_reports_a_stored_occasion_and_excludes_it_from_presets(): void {
  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '',
  						'motif'  => 'maple-leaf',
  						'line'   => '',
  						'mode'   => 'force_on',
  					),
  				),
  			)
  		);

  		( new Blueline_Settings_Command() )->occasions( array( 'list' ), array() );

  		$output = $this->cli_output();

  		$this->assertStringContainsString( 'label=Canada Day', $output );
  		$this->assertStringContainsString( 'mode=force_on', $output );

  		$presets_section = substr( $output, (int) strpos( $output, 'Presets not yet stored' ) );
  		$this->assertStringNotContainsString( 'canada-day', $presets_section );
  	}

  	/**
  	 * `enable` refuses without `manage_options`, writing nothing.
  	 */
  	public function test_enable_refuses_without_manage_options(): void {
  		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

  		try {
  			( new Blueline_Settings_Command() )->occasions( array( 'enable', 'canada-day' ), array() );
  		} finally {
  			$this->assertSame( false, get_option( BLUELINE_SETTINGS_OPTION, false ) );
  		}
  	}

  	/**
  	 * `enable` on a preset id not yet stored materializes it with
  	 * mode=auto -- the CLI equivalent of the panel's "add from preset".
  	 */
  	public function test_enable_materializes_a_preset_with_auto_mode(): void {
  		$this->grant_manage_options();

  		( new Blueline_Settings_Command() )->occasions( array( 'enable', 'canada-day' ), array() );

  		$stored = blueline_settings( 'occasions' );

  		$this->assertArrayHasKey( 'canada-day', $stored );
  		$this->assertSame( 'auto', $stored['canada-day']['mode'] );
  		$this->assertSame( 'Canada Day', $stored['canada-day']['label'] );
  	}

  	/**
  	 * `disable` on an already-stored occasion sets force_off, preserving
  	 * every other field.
  	 */
  	public function test_disable_sets_force_off_on_a_stored_occasion(): void {
  		$this->grant_manage_options();

  		update_option(
  			BLUELINE_SETTINGS_OPTION,
  			array(
  				'occasions' => array(
  					'canada-day' => array(
  						'id'     => 'canada-day',
  						'label'  => 'Canada Day',
  						'type'   => 'decorative',
  						'window' => array( 'start_md' => '07-01', 'end_md' => '07-01' ),
  						'accent' => '#ffffff',
  						'motif'  => 'maple-leaf',
  						'line'   => '',
  						'mode'   => 'auto',
  					),
  				),
  			)
  		);

  		( new Blueline_Settings_Command() )->occasions( array( 'disable', 'canada-day' ), array() );

  		$stored = blueline_settings( 'occasions' );

  		$this->assertSame( 'force_off', $stored['canada-day']['mode'] );
  		$this->assertSame( '#ffffff', $stored['canada-day']['accent'] );
  	}

  	/**
  	 * `force` sets force_on, whether materializing a preset or updating
  	 * an existing stored occasion.
  	 */
  	public function test_force_sets_force_on(): void {
  		$this->grant_manage_options();

  		( new Blueline_Settings_Command() )->occasions( array( 'force', 'christmas' ), array() );

  		$stored = blueline_settings( 'occasions' );

  		$this->assertSame( 'force_on', $stored['christmas']['mode'] );
  	}

  	/**
  	 * An id matching neither a stored occasion nor a preset is a
  	 * WP_CLI::error(), never a silent no-op.
  	 */
  	public function test_enable_an_unknown_id_errors(): void {
  		$this->grant_manage_options();

  		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

  		( new Blueline_Settings_Command() )->occasions( array( 'enable', 'not-a-real-id' ), array() );
  	}

  	/**
  	 * An unrecognised action (neither list, enable, disable, nor force)
  	 * errors.
  	 */
  	public function test_an_unrecognised_action_errors(): void {
  		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

  		( new Blueline_Settings_Command() )->occasions( array( 'delete', 'canada-day' ), array() );
  	}

  	/**
  	 * `enable` with no id at all (a missing positional argument) errors
  	 * rather than fataling on an undefined array key.
  	 */
  	public function test_enable_with_no_id_errors(): void {
  		$this->grant_manage_options();

  		$this->expectException( Blueline_Test_Cli_Exit_Exception::class );

  		( new Blueline_Settings_Command() )->occasions( array( 'enable' ), array() );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsCliCommandTest` — Expected: FAIL with "Call to undefined method Blueline_Settings_Command::occasions()".

- [ ] **Step 3: Write minimal implementation**

  In `inc/cli/settings-command.php`, locate this exact block (the end of `flush_cache()`, immediately before the class's closing `}`):

  ```php
  		$command = blueline_cache_purge_command( blueline_cache_purge_host() );

  		WP_CLI::log(
  			'' !== $command
  				? $command
  				: blueline_cache_purge_unresolvable_host_message()
  		);

  		WP_CLI::error( __( 'Nothing was purged. Run the command above on the server.', 'blueline' ) );
  	}
  }

  WP_CLI::add_command( 'blueline settings', 'Blueline_Settings_Command' );
  ```

  Replace it with:

  ```php
  		$command = blueline_cache_purge_command( blueline_cache_purge_host() );

  		WP_CLI::log(
  			'' !== $command
  				? $command
  				: blueline_cache_purge_unresolvable_host_message()
  		);

  		WP_CLI::error( __( 'Nothing was purged. Run the command above on the server.', 'blueline' ) );
  	}

  	/**
  	 * `wp blueline settings occasions list|enable <id>|disable <id>|force <id>`
  	 * -- the original spec's §6.9 command list's `occasions` entry, ruled
  	 * on by design spec §6.5: one subcommand taking an action positional
  	 * argument, not four separately-registered subcommands, matching how
  	 * the spec itself names it as a single command with four listed
  	 * behaviors.
  	 *
  	 * Verb-to-mode mapping (design spec §6.5, the three
  	 * blueline_occasion_modes() values with no fourth invented): `enable`
  	 * -> `auto` (let it run on its own calendar window -- the "normal"
  	 * state), `disable` -> `force_off` (never eligible, regardless of
  	 * window), `force` -> `force_on` (always eligible, "preview it now" --
  	 * the same phrase blueline_occasion_modes()'s own docblock already
  	 * uses for `force_on`).
  	 *
  	 * `enable`/`disable`/`force` on an <id> present in
  	 * blueline_occasion_presets() but absent from the currently STORED
  	 * `occasions` map copies that preset in first (with the requested mode
  	 * applied) -- the CLI equivalent of the panel's "add from preset"
  	 * affordance (2.1b); refusing to would make the CLI strictly less
  	 * capable than the panel already is, for no stated reason. An <id>
  	 * matching neither a stored occasion nor a preset is a WP_CLI::error(),
  	 * matching every other subcommand's not-found handling convention in
  	 * this file.
  	 *
  	 * `list` requires no capability at all -- read-only, matching
  	 * `export`'s own precedent above (and validated BEFORE any capability
  	 * check, since rejecting an unrecognised action needs no authorization
  	 * decision at all). `enable`/`disable`/`force` require
  	 * `manage_options`, matching every mutating subcommand in this file,
  	 * since each one writes to the live settings option.
  	 *
  	 * The actual write goes through the same
  	 * `update_option( BLUELINE_SETTINGS_OPTION, ... )` ->
  	 * `sanitize_option_{$option}` path every other subcommand in this file
  	 * uses: `_tab` is never set on this write, so
  	 * blueline_settings_sanitize_callback()'s `occasions` branch takes its
  	 * pre-existing "programmatic write" path (blueline_sanitize_occasions()
  	 * directly, no id derivation) -- unchanged 2.1a behaviour, since this
  	 * command always supplies an already-correctly-keyed id itself.
  	 *
  	 * ## OPTIONS
  	 *
  	 * <action>
  	 * : One of "list", "enable", "disable", "force".
  	 *
  	 * [<id>]
  	 * : The occasion id. Required for enable/disable/force; ignored for list.
  	 *
  	 * ## EXAMPLES
  	 *
  	 *     wp blueline settings occasions list
  	 *     wp blueline settings occasions enable canada-day
  	 *     wp blueline settings occasions disable canada-day
  	 *     wp blueline settings occasions force christmas --user=admin
  	 *
  	 * @param array<int, string>    $args       Positional arguments: [ $action, $id? ].
  	 * @param array<string, string> $assoc_args Associative arguments (unused).
  	 * @return void
  	 */
  	public function occasions( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP_CLI_Command's dispatch contract; this subcommand takes no associative argument.
  		$action = (string) ( $args[0] ?? '' );

  		if ( 'list' === $action ) {
  			$this->occasions_list();
  			return;
  		}

  		$mode_map = array(
  			'enable'  => 'auto',
  			'disable' => 'force_off',
  			'force'   => 'force_on',
  		);

  		if ( ! isset( $mode_map[ $action ] ) ) {
  			WP_CLI::error( sprintf( '"%s" is not a recognised action. Use list, enable, disable, or force.', $action ) );
  			return;
  		}

  		if ( ! current_user_can( 'manage_options' ) ) {
  			WP_CLI::error( __( 'The current user is not allowed to manage_options. Re-run with --user=<an administrator>.', 'blueline' ) );
  			return;
  		}

  		$id = (string) ( $args[1] ?? '' );

  		if ( '' === $id ) {
  			WP_CLI::error( 'An occasion id is required for this action.' );
  			return;
  		}

  		$stored = blueline_settings( 'occasions' );
  		$stored = is_array( $stored ) ? $stored : array();

  		if ( isset( $stored[ $id ] ) && is_array( $stored[ $id ] ) ) {
  			$occasion = $stored[ $id ];
  		} else {
  			$presets = blueline_occasion_presets();

  			if ( ! isset( $presets[ $id ] ) ) {
  				WP_CLI::error( sprintf( '"%s" is not a stored occasion or a known preset. Run "wp blueline settings occasions list" to see both.', $id ) );
  				return;
  			}

  			// The CLI equivalent of the panel's "add from preset"
  			// affordance -- materialize the preset into the real stored
  			// map on first use.
  			$occasion = $presets[ $id ];
  		}

  		$occasion['mode'] = $mode_map[ $action ];
  		$stored[ $id ]    = $occasion;

  		$current_option              = get_option( BLUELINE_SETTINGS_OPTION, array() );
  		$current_option              = is_array( $current_option ) ? $current_option : array();
  		$current_option['occasions'] = $stored;

  		update_option( BLUELINE_SETTINGS_OPTION, $current_option );

  		WP_CLI::success( sprintf( '"%s" is now %s.', $id, $mode_map[ $action ] ) );
  	}

  	/**
  	 * `occasions list`'s body: every currently stored occasion, then every
  	 * preset (blueline_occasion_presets()) not already materialized into
  	 * the stored map -- id/label/type/mode for each.
  	 *
  	 * @return void
  	 */
  	private function occasions_list(): void {
  		$stored = blueline_settings( 'occasions' );
  		$stored = is_array( $stored ) ? $stored : array();

  		if ( array() === $stored ) {
  			WP_CLI::log( __( 'No occasions are stored yet.', 'blueline' ) );
  		} else {
  			WP_CLI::log( __( 'Stored occasions:', 'blueline' ) );
  			foreach ( $stored as $id => $occasion ) {
  				WP_CLI::log(
  					sprintf(
  						'  %s  label=%s  type=%s  mode=%s',
  						str_pad( (string) $id, 20 ),
  						(string) ( $occasion['label'] ?? '' ),
  						(string) ( $occasion['type'] ?? '' ),
  						(string) ( $occasion['mode'] ?? '' )
  					)
  				);
  			}
  		}

  		$unmaterialized = array_diff_key( blueline_occasion_presets(), $stored );

  		if ( array() !== $unmaterialized ) {
  			WP_CLI::log( '' );
  			WP_CLI::log( __( 'Presets not yet stored (enable/disable/force materializes one):', 'blueline' ) );
  			foreach ( $unmaterialized as $id => $preset ) {
  				WP_CLI::log(
  					sprintf(
  						'  %s  label=%s',
  						str_pad( (string) $id, 20 ),
  						(string) ( $preset['label'] ?? '' )
  					)
  				);
  			}
  		}
  	}
  }

  WP_CLI::add_command( 'blueline settings', 'Blueline_Settings_Command' );
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsCliCommandTest` — Expected: PASS.

- [ ] **Step 5: Run the whole suite**

  Run: `composer test` — Expected: PASS. This subcommand writes through `update_option()` → `blueline_settings_sanitize_callback()`, the same choke point Task 1 touched — confirm nothing regressed.

- [ ] **Step 6: Commit**
  ```bash
  git add inc/cli/settings-command.php tests/OccasionsCliCommandTest.php
  git commit -m "Add the wp blueline settings occasions WP-CLI subcommand"
  ```

---

### Task 6: Full verification

**Files:**
- (none — verification only)

**Interfaces:**
- Consumes: everything produced by Tasks 1-5.
- Produces: a confirmed-green `npm run check` for this phase, closing out the P2 initiative.

- [ ] **Step 1: Run the full gate**

  Run: `npm run check` — Expected: PASS (`lint:css`, `lint:js`, `test:js`, `tokens:check`, `composer test`, `composer lint` all green). `lint:css`/`lint:js`/`test:js`/`tokens:check` should be unaffected by anything in this plan (no CSS, JS, or token changes); if any of them fails, the failure is a pre-existing condition on this branch, not something Tasks 1-5 introduced — investigate before assuming otherwise.

- [ ] **Step 2: If `composer lint` (phpcs) reports anything in the modified files**

  Fix any WordPress-Coding-Standards nit it finds (docblock alignment, spacing, array alignment) with a line-level `phpcs:ignore <sniff> -- <reason>` only where the sniff is flagging something deliberate — never `phpcs:ignoreFile`, and never stack a second annotation on a line that already has a trailing one.

- [ ] **Step 3: Confirm no test from Tasks 1-5 regressed**

  Run: `composer test` — Expected: PASS, full suite (not just this phase's new files). Tasks 1, 3, and 5 all write through `blueline_settings_sanitize_callback()`, a shared choke point every settings save reaches — exactly the kind of change that can retroactively break an existing test reaching the same path.

- [ ] **Step 4: Confirm the suite passes with non-default state actively exercised, not only at defaults**

  Per the design spec's §7 ("whole-suite, every phase" testing requirement): this plan's own tests already exercise a stored occasion, a live acknowledgement in each of its three classifications (`valid`/`orphaned`/`stale`), a detected drift with its notice payload set, and a materialized preset via WP-CLI (Tasks 2-5's new test files, plus Task 1's `SettingsPageTest`/`SettingsInputsHashTest` additions), so Step 3's full run already covers this — no separate action needed here beyond confirming Step 3 actually passed with those files included, not skipped by an over-narrow `--filter`.

- [ ] **Step 5: Manual smoke check (optional but recommended before merging)**

  On a real WordPress install with an occasion whose accent fails contrast and a live, matching acknowledgement (created via the Occasions panel, 2.1b): edit `contrast-rules.json`'s `thresholds.body` (or touch `style.css`'s mtime) to force a drift, then load any wp-admin screen as an administrator. Confirm: the deploy-drift notice appears exactly once, naming the occasion by its label and stating it needs re-review; reloading the same screen again does NOT show it a second time; Tools → Site Health → Info → Blueline shows the active occasion and the acknowledgement's line marked "(needs re-review)"; `wp blueline settings occasions list` shows the same occasion and every preset not yet stored; `wp blueline settings occasions force christmas` (or any preset id) materializes it with `mode=force_on` and is visible immediately afterward in both the panel and a subsequent `list`.

- [ ] **Step 6: Commit** — nothing to commit; this task is verification-only. If Step 2 required a fix, that fix was already committed as part of its own task before reaching here; re-run Step 1 to confirm.
