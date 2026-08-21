# Blueline P2 Phase 2.0 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation Phase 2.1 (Occasions) and Phase 2.2 (deploy drift, Site Health) will stand on — the token tunability manifest, the one new `--bl-occasion-accent` token, the shared settings-inputs hash, and real `aa_acknowledgements` storage with its generic record/covers mechanism — without building any of the Occasions model, panel UI, or deploy-drift logic those later phases own.

**Architecture:** Two small read-only PHP modules (`inc/settings/tokens.php`, `inc/occasions.php`) mirror the defensive-fallback pattern `inc/team-colors.php` already established for reading a committed JSON/CSS file at runtime — never fatal, log-and-fall-back on any malformed input. A third module (`inc/settings/validation.php`) computes one shared hash from two existing inputs (`blueline_stylesheet_version()` and `contrast-rules.json`'s rules/thresholds). A fourth module (`inc/settings/acknowledgements.php`) gives `aa_acknowledgements` real storage, protected in the settings save-merge pipeline the exact same way `_schema` already is, plus pure record/check/invalidate functions Phase 2.1's resolver will call once a real Occasion model exists. `tools/tokens.json` and two new `contrast-rules.json` rules are the only new committed data files.

**Tech Stack:** PHP 8.1+ (PHPUnit 12 against `tests/bootstrap.php`'s WordPress stubs), Node.js (`node:test`) for the CSS/token round-trip guard already living in `tools/`.

**Spec:** docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md (§4 specifically)

## Global Constraints

- `phpcs:ignoreFile` is forbidden. Only line-level `phpcs:ignore <sniff> -- <reason>`, and a trailing annotation on a line REPLACES a preceding-line one silently — never stack two expecting both to apply.
- Every `sanitize_option_{$option}`-equivalent validator this phase touches must keep running unconditionally at file scope (already true of `blueline_settings_sanitize_callback()`), so WP-CLI and a direct `update_option()` call are validated exactly like the panel — this phase must not weaken that.
- No admin notice may render as a bare `<div>` (`NoticeDivGuardTest`) — not directly relevant to this phase (no UI), but no new notice-rendering code may sneak in that violates it.
- `$wpdb` only via `prepare()`; escape only at the point of echo — not applicable to this phase's code (no direct DB queries, no rendering), noted so nothing added here violates it incidentally.
- Never write a comment or docblock claiming a test proves something that isn't actually true, or that code does something it doesn't — verify before asserting. This project's dominant historical defect (P1b decision record §4) is exactly this.
- A guard added to a write path retroactively threatens every test that reached the guarded value through that path (P1b decision record §3.1) — when this phase adds a new sanitizer branch (`aa_acknowledgements`), re-run the whole suite, not just the new tests.
- A notice test that does not assert on rendered markup cannot see how the notice looks (P1b decision record §3.2) — not applicable this phase (no notice UI), kept here for the implementer's awareness since Phase 2.1 will build exactly that.
- No general-purpose PHP `:root` CSS parser. `blueline_occasion_accent_default()` resolves exactly one `var()` hop for exactly one token — nothing more general.
- `blueline_settings`'s storage shape stays flat. `aa_acknowledgements` is a new top-level key, not a nested structure.
- Out of scope for this plan: `inc/team-colors.php` (already correct, already tested — do not touch), the Occasions model/schema/panel UI/resolution/motifs/cron purge/editor parity (Phase 2.1), `_validated_against`/Site Health fields/WP-CLI additions (Phase 2.2).
- Every new `.php` file under `inc/` must be `require_once`'d from `functions.php`, or `tests/IncRequireCoverageTest.php` fails the build.
- No new `inc/` file may contain a bare top-level function call outside the allow-list (`add_action`/`add_filter`/`remove_action`/`remove_filter`/`defined`/`define`/`class_exists`/`function_exists`/`interface_exists`/`trait_exists`/`method_exists`/`WP_CLI::add_command`), or `tests/IncTopLevelCallGuardTest.php` fails the build.

---

### Task 1: The `--bl-occasion-accent` token, `tools/tokens.json`, and its two contrast rules

**Files:**
- Modify: `style.css` (`:root` block, end)
- Modify: `assets/src/css/editor.css` (`:root, .editor-styles-wrapper` block, end)
- Create: `tools/tokens.json`
- Modify: `tools/contrast-rules.json` (append two rules)
- Test: `tools/tokens-manifest.test.mjs`

**Interfaces:**
- Consumes: `extractRootTokens()`, `normalizeValue()` (`tools/lib/css-tokens.mjs`, pre-existing).
- Produces: the CSS custom property `--bl-occasion-accent` (declared value `var(--bl-ice)`) in both `style.css` and `assets/src/css/editor.css`; the committed file `tools/tokens.json` with a top-level `tokens` map keyed by every `--bl-*` token name; two new `contrast-rules.json` rule ids, `ink-on-occasion-accent` and `paper-not-text-on-occasion-accent`.

- [ ] **Step 1: Write the failing test**

  Create `tools/tokens-manifest.test.mjs`:

  ```js
  import { test } from 'node:test';
  import assert from 'node:assert/strict';
  import { readFileSync } from 'node:fs';
  import { fileURLToPath } from 'node:url';
  import { dirname, resolve } from 'node:path';
  import { extractRootTokens } from './lib/css-tokens.mjs';

  const here = dirname( fileURLToPath( import.meta.url ) );
  const styleCss = readFileSync( resolve( here, '../style.css' ), 'utf8' );
  const styleTokens = extractRootTokens( styleCss );

  const manifest = JSON.parse(
  	readFileSync( resolve( here, 'tokens.json' ), 'utf8' )
  );
  const { rules: contrastRules } = JSON.parse(
  	readFileSync( resolve( here, 'contrast-rules.json' ), 'utf8' )
  );

  test( 'tokens.json declares every --bl-* token style.css defines, and no others', () => {
  	const manifestNames = new Set( Object.keys( manifest.tokens ) );
  	const styleNames = new Set( styleTokens.keys() );

  	for ( const name of styleNames ) {
  		assert.ok( manifestNames.has( name ), `tokens.json is missing ${ name }` );
  	}
  	for ( const name of manifestNames ) {
  		assert.ok( styleNames.has( name ), `tokens.json declares ${ name }, which style.css does not define` );
  	}
  } );

  test( 'every manifest entry declares type, group, tier, bounds and tunable', () => {
  	for ( const [ name, entry ] of Object.entries( manifest.tokens ) ) {
  		assert.equal( typeof entry.type, 'string', `${ name }.type` );
  		assert.equal( typeof entry.group, 'string', `${ name }.group` );
  		assert.equal( typeof entry.tier, 'string', `${ name }.tier` );
  		assert.ok( 'bounds' in entry, `${ name }.bounds` );
  		assert.equal( typeof entry.tunable, 'boolean', `${ name }.tunable` );
  	}
  } );

  test( 'exactly one token is tunable, and it is --bl-occasion-accent', () => {
  	const tunable = Object.entries( manifest.tokens ).filter( ( [ , e ] ) => e.tunable );
  	assert.equal( tunable.length, 1 );
  	assert.equal( tunable[ 0 ][ 0 ], '--bl-occasion-accent' );
  } );

  test( '--bl-occasion-accent defaults to var(--bl-ice) in style.css', () => {
  	assert.equal( styleTokens.get( '--bl-occasion-accent' ), 'var(--bl-ice)' );
  } );

  test( "--bl-occasion-accent's declared bounds name real contrast-rules.json rule ids", () => {
  	const ruleIds = new Set( contrastRules.map( ( r ) => r.id ) );
  	const bounds = manifest.tokens[ '--bl-occasion-accent' ].bounds;
  	assert.ok( Array.isArray( bounds.contrast_rules ) && bounds.contrast_rules.length > 0 );
  	for ( const id of bounds.contrast_rules ) {
  		assert.ok( ruleIds.has( id ), `bounds names rule "${ id }", which contrast-rules.json does not declare` );
  	}
  } );
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `npm run test:js` — Expected: FAIL (`tools/tokens.json` does not exist, or `--bl-occasion-accent` is not yet in `style.css`'s tokens).

- [ ] **Step 3: Write minimal implementation**

  Append to `style.css`'s `:root` block, immediately before its closing `}` (after `--bl-focus-offset: 2px;`):

  ```css
  	/*
  	 * Occasions — Phase 2.0 foundation. Exactly one new tunable colour
  	 * surface (see tools/tokens.json's "tunable" flag and the design
  	 * spec's §4.1/§4.2 for why every other token here stays non-tunable).
  	 * Defaults to --bl-ice: no occasion exists yet to override it (that is
  	 * Phase 2.1), so this must render identically to today until then.
  	 */
  	--bl-occasion-accent: var(--bl-ice);
  ```

  Append to `assets/src/css/editor.css`'s `:root, .editor-styles-wrapper` block, immediately before its closing `}` (after `--bl-radius: 0px;`):

  ```css
  	--bl-occasion-accent: var(--bl-ice);
  ```

  Create `tools/tokens.json`:

  ```json
  {
  	"$comment": "Tunability manifest: every --bl-* token in style.css's :root block, with a type/group/tier and whether the settings panel may ever expose it as an editable value at all. Every token defaults tunable:false; only --bl-occasion-accent is tunable, and that is a structural limit, not a promise the panel merely chooses to keep -- see docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.1. 'tier' is descriptive metadata, not an enforcement mechanism: brand (the raw sampled palette), derived (built from a brand token via var()), semantic (status colours), structural (spacing/type/radius/shadow/layout/focus -- non-colour design-system tokens), occasion (the one new tunable colour surface).",
  	"version": 1,
  	"tokens": {
  		"--bl-ink":            { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-ink-deep":       { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-ink-mid":        { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-accent-text":    { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-steel":          { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-ice":            { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-pale":           { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-paper":          { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },
  		"--bl-white":          { "type": "color",       "group": "brand",     "tier": "brand",      "bounds": null, "tunable": false },

  		"--bl-surface":        { "type": "color",       "group": "surface",   "tier": "derived",    "bounds": null, "tunable": false },
  		"--bl-surface-sunken": { "type": "color",       "group": "surface",   "tier": "derived",    "bounds": null, "tunable": false },
  		"--bl-surface-inverse": { "type": "color",      "group": "surface",   "tier": "derived",    "bounds": null, "tunable": false },
  		"--bl-border":         { "type": "color",       "group": "surface",   "tier": "derived",    "bounds": null, "tunable": false },
  		"--bl-border-strong":  { "type": "color",       "group": "surface",   "tier": "derived",    "bounds": null, "tunable": false },

  		"--bl-success":        { "type": "color",       "group": "semantic",  "tier": "semantic",   "bounds": null, "tunable": false },
  		"--bl-warning":        { "type": "color",       "group": "semantic",  "tier": "semantic",   "bounds": null, "tunable": false },
  		"--bl-danger":         { "type": "color",       "group": "semantic",  "tier": "semantic",   "bounds": null, "tunable": false },

  		"--bl-font-display":   { "type": "font-family", "group": "typography", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-font-body":      { "type": "font-family", "group": "typography", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-text-xs":        { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-text-sm":        { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-text-base":      { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-text-lg":        { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": { "min": "1.125rem", "max": "1.25rem" }, "tunable": false },
  		"--bl-text-xl":        { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": { "min": "1.375rem", "max": "1.75rem" }, "tunable": false },
  		"--bl-text-2xl":       { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": { "min": "1.75rem",  "max": "2.5rem" },  "tunable": false },
  		"--bl-text-3xl":       { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": { "min": "2.25rem",  "max": "3.75rem" }, "tunable": false },
  		"--bl-text-4xl":       { "type": "dimension",   "group": "typography", "tier": "structural", "bounds": { "min": "2.75rem",  "max": "5rem" },    "tunable": false },

  		"--bl-space-1":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-2":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-3":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-4":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-5":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-6":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-7":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-8":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-space-9":        { "type": "dimension",   "group": "spacing",   "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-skew":           { "type": "angle",       "group": "signature", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-band-ice":       { "type": "dimension",   "group": "signature", "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-band-ink":       { "type": "dimension",   "group": "signature", "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-radius":         { "type": "dimension",   "group": "radius",    "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-radius-card":    { "type": "dimension",   "group": "radius",    "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-shadow-card":    { "type": "shadow",      "group": "shadow",    "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-shadow-raised":  { "type": "shadow",      "group": "shadow",    "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-container":      { "type": "dimension",   "group": "layout",    "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-focus-width":    { "type": "dimension",   "group": "focus",     "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-focus-style":    { "type": "keyword",     "group": "focus",     "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-focus-color":    { "type": "color",       "group": "focus",     "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-focus-halo":     { "type": "color",       "group": "focus",     "tier": "structural", "bounds": null, "tunable": false },
  		"--bl-focus-offset":   { "type": "dimension",   "group": "focus",     "tier": "structural", "bounds": null, "tunable": false },

  		"--bl-occasion-accent": {
  			"type": "color",
  			"group": "occasion",
  			"tier": "occasion",
  			"bounds": { "contrast_rules": [ "ink-on-occasion-accent", "paper-not-text-on-occasion-accent" ] },
  			"tunable": true
  		}
  	}
  }
  ```

  Append two rules to `tools/contrast-rules.json`'s `rules` array, right after the existing `ice-not-text-on-light` entry:

  ```json
      { "id": "ink-on-occasion-accent",             "description": "ink text on the occasion-accent fill (CTA ribbon, signature band, motif)", "fg": "--bl-ink",   "bg": "--bl-occasion-accent", "min": 4.5 },
      { "id": "paper-not-text-on-occasion-accent",  "description": "--bl-occasion-accent correctly unusable as paper text (fill-only, like --bl-ice)", "fg": "--bl-paper", "bg": "--bl-occasion-accent", "max": 3.0 },
  ```

  These mirror `ink-on-ice` (a `min` rule pairing `--bl-ink` with a light fill) and `ice-not-text-on-light`/`border-not-text-on-white` (a `max` rule asserting a fill-only token is correctly unreadable as text) rather than `paper-on-ink` literally, because at `--bl-occasion-accent`'s default value (`var(--bl-ice)`, a light fill) paper-on-it computes to ~1.94:1 — asserting that as a `min` rule would make it fail today, and the task's own instruction is that this change must not break `npm run tokens:check`. The `max` framing is the correct, passing assertion for the same pairing: it states, and continues to verify on every future edit, that this fill-only token is *correctly* not usable as light text on it — exactly the guarantee `--bl-ice`'s own `ice-not-text-on-light` rule already gives its literal.

- [ ] **Step 4: Run test to verify it passes**

  Run: `npm run test:js` — Expected: PASS
  Run: `npm run tokens:check` — Expected: PASS, with new output lines `ok   ink text on the occasion-accent fill (CTA ribbon, signature band, motif): 7.69 (min 4.5)` and `ok   --bl-occasion-accent correctly unusable as paper text (fill-only, like --bl-ice): 1.94 (max 3.0)`, and the existing editor.css/team-colors.php parity checks still `ok`.

- [ ] **Step 5: Commit**
  ```bash
  git add style.css assets/src/css/editor.css tools/tokens.json tools/contrast-rules.json tools/tokens-manifest.test.mjs
  git commit -m "Add the --bl-occasion-accent token, tools/tokens.json manifest, and its two contrast rules"
  ```

---

### Task 2: `inc/settings/tokens.php` — the token manifest reader/validator

**Files:**
- Create: `inc/settings/tokens.php`
- Modify: `functions.php` (require chain)
- Test: `tests/SettingsTokensTest.php`

**Interfaces:**
- Consumes: `tools/tokens.json` (Task 1).
- Produces: `blueline_token_manifest( ?string $path_override = null ): array` and `blueline_token_is_tunable( string $token_name ): bool`, both callable from any later task with no arguments in production use.

- [ ] **Step 1: Write the failing test**

  Create `tests/SettingsTokensTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/settings/tokens.php';

  /**
   * Covers blueline_token_manifest()'s defensive read of tools/tokens.json
   * and blueline_token_is_tunable()'s lookup against it.
   */
  final class SettingsTokensTest extends TestCase {

  	/**
  	 * Asserts a missing manifest file falls back to an empty manifest
  	 * rather than fataling.
  	 */
  	public function test_missing_file_returns_empty_manifest(): void {
  		$this->assertSame( array(), blueline_token_manifest( '/nonexistent/tokens.json' ) );
  	}

  	/**
  	 * Asserts malformed JSON falls back to an empty manifest rather than
  	 * fataling.
  	 */
  	public function test_malformed_json_returns_empty_manifest(): void {
  		$path = sys_get_temp_dir() . '/blueline-tokens-malformed-' . uniqid( '', true ) . '.json';
  		file_put_contents( $path, '{ not valid json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.

  		try {
  			$this->assertSame( array(), blueline_token_manifest( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a file missing the top-level "tokens" object falls back to
  	 * an empty manifest rather than fataling.
  	 */
  	public function test_missing_tokens_key_returns_empty_manifest(): void {
  		$path = sys_get_temp_dir() . '/blueline-tokens-notokens-' . uniqid( '', true ) . '.json';
  		file_put_contents( $path, wp_json_encode( array( 'version' => 1 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.

  		try {
  			$this->assertSame( array(), blueline_token_manifest( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a well-formed manifest parses every entry's five keys, and
  	 * that a malformed individual entry (not an array) is dropped rather
  	 * than corrupting the whole read.
  	 */
  	public function test_parses_valid_entries_and_drops_a_malformed_one(): void {
  		$path = sys_get_temp_dir() . '/blueline-tokens-valid-' . uniqid( '', true ) . '.json';
  		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
  			$path,
  			wp_json_encode(
  				array(
  					'tokens' => array(
  						'--bl-ink'              => array( 'type' => 'color', 'group' => 'brand', 'tier' => 'brand', 'bounds' => null, 'tunable' => false ),
  						'--bl-occasion-accent'  => array( 'type' => 'color', 'group' => 'occasion', 'tier' => 'occasion', 'bounds' => array( 'contrast_rules' => array( 'ink-on-occasion-accent' ) ), 'tunable' => true ),
  						'--bl-broken'           => 'not an array',
  					),
  				)
  			)
  		);

  		try {
  			$manifest = blueline_token_manifest( $path );

  			$this->assertArrayNotHasKey( '--bl-broken', $manifest );
  			$this->assertFalse( $manifest['--bl-ink']['tunable'] );
  			$this->assertTrue( $manifest['--bl-occasion-accent']['tunable'] );
  			$this->assertSame(
  				array( 'contrast_rules' => array( 'ink-on-occasion-accent' ) ),
  				$manifest['--bl-occasion-accent']['bounds']
  			);
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts blueline_token_is_tunable() reads the manifest correctly for
  	 * a tunable token, a declared non-tunable token, and an unknown name.
  	 */
  	public function test_is_tunable_reads_the_real_manifest(): void {
  		$this->assertTrue( blueline_token_is_tunable( '--bl-occasion-accent' ) );
  		$this->assertFalse( blueline_token_is_tunable( '--bl-ink' ) );
  		$this->assertFalse( blueline_token_is_tunable( '--bl-does-not-exist' ) );
  	}

  	/**
  	 * Asserts the real, committed tools/tokens.json marks exactly one
  	 * token tunable.
  	 */
  	public function test_real_manifest_has_exactly_one_tunable_token(): void {
  		$manifest = blueline_token_manifest();
  		$tunable  = array_filter( $manifest, static fn( $entry ) => $entry['tunable'] );

  		$this->assertCount( 1, $tunable );
  		$this->assertArrayHasKey( '--bl-occasion-accent', $tunable );
  	}

  	/**
  	 * Asserts the read-failure trace hook never fatals and is safe to call
  	 * repeatedly.
  	 */
  	public function test_read_failure_hook_never_fatals(): void {
  		blueline_token_manifest_read_failure( '/nonexistent/tokens.json', 'missing or unreadable' );
  		blueline_token_manifest_read_failure( '/nonexistent/tokens.json', 'missing or unreadable' );

  		$this->addToAssertionCount( 1 );
  	}
  }
  ```

  This test requires `wp_json_encode()`, which `tests/bootstrap.php` does not stub (only `tests/cli-stubs.php` does). Modify this test file's own require block — no other file needs it — by requiring that stub file too:

  ```php
  require_once __DIR__ . '/cli-stubs.php';
  require_once __DIR__ . '/../inc/settings/tokens.php';
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsTokensTest` — Expected: FAIL with "Call to undefined function blueline_token_manifest()".

- [ ] **Step 3: Write minimal implementation**

  Create `inc/settings/tokens.php`:

  ```php
  <?php
  /**
   * Design-token tunability manifest reader.
   *
   * tools/tokens.json is the committed manifest declaring, for every --bl-*
   * custom property in style.css's :root block, its type/group/tier/bounds
   * and whether the settings panel is ever allowed to expose it as an
   * editable value at all ("tunable"). Every token defaults to non-tunable;
   * only --bl-occasion-accent is marked tunable -- a structural limit, not
   * a promise the panel merely chooses to keep. See
   * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.1.
   *
   * @package blueline
   */

  defined( 'ABSPATH' ) || exit;

  /**
   * Log, at most once per call site, that tools/tokens.json could not be
   * used, mirroring inc/team-colors.php's blueline_contrast_rules_read_failure():
   * never a fatal, never a _doing_it_wrong() notice a visitor could see --
   * just a server-log trace of a silently degraded fallback.
   *
   * @param string $path   The tokens.json path that could not be used.
   * @param string $reason Human-readable reason, for the log line.
   * @return void
   */
  function blueline_token_manifest_read_failure( string $path, string $reason ): void {
  	static $logged = false;

  	if ( $logged ) {
  		return;
  	}
  	$logged = true;

  	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: mirrors inc/team-colors.php's blueline_contrast_rules_read_failure(), whose own docblock explains why a silent fallback should still leave a server-log trace.
  	error_log(
  		sprintf(
  			'Blueline: tools/tokens.json unusable (%s) at %s -- falling back to an empty token manifest.',
  			$reason,
  			$path
  		)
  	);
  }

  /**
   * Parse tools/tokens.json at $path into its `tokens` map, dropping any
   * entry that is not an array so one malformed row cannot corrupt the
   * whole read.
   *
   * @param string $path Path to a tokens.json file.
   * @return array<string, array{type:string, group:string, tier:string, bounds:mixed, tunable:bool}>
   */
  function blueline_token_manifest_parse_from_path( string $path ): array {
  	if ( ! is_readable( $path ) ) {
  		blueline_token_manifest_read_failure( $path, 'file is missing or unreadable' );
  		return array();
  	}

  	$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
  	$json = json_decode( (string) $raw, true );

  	if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
  		blueline_token_manifest_read_failure( $path, 'invalid JSON (' . json_last_error_msg() . ')' );
  		return array();
  	}

  	if ( ! is_array( $json ) || ! isset( $json['tokens'] ) || ! is_array( $json['tokens'] ) ) {
  		blueline_token_manifest_read_failure( $path, 'missing or non-object top-level "tokens"' );
  		return array();
  	}

  	$tokens = array();

  	foreach ( $json['tokens'] as $name => $entry ) {
  		if ( ! is_string( $name ) || '' === $name || ! is_array( $entry ) ) {
  			continue;
  		}

  		$tokens[ $name ] = array(
  			'type'    => is_string( $entry['type'] ?? null ) ? $entry['type'] : '',
  			'group'   => is_string( $entry['group'] ?? null ) ? $entry['group'] : '',
  			'tier'    => is_string( $entry['tier'] ?? null ) ? $entry['tier'] : '',
  			'bounds'  => $entry['bounds'] ?? null,
  			'tunable' => ! empty( $entry['tunable'] ),
  		);
  	}

  	return $tokens;
  }

  /**
   * The parsed tools/tokens.json manifest, cached for the lifetime of the
   * request when read from its real, default path.
   *
   * @param string|null $path_override Explicit path, for tests. Defaults to
   *                                    the real tools/tokens.json next to
   *                                    this theme. Bypasses the cache: a
   *                                    test that passes an override always
   *                                    gets a fresh read.
   * @return array<string, array{type:string, group:string, tier:string, bounds:mixed, tunable:bool}>
   */
  function blueline_token_manifest( ?string $path_override = null ): array {
  	static $cache = null;

  	if ( null === $path_override && null !== $cache ) {
  		return $cache;
  	}

  	$path = $path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__, 2 ) ) . '/tools/tokens.json' );

  	$tokens = blueline_token_manifest_parse_from_path( $path );

  	if ( null === $path_override ) {
  		$cache = $tokens;
  	}

  	return $tokens;
  }

  /**
   * Whether $token_name is marked tunable in tools/tokens.json.
   *
   * An unknown token name, and any lookup made while the manifest itself is
   * unavailable, both answer false -- the same fail-closed default every
   * declared token already carries unless explicitly marked otherwise.
   *
   * @param string $token_name Token name, e.g. '--bl-occasion-accent'.
   * @return bool
   */
  function blueline_token_is_tunable( string $token_name ): bool {
  	$manifest = blueline_token_manifest();

  	return ! empty( $manifest[ $token_name ]['tunable'] );
  }
  ```

  Modify `functions.php`: add, after the `inc/settings/site-health.php` require line:

  ```php
  require_once BLUELINE_DIR . '/inc/settings/tokens.php';
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsTokensTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/tokens.php functions.php tests/SettingsTokensTest.php
  git commit -m "Add blueline_token_manifest()/blueline_token_is_tunable(), reading tools/tokens.json"
  ```

---

### Task 3: `inc/occasions.php` — `blueline_occasion_accent_default()`

**Files:**
- Create: `inc/occasions.php`
- Modify: `functions.php` (require chain)
- Test: `tests/OccasionsTest.php`

**Interfaces:**
- Consumes: `--bl-occasion-accent: var(--bl-ice);` and `--bl-ice: #74C0E1;` in `style.css`'s `:root` block (Task 1).
- Produces: `blueline_occasion_accent_default( ?string $path_override = null ): string`, returning a lowercase `#rrggbb` or `''`.

- [ ] **Step 1: Write the failing test**

  Create `tests/OccasionsTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/occasions.php';

  /**
   * Covers blueline_occasion_accent_default()'s narrow, one-hop resolution
   * of --bl-occasion-accent's declared default against a real or fixture
   * style.css.
   */
  final class OccasionsTest extends TestCase {

  	/**
  	 * Writes a minimal fixture stylesheet and returns its path.
  	 *
  	 * @param string $css Fixture CSS content.
  	 * @return string Path to the fixture file.
  	 */
  	private function write_fixture( string $css ): string {
  		$path = sys_get_temp_dir() . '/blueline-occasions-' . uniqid( '', true ) . '.css';
  		file_put_contents( $path, $css ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
  		return $path;
  	}

  	/**
  	 * Asserts the real, committed style.css resolves to --bl-ice's real
  	 * literal value.
  	 */
  	public function test_resolves_the_real_stylesheet(): void {
  		$this->assertSame( '#74c0e1', blueline_occasion_accent_default() );
  	}

  	/**
  	 * Asserts a minimal fixture with a different --bl-ice value resolves
  	 * correctly, proving this reads the referenced token's OWN value
  	 * rather than a hardcoded literal.
  	 */
  	public function test_resolves_a_fixture_with_a_different_ice_value(): void {
  		$path = $this->write_fixture(
  			":root {\n\t--bl-ice: #123456;\n\t--bl-occasion-accent: var(--bl-ice);\n}"
  		);

  		try {
  			$this->assertSame( '#123456', blueline_occasion_accent_default( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a mention of --bl-occasion-accent inside a comment is not
  	 * mistaken for the real declaration -- the same regression
  	 * tools/lib/css-tokens.mjs's own test suite guards against.
  	 */
  	public function test_ignores_a_mention_inside_a_comment(): void {
  		$path = $this->write_fixture(
  			"/* --bl-occasion-accent: var(--bl-danger); */\n:root {\n\t--bl-ice: #abcdef;\n\t--bl-occasion-accent: var(--bl-ice);\n}"
  		);

  		try {
  			$this->assertSame( '#abcdef', blueline_occasion_accent_default( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a missing stylesheet returns '' rather than fataling.
  	 */
  	public function test_returns_empty_string_when_file_missing(): void {
  		$this->assertSame( '', blueline_occasion_accent_default( '/nonexistent/style.css' ) );
  	}

  	/**
  	 * Asserts a stylesheet with no :root rule at all returns '' rather
  	 * than fataling.
  	 */
  	public function test_returns_empty_string_when_no_root_rule(): void {
  		$path = $this->write_fixture( 'body { color: red; }' );

  		try {
  			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a --bl-occasion-accent declared as a literal (not a single
  	 * var() reference) returns '' rather than guessing.
  	 */
  	public function test_returns_empty_string_when_not_declared_as_a_var_reference(): void {
  		$path = $this->write_fixture( ":root {\n\t--bl-occasion-accent: #74c0e1;\n}" );

  		try {
  			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a referenced token that is not itself a plain hex literal
  	 * (here, undeclared entirely) returns '' rather than guessing.
  	 */
  	public function test_returns_empty_string_when_referenced_token_is_not_a_hex_literal(): void {
  		$path = $this->write_fixture( ":root {\n\t--bl-occasion-accent: var(--bl-undeclared);\n}" );

  		try {
  			$this->assertSame( '', blueline_occasion_accent_default( $path ) );
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts the read-failure trace hook never fatals and is safe to call
  	 * repeatedly.
  	 */
  	public function test_read_failure_hook_never_fatals(): void {
  		blueline_occasion_accent_default_read_failure( 'missing or unreadable' );
  		blueline_occasion_accent_default_read_failure( 'missing or unreadable' );

  		$this->addToAssertionCount( 1 );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter OccasionsTest` — Expected: FAIL with "Call to undefined function blueline_occasion_accent_default()".

- [ ] **Step 3: Write minimal implementation**

  Create `inc/occasions.php`:

  ```php
  <?php
  /**
   * Occasions -- Phase 2.0 foundation only.
   *
   * Hosts blueline_occasion_accent_default(): resolving --bl-occasion-accent's
   * own declared default (`var(--bl-ice)`) to --bl-ice's literal hex value,
   * by reading style.css's :root block directly.
   *
   * Deliberately NOT a general :root parser. This resolves exactly one
   * var() hop for exactly one token -- everything Phase 2.0 needs. See
   * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.1:
   * a general recursive :root parser was explicitly descoped as premature
   * generality for a need that does not exist yet. A future phase that
   * needs to resolve arbitrary :root tokens at runtime should build that
   * parser then, against a second real caller.
   *
   * The Occasion model, scheduling, resolution, motifs, cron boundary
   * purge, and editor parity described in the design spec's §7/§5 (Phase
   * 2.1) do NOT live here yet.
   *
   * @package blueline
   */

  defined( 'ABSPATH' ) || exit;

  /**
   * Log, at most once per call site, that style.css's :root block could not
   * be read to resolve --bl-occasion-accent's default. Mirrors
   * inc/team-colors.php's blueline_contrast_rules_read_failure(): never a
   * fatal, never a _doing_it_wrong() notice a visitor could see, just a
   * server-log trace of a silently degraded fallback.
   *
   * @param string $reason Human-readable reason, for the log line.
   * @return void
   */
  function blueline_occasion_accent_default_read_failure( string $reason ): void {
  	static $logged = false;

  	if ( $logged ) {
  		return;
  	}
  	$logged = true;

  	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: mirrors inc/team-colors.php's blueline_contrast_rules_read_failure() -- see that function's own docblock for why a silent fallback should still leave a server-log trace.
  	error_log(
  		sprintf( "Blueline: could not resolve --bl-occasion-accent's default from style.css (%s).", $reason )
  	);
  }

  /**
   * Resolve --bl-occasion-accent's own declared default to a literal hex
   * value, by reading style.css's :root block directly.
   *
   * style.css declares `--bl-occasion-accent: var(--bl-ice);` -- one var()
   * hop to a token that is itself a plain hex literal. This follows exactly
   * that one hop and no more: it does not resolve clamp(), rgba(), or a
   * chain of more than one var(). Never fatal: a missing file, a missing
   * :root rule, a --bl-occasion-accent declaration that is not a single
   * var(--bl-*) reference, or a referenced token that is not itself a plain
   * 6-digit hex literal all log (via
   * blueline_occasion_accent_default_read_failure()) and return ''.
   *
   * @param string|null $path_override Explicit path to a stylesheet, for
   *                                    tests. Defaults to the real
   *                                    style.css next to this theme.
   * @return string Lowercase `#rrggbb`, or '' if it could not be resolved.
   */
  function blueline_occasion_accent_default( ?string $path_override = null ): string {
  	$path = $path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__ ) ) . '/style.css' );

  	if ( ! is_readable( $path ) ) {
  		blueline_occasion_accent_default_read_failure( 'stylesheet is missing or unreadable' );
  		return '';
  	}

  	$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.

  	// Strip comments first so a mention inside one cannot be mistaken for
  	// a real declaration.
  	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

  	if ( ! preg_match( '/:root\s*\{(.*?)\}/s', $css, $root_match ) ) {
  		blueline_occasion_accent_default_read_failure( 'no :root rule found' );
  		return '';
  	}

  	$root_block = $root_match[1];

  	if ( ! preg_match( '/--bl-occasion-accent\s*:\s*var\(\s*(--[a-z0-9-]+)\s*\)\s*;/i', $root_block, $ref_match ) ) {
  		blueline_occasion_accent_default_read_failure( '--bl-occasion-accent is not declared as a single var(--bl-*) reference' );
  		return '';
  	}

  	$referenced = $ref_match[1];

  	if ( ! preg_match( '/' . preg_quote( $referenced, '/' ) . '\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $root_block, $hex_match ) ) {
  		blueline_occasion_accent_default_read_failure( "referenced token {$referenced} is not declared as a plain hex literal" );
  		return '';
  	}

  	return strtolower( $hex_match[1] );
  }
  ```

  Modify `functions.php`: add, immediately after the `inc/team-colors.php` require line:

  ```php
  require_once BLUELINE_DIR . '/inc/occasions.php';
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter OccasionsTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/occasions.php functions.php tests/OccasionsTest.php
  git commit -m "Add blueline_occasion_accent_default(), a narrow one-hop :root resolver"
  ```

---

### Task 4: `inc/settings/validation.php` — the shared inputs-hash primitive

**Files:**
- Create: `inc/settings/validation.php`
- Modify: `functions.php` (require chain)
- Test: `tests/SettingsInputsHashTest.php`

**Interfaces:**
- Consumes: `blueline_stylesheet_version()` (`inc/enqueue.php:50`, pre-existing).
- Produces: `blueline_settings_inputs_hash( ?string $contrast_rules_path_override = null ): string` and `blueline_contrast_rules_content_hash( string $path ): string`, both usable with no arguments in production.

- [ ] **Step 1: Write the failing test**

  Create `tests/SettingsInputsHashTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/cli-stubs.php';
  require_once __DIR__ . '/../inc/enqueue.php';
  require_once __DIR__ . '/../inc/settings/validation.php';

  /**
   * Covers blueline_settings_inputs_hash(): a single hash that changes if,
   * and only if, style.css's own last-edit time or contrast-rules.json's
   * rules/thresholds change.
   */
  final class SettingsInputsHashTest extends TestCase {

  	/**
  	 * Writes a minimal fixture contrast-rules.json and returns its path.
  	 *
  	 * @param array $data Decoded content to encode.
  	 * @return string Path to the fixture file.
  	 */
  	private function write_fixture( array $data ): string {
  		$path = sys_get_temp_dir() . '/blueline-rules-' . uniqid( '', true ) . '.json';
  		file_put_contents( $path, wp_json_encode( $data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test fixture.
  		return $path;
  	}

  	/**
  	 * Asserts the same inputs (style.css unchanged, same rules file
  	 * content) always hash identically.
  	 */
  	public function test_stable_for_identical_inputs(): void {
  		$path = $this->write_fixture(
  			array(
  				'rules'      => array( array( 'id' => 'a', 'fg' => '--x', 'bg' => '--y', 'min' => 4.5 ) ),
  				'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ),
  			)
  		);

  		try {
  			$this->assertSame(
  				blueline_settings_inputs_hash( $path ),
  				blueline_settings_inputs_hash( $path )
  			);
  		} finally {
  			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts changing contrast-rules.json's "rules" changes the hash.
  	 */
  	public function test_changes_when_rules_change(): void {
  		$before = $this->write_fixture(
  			array( 'rules' => array( array( 'id' => 'a', 'fg' => '--x', 'bg' => '--y', 'min' => 4.5 ) ), 'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ) )
  		);
  		$after = $this->write_fixture(
  			array( 'rules' => array( array( 'id' => 'a', 'fg' => '--x', 'bg' => '--y', 'min' => 7.0 ) ), 'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ) )
  		);

  		try {
  			$this->assertNotSame(
  				blueline_settings_inputs_hash( $before ),
  				blueline_settings_inputs_hash( $after )
  			);
  		} finally {
  			unlink( $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  			unlink( $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts changing contrast-rules.json's "thresholds" changes the
  	 * hash.
  	 */
  	public function test_changes_when_thresholds_change(): void {
  		$before = $this->write_fixture(
  			array( 'rules' => array(), 'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ) )
  		);
  		$after = $this->write_fixture(
  			array( 'rules' => array(), 'thresholds' => array( 'body' => 7.0, 'large' => 3.0 ) )
  		);

  		try {
  			$this->assertNotSame(
  				blueline_settings_inputs_hash( $before ),
  				blueline_settings_inputs_hash( $after )
  			);
  		} finally {
  			unlink( $before ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  			unlink( $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts editing an unrelated top-level key (e.g. "$comment") does
  	 * NOT change the hash -- only "rules" and "thresholds" are hashed, per
  	 * the design spec's §4.3, so a comment-only edit does not invalidate
  	 * every live acknowledgement.
  	 */
  	public function test_unrelated_top_level_keys_do_not_change_the_hash(): void {
  		$without_comment = $this->write_fixture(
  			array( 'rules' => array(), 'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ) )
  		);
  		$with_comment = $this->write_fixture(
  			array( '$comment' => 'a documentation-only edit', 'version' => 2, 'rules' => array(), 'thresholds' => array( 'body' => 4.5, 'large' => 3.0 ) )
  		);

  		try {
  			$this->assertSame(
  				blueline_settings_inputs_hash( $without_comment ),
  				blueline_settings_inputs_hash( $with_comment )
  			);
  		} finally {
  			unlink( $without_comment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  			unlink( $with_comment ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local test fixture cleanup.
  		}
  	}

  	/**
  	 * Asserts a missing or malformed contrast-rules.json degrades to
  	 * hashing an empty rules/thresholds set rather than fataling.
  	 */
  	public function test_missing_rules_file_does_not_fatal(): void {
  		$hash = blueline_settings_inputs_hash( '/nonexistent/contrast-rules.json' );

  		$this->assertIsString( $hash );
  		$this->assertNotSame( '', $hash );
  	}

  	/**
  	 * Asserts calling with no arguments at all (the real production
  	 * contract) resolves against the real, committed
  	 * tools/contrast-rules.json without fataling.
  	 */
  	public function test_real_no_argument_call_does_not_fatal(): void {
  		$hash = blueline_settings_inputs_hash();

  		$this->assertIsString( $hash );
  		$this->assertNotSame( '', $hash );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsInputsHashTest` — Expected: FAIL with "Call to undefined function blueline_settings_inputs_hash()".

- [ ] **Step 3: Write minimal implementation**

  Create `inc/settings/validation.php`:

  ```php
  <?php
  /**
   * The shared "inputs hash" primitive.
   *
   * A single hash summarising the external inputs the settings panel's
   * AA-related verdicts depend on: style.css's own last-edit time (via
   * blueline_stylesheet_version(), inc/enqueue.php) and
   * tools/contrast-rules.json's `rules`/`thresholds` keys specifically --
   * deliberately not the whole file, so an edit to its `$comment` or
   * `version` does not invalidate every live acknowledgement.
   *
   * Two consumers share this: an `aa_acknowledgements` entry
   * (inc/settings/acknowledgements.php) stores this hash at the moment of
   * acknowledgement (Phase 2.0); Phase 2.2's `_validated_against` will
   * store it for the settings option as a whole. See
   * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.3.
   *
   * @package blueline
   */

  defined( 'ABSPATH' ) || exit;

  /**
   * Log, at most once per call site, that tools/contrast-rules.json could
   * not be read while computing the inputs hash. A distinct function from
   * inc/team-colors.php's blueline_contrast_rules_read_failure() (same
   * shape, deliberately not shared, so this file has no reason to depend
   * on that one's internal static).
   *
   * @param string $path   The contrast-rules.json path that could not be used.
   * @param string $reason Human-readable reason, for the log line.
   * @return void
   */
  function blueline_settings_inputs_hash_read_failure( string $path, string $reason ): void {
  	static $logged = false;

  	if ( $logged ) {
  		return;
  	}
  	$logged = true;

  	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: same reasoning as inc/team-colors.php's blueline_contrast_rules_read_failure().
  	error_log(
  		sprintf(
  			'Blueline: contrast-rules.json unusable (%s) at %s while computing the settings inputs hash -- hashing an empty rules/thresholds set instead.',
  			$reason,
  			$path
  		)
  	);
  }

  /**
   * A stable hash of tools/contrast-rules.json's `rules` and `thresholds`
   * keys only. Never fatal: a missing or malformed file hashes an empty
   * rules/thresholds set instead -- the file's own thresholds are already
   * unusable in that case (see blueline_load_contrast_thresholds()), so
   * this only means an acknowledgement recorded while the file was broken
   * will need re-acknowledging once it is readable again.
   *
   * @param string $path Path to contrast-rules.json.
   * @return string A sha256 hex digest.
   */
  function blueline_contrast_rules_content_hash( string $path ): string {
  	$rules      = array();
  	$thresholds = array();

  	if ( is_readable( $path ) ) {
  		$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
  		$json = json_decode( (string) $raw, true );

  		if ( is_array( $json ) ) {
  			$rules      = is_array( $json['rules'] ?? null ) ? $json['rules'] : array();
  			$thresholds = is_array( $json['thresholds'] ?? null ) ? $json['thresholds'] : array();
  		} else {
  			blueline_settings_inputs_hash_read_failure( $path, 'invalid JSON' );
  		}
  	} else {
  		blueline_settings_inputs_hash_read_failure( $path, 'file is missing or unreadable' );
  	}

  	return hash(
  		'sha256',
  		(string) wp_json_encode(
  			array(
  				'rules'      => $rules,
  				'thresholds' => $thresholds,
  			)
  		)
  	);
  }

  /**
   * The shared inputs hash: a single string that changes if, and only if,
   * either style.css's own last-edit time or contrast-rules.json's
   * `rules`/`thresholds` change.
   *
   * @param string|null $contrast_rules_path_override Explicit path to
   *                                                   contrast-rules.json,
   *                                                   for tests. Defaults
   *                                                   to the real
   *                                                   tools/contrast-rules.json
   *                                                   next to this theme.
   * @return string A sha256 hex digest.
   */
  function blueline_settings_inputs_hash( ?string $contrast_rules_path_override = null ): string {
  	$path = $contrast_rules_path_override ?? ( ( defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__, 2 ) ) . '/tools/contrast-rules.json' );

  	return hash( 'sha256', blueline_stylesheet_version() . '|' . blueline_contrast_rules_content_hash( $path ) );
  }
  ```

  Modify `functions.php`: add, after the `inc/settings/tokens.php` require line added in Task 2:

  ```php
  require_once BLUELINE_DIR . '/inc/settings/validation.php';
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsInputsHashTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/validation.php functions.php tests/SettingsInputsHashTest.php
  git commit -m "Add blueline_settings_inputs_hash(), the shared AA-inputs hash primitive"
  ```

---

### Task 5: `aa_acknowledgements` — real storage, protected like `_schema`

**Files:**
- Create: `inc/settings/acknowledgements.php`
- Modify: `inc/settings/defaults.php` (`blueline_settings_defaults()`)
- Modify: `inc/settings/page.php:217` (`BLUELINE_SETTINGS_RESERVED_KEYS`), and the reserved-key branch of `blueline_settings_sanitize_callback()` (around line 483)
- Modify: `functions.php` (require chain)
- Modify: `tests/SettingsPageTest.php` (add require, add test methods)
- Test: `tests/SettingsAcknowledgementsTest.php`

**Interfaces:**
- Consumes: `BLUELINE_SETTINGS_RESERVED_KEYS` (`inc/settings/page.php:217`, pre-existing), `blueline_settings_sanitize_callback()` (`inc/settings/page.php`, pre-existing), `blueline_settings_defaults()` (`inc/settings/defaults.php`, pre-existing), `blueline_settings_merge()` (`inc/settings/store.php`, pre-existing, unmodified — its existing carry-forward logic already protects any key absent from `_posted_fields`).
- Produces: `blueline_sanitize_acknowledgements( $value ): array` (`inc/settings/acknowledgements.php`), the default `'aa_acknowledgements' => array()` in `blueline_settings_defaults()`, and `aa_acknowledgements` on `BLUELINE_SETTINGS_RESERVED_KEYS`.

- [ ] **Step 1: Write the failing test**

  Create `tests/SettingsAcknowledgementsTest.php`:

  ```php
  <?php
  /**
   * Unit tests.
   *
   * @package blueline
   */

  use PHPUnit\Framework\TestCase;

  require_once __DIR__ . '/../inc/settings/acknowledgements.php';

  /**
   * Covers aa_acknowledgements storage: blueline_sanitize_acknowledgements()'s
   * shape validation.
   */
  final class SettingsAcknowledgementsTest extends TestCase {

  	/**
  	 * A well-formed entry, reused across several tests.
  	 *
  	 * @return array<string, mixed>
  	 */
  	private function valid_entry(): array {
  		return array(
  			'rule_id'     => 'ink-on-occasion-accent',
  			'ratio'       => 3.2,
  			'user_id'     => 7,
  			'date'        => 1700000000,
  			'inputs_hash' => 'abc123',
  			'scope'       => 'occasion:canada-day',
  		);
  	}

  	/**
  	 * Asserts a non-array value sanitizes to an empty map.
  	 */
  	public function test_non_array_value_sanitizes_to_empty(): void {
  		foreach ( array( null, 'not-an-array', 42, false ) as $bad ) {
  			$this->assertSame( array(), blueline_sanitize_acknowledgements( $bad ) );
  		}
  	}

  	/**
  	 * Asserts a well-formed entry survives, keyed by its own scope.
  	 */
  	public function test_a_well_formed_entry_survives(): void {
  		$clean = blueline_sanitize_acknowledgements(
  			array( 'occasion:canada-day' => $this->valid_entry() )
  		);

  		$this->assertSame( $this->valid_entry(), $clean['occasion:canada-day'] );
  	}

  	/**
  	 * Asserts an entry missing a required key is dropped rather than
  	 * corrupting the whole read.
  	 */
  	public function test_an_entry_missing_a_required_key_is_dropped(): void {
  		$entry = $this->valid_entry();
  		unset( $entry['inputs_hash'] );

  		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'occasion:canada-day', $clean );
  	}

  	/**
  	 * Asserts an entry whose own `scope` field disagrees with its map key
  	 * is dropped rather than trusted.
  	 */
  	public function test_an_entry_whose_scope_field_disagrees_with_its_key_is_dropped(): void {
  		$entry           = $this->valid_entry();
  		$entry['scope']  = 'occasion:remembrance-day';

  		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

  		$this->assertArrayNotHasKey( 'occasion:canada-day', $clean );
  	}

  	/**
  	 * Asserts a non-array row value (not a whole entry) is dropped.
  	 */
  	public function test_a_non_array_row_is_dropped(): void {
  		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => 'not-an-array' ) );

  		$this->assertSame( array(), $clean );
  	}

  	/**
  	 * Asserts a valid entry and an invalid one in the same map are handled
  	 * independently: the valid one survives, the invalid one is dropped.
  	 */
  	public function test_one_bad_entry_does_not_take_down_a_good_one(): void {
  		$bad = $this->valid_entry();
  		unset( $bad['ratio'] );

  		$clean = blueline_sanitize_acknowledgements(
  			array(
  				'occasion:canada-day'      => $this->valid_entry(),
  				'occasion:remembrance-day' => $bad,
  			)
  		);

  		$this->assertArrayHasKey( 'occasion:canada-day', $clean );
  		$this->assertArrayNotHasKey( 'occasion:remembrance-day', $clean );
  	}

  	/**
  	 * Asserts numeric-looking string values for ratio/user_id/date are
  	 * cast to their real types rather than rejected -- a value round-
  	 * tripped through JSON (import/export) may arrive this way.
  	 */
  	public function test_numeric_strings_are_cast_to_the_right_type(): void {
  		$entry             = $this->valid_entry();
  		$entry['ratio']    = '3.2';
  		$entry['user_id']  = '7';
  		$entry['date']     = '1700000000';

  		$clean = blueline_sanitize_acknowledgements( array( 'occasion:canada-day' => $entry ) );

  		$this->assertSame( 3.2, $clean['occasion:canada-day']['ratio'] );
  		$this->assertSame( 7, $clean['occasion:canada-day']['user_id'] );
  		$this->assertSame( 1700000000, $clean['occasion:canada-day']['date'] );
  	}
  }
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsAcknowledgementsTest` — Expected: FAIL with "Call to undefined function blueline_sanitize_acknowledgements()".

- [ ] **Step 3: Write minimal implementation**

  Create `inc/settings/acknowledgements.php`:

  ```php
  <?php
  /**
   * `aa_acknowledgements` storage.
   *
   * Real storage for what, before this file, was only an import-time
   * discard target (inc/settings/import.php unsets it unconditionally on
   * every import -- that does not change here; consent is not something a
   * file can assert on an admin's behalf). A map of acknowledgement id (a
   * `scope` string, e.g. `occasion:canada-day`) => {rule_id, ratio,
   * user_id, date, inputs_hash, scope}.
   *
   * `aa_acknowledgements` is protected the same way `_schema` already is
   * (inc/settings/page.php's BLUELINE_SETTINGS_RESERVED_KEYS,
   * blueline_settings_sanitize_callback()'s reserved-key branch, and
   * inc/settings/store.php's blueline_settings_merge() carry-forward)
   * rather than being a real schema field: there is no panel tab or form
   * field that owns it in Phase 2.0, or even Phase 2.1's Occasions tab --
   * see docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md
   * §4.4/§4.5 -- so a schema `type`/`tab` entry would have nothing real to
   * render.
   *
   * The pure record/covers/invalidate mechanism functions this file will
   * also carry (Phase 2.0's next task) are what Phase 2.1's resolver calls
   * once a real Occasion model exists to supply their parameters from.
   *
   * @package blueline
   */

  defined( 'ABSPATH' ) || exit;

  /**
   * Validate and repair a stored `aa_acknowledgements` value.
   *
   * Called from inc/settings/page.php's blueline_settings_sanitize_callback()
   * reserved-key branch, the same choke point `_schema` already goes
   * through -- so this runs on every write path (WP-CLI, a direct
   * update_option() call, this file's own future record/invalidate
   * functions), not only ones that pass through wp-admin. Never fatal: a
   * non-array input, or any entry missing a required key, carrying the
   * wrong type for one, or whose own `scope` field disagrees with its map
   * key, is dropped rather than allowed to corrupt the option or crash a
   * later reader -- the same defensive posture
   * blueline_load_contrast_thresholds() takes for a malformed
   * contrast-rules.json.
   *
   * @param mixed $value Raw value to validate.
   * @return array<string, array{rule_id:string, ratio:float, user_id:int, date:int, inputs_hash:string, scope:string}>
   */
  function blueline_sanitize_acknowledgements( $value ): array {
  	if ( ! is_array( $value ) ) {
  		return array();
  	}

  	$clean = array();

  	foreach ( $value as $scope => $entry ) {
  		if ( ! is_string( $scope ) || '' === $scope || ! is_array( $entry ) ) {
  			continue;
  		}

  		if ( ! isset( $entry['rule_id'], $entry['ratio'], $entry['user_id'], $entry['date'], $entry['inputs_hash'], $entry['scope'] ) ) {
  			continue;
  		}

  		if ( ! is_string( $entry['rule_id'] ) || '' === $entry['rule_id']
  			|| ! is_numeric( $entry['ratio'] )
  			|| ! is_numeric( $entry['user_id'] )
  			|| ! is_numeric( $entry['date'] )
  			|| ! is_string( $entry['inputs_hash'] ) || '' === $entry['inputs_hash']
  			|| ! is_string( $entry['scope'] ) || $entry['scope'] !== $scope
  		) {
  			continue;
  		}

  		$clean[ $scope ] = array(
  			'rule_id'     => $entry['rule_id'],
  			'ratio'       => (float) $entry['ratio'],
  			'user_id'     => (int) $entry['user_id'],
  			'date'        => (int) $entry['date'],
  			'inputs_hash' => $entry['inputs_hash'],
  			'scope'       => $scope,
  		);
  	}

  	return $clean;
  }
  ```

  Modify `inc/settings/defaults.php`: in `blueline_settings_defaults()`, immediately after the `'advanced_enabled' => false,` line, add:

  ```php
  		// Real storage for the AA-failure acknowledgement mechanism (design
  		// spec §4.4/§4.5): a map of acknowledgement id (a `scope` string,
  		// e.g. `occasion:canada-day`) => {rule_id, ratio, user_id, date,
  		// inputs_hash, scope}, written and read by
  		// inc/settings/acknowledgements.php. Not a schema field -- see that
  		// file's own docblock for why -- so it carries no `type`/`tab` entry
  		// in blueline_settings_schema() above; it is protected against an
  		// unrelated tab's save the same way `_schema` is
  		// (BLUELINE_SETTINGS_RESERVED_KEYS, inc/settings/page.php).
  		'aa_acknowledgements'            => array(),
  ```

  Modify `inc/settings/page.php:217`:

  ```php
  const BLUELINE_SETTINGS_RESERVED_KEYS = array( '_schema', 'aa_acknowledgements' );
  ```

  Modify `blueline_settings_sanitize_callback()`'s reserved-key branch: immediately after the existing `if ( '' !== $submitted_tab ) { continue; }` block and before `$sanitized_reserved = absint( $value );`, add:

  ```php
  			if ( 'aa_acknowledgements' === $key ) {
  				// Not an integer like every other reserved key today -- a map
  				// of acknowledgement entries (inc/settings/acknowledgements.php).
  				// Its own validator drops anything malformed rather than
  				// corrupting the option or crashing a later reader.
  				$output[ $key ] = blueline_sanitize_acknowledgements( $value );
  				continue;
  			}

  ```

  Modify `functions.php`: add, before the `inc/settings/page.php` require line (so the function it calls is already defined; PHP would still resolve it correctly either way since function calls resolve at call time, not parse time, but this keeps the require chain's declared order meaningful):

  ```php
  require_once BLUELINE_DIR . '/inc/settings/acknowledgements.php';
  ```

  Modify `tests/SettingsPageTest.php`: add, alongside its existing requires:

  ```php
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  ```

  and add these test methods to the class:

  ```php
  	/**
  	 * Asserts `aa_acknowledgements` survives a programmatic write (no
  	 * `_tab`), the same path `_schema` already relies on.
  	 */
  	public function test_sanitize_callback_lets_acknowledgements_survive_a_programmatic_write(): void {
  		$entry  = array(
  			'rule_id'     => 'ink-on-occasion-accent',
  			'ratio'       => 3.2,
  			'user_id'     => 7,
  			'date'        => 1700000000,
  			'inputs_hash' => 'abc123',
  			'scope'       => 'occasion:canada-day',
  		);
  		$output = blueline_settings_sanitize_callback(
  			array( 'aa_acknowledgements' => array( 'occasion:canada-day' => $entry ) )
  		);

  		$this->assertSame( $entry, $output['aa_acknowledgements']['occasion:canada-day'] );
  	}

  	/**
  	 * Asserts `aa_acknowledgements` is dropped outright from a tab-scoped
  	 * (form) submission -- it never legitimately arrives from the panel's
  	 * own rendered form, the same protection `_schema` already has.
  	 */
  	public function test_sanitize_callback_drops_acknowledgements_from_a_form_submission(): void {
  		$output = blueline_settings_sanitize_callback(
  			array(
  				'_tab'                => 'content',
  				'aa_acknowledgements' => array( 'occasion:canada-day' => array( 'anything' => true ) ),
  			)
  		);

  		$this->assertArrayNotHasKey( 'aa_acknowledgements', $output );
  	}

  	/**
  	 * Asserts a malformed `aa_acknowledgements` value is repaired to an
  	 * empty map rather than trusted verbatim, even on a programmatic
  	 * write.
  	 */
  	public function test_sanitize_callback_validates_acknowledgements_shape(): void {
  		$output = blueline_settings_sanitize_callback(
  			array( 'aa_acknowledgements' => 'not-an-array' )
  		);

  		$this->assertSame( array(), $output['aa_acknowledgements'] );
  	}
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter "SettingsAcknowledgementsTest|SettingsPageTest|SettingsDefaultsTest|SettingsStoreTest"` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/acknowledgements.php inc/settings/defaults.php inc/settings/page.php functions.php tests/SettingsAcknowledgementsTest.php tests/SettingsPageTest.php
  git commit -m "Give aa_acknowledgements real storage, protected like _schema"
  ```

---

### Task 6: The occasion AA-override mechanism functions

**Files:**
- Modify: `inc/settings/acknowledgements.php` (add `blueline_record_acknowledgement()`, `blueline_invalidate_acknowledgement()`, `blueline_acknowledgement_covers()`)
- Test: `tests/SettingsAcknowledgementsTest.php` (add test methods)

**Interfaces:**
- Consumes: `blueline_sanitize_acknowledgements( $value ): array` (Task 5, same file — not called directly by these functions, but they operate on the same shape it validates), `blueline_settings_inputs_hash(): string` (Task 4, used only in one integration-style test below, not by the functions themselves).
- Produces: `blueline_record_acknowledgement( array $acknowledgements, string $scope, string $rule_id, float $ratio, string $inputs_hash, int $user_id ): array`, `blueline_invalidate_acknowledgement( array $acknowledgements, string $scope ): array`, `blueline_acknowledgement_covers( array $acknowledgements, string $scope, string $rule_id, float $ratio, string $current_inputs_hash ): bool`. All three are pure functions over a plain acknowledgements array and reference nothing about what an Occasion is — Phase 2.1's resolver will call them once a real Occasion model exists to supply their `$scope`/`$rule_id`/`$ratio` arguments from.

- [ ] **Step 1: Write the failing test**

  Add to `tests/SettingsAcknowledgementsTest.php`:

  ```php
  	/* -------------------------------------------------- record/invalidate */

  	/**
  	 * Asserts recording an acknowledgement adds an entry keyed by its
  	 * scope, with the given fields and a `date` set from the real clock.
  	 */
  	public function test_record_adds_an_entry_keyed_by_scope(): void {
  		$before = time();

  		$acknowledgements = blueline_record_acknowledgement(
  			array(),
  			'occasion:canada-day',
  			'ink-on-occasion-accent',
  			3.2,
  			'abc123',
  			7
  		);

  		$after = time();

  		$entry = $acknowledgements['occasion:canada-day'];
  		$this->assertSame( 'ink-on-occasion-accent', $entry['rule_id'] );
  		$this->assertSame( 3.2, $entry['ratio'] );
  		$this->assertSame( 7, $entry['user_id'] );
  		$this->assertSame( 'abc123', $entry['inputs_hash'] );
  		$this->assertSame( 'occasion:canada-day', $entry['scope'] );
  		$this->assertGreaterThanOrEqual( $before, $entry['date'] );
  		$this->assertLessThanOrEqual( $after, $entry['date'] );
  	}

  	/**
  	 * Asserts recording an acknowledgement for a scope that already has
  	 * one REPLACES it rather than accumulating history -- only one entry
  	 * is ever live per scope.
  	 */
  	public function test_record_replaces_an_existing_entry_for_the_same_scope(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
  		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:canada-day', 'rule-b', 4.0, 'hash-2', 2 );

  		$this->assertCount( 1, $acknowledgements );
  		$this->assertSame( 'rule-b', $acknowledgements['occasion:canada-day']['rule_id'] );
  		$this->assertSame( 2, $acknowledgements['occasion:canada-day']['user_id'] );
  	}

  	/**
  	 * Asserts recording an acknowledgement leaves an unrelated scope's
  	 * entry untouched.
  	 */
  	public function test_record_does_not_disturb_a_different_scope(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
  		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:remembrance-day', 'rule-b', 4.0, 'hash-2', 2 );

  		$this->assertSame( 'rule-a', $acknowledgements['occasion:canada-day']['rule_id'] );
  		$this->assertSame( 'rule-b', $acknowledgements['occasion:remembrance-day']['rule_id'] );
  	}

  	/**
  	 * Asserts invalidating removes exactly the named scope's entry.
  	 */
  	public function test_invalidate_removes_only_the_named_scope(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'rule-a', 3.0, 'hash-1', 1 );
  		$acknowledgements = blueline_record_acknowledgement( $acknowledgements, 'occasion:remembrance-day', 'rule-b', 4.0, 'hash-2', 2 );

  		$acknowledgements = blueline_invalidate_acknowledgement( $acknowledgements, 'occasion:canada-day' );

  		$this->assertArrayNotHasKey( 'occasion:canada-day', $acknowledgements );
  		$this->assertArrayHasKey( 'occasion:remembrance-day', $acknowledgements );
  	}

  	/**
  	 * Asserts invalidating a scope with no entry is a harmless no-op.
  	 */
  	public function test_invalidate_is_a_no_op_for_an_unknown_scope(): void {
  		$this->assertSame( array(), blueline_invalidate_acknowledgement( array(), 'occasion:canada-day' ) );
  	}

  	/* -------------------------------------------------------------- covers */

  	/**
  	 * Asserts a matching rule id, ratio, and inputs hash all together
  	 * cover the scope.
  	 */
  	public function test_covers_true_when_rule_ratio_and_hash_all_match(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

  		$this->assertTrue(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1' )
  		);
  	}

  	/**
  	 * Asserts no entry for the scope at all answers false.
  	 */
  	public function test_covers_false_when_no_entry_exists_for_the_scope(): void {
  		$this->assertFalse(
  			blueline_acknowledgement_covers( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1' )
  		);
  	}

  	/**
  	 * Asserts a stale inputs hash (style.css or contrast-rules.json
  	 * changed since the acknowledgement) answers false, even though the
  	 * rule id and ratio still match -- per the design spec's §4.5, this is
  	 * "unacknowledged", not an error.
  	 */
  	public function test_covers_false_when_inputs_hash_is_stale(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-old', 7 );

  		$this->assertFalse(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-new' )
  		);
  	}

  	/**
  	 * Asserts a different rule id answers false, even with the same ratio
  	 * and hash.
  	 */
  	public function test_covers_false_when_rule_id_differs(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

  		$this->assertFalse(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'paper-not-text-on-occasion-accent', 3.2, 'hash-1' )
  		);
  	}

  	/**
  	 * Asserts a changed ratio (the admin edited the value since
  	 * acknowledging) answers false, even with the same rule id and hash.
  	 */
  	public function test_covers_false_when_ratio_differs(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

  		$this->assertFalse(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 5.0, 'hash-1' )
  		);
  	}

  	/**
  	 * Asserts a floating-point ratio that is equal within a tiny tolerance
  	 * still covers -- the stored value and the freshly-computed value are
  	 * two independent float computations, never assumed bit-identical.
  	 */
  	public function test_covers_true_within_a_small_float_tolerance(): void {
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, 'hash-1', 7 );

  		$this->assertTrue(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2 + 1.0e-9, 'hash-1' )
  		);
  	}

  	/**
  	 * Integration-style: uses the real blueline_settings_inputs_hash()
  	 * (Task 4) to prove the mechanism composes with it exactly as Phase
  	 * 2.1's resolver will -- record with the real current hash, then check
  	 * coverage against that same real current hash.
  	 */
  	public function test_composes_with_the_real_inputs_hash(): void {
  		$current_hash     = blueline_settings_inputs_hash();
  		$acknowledgements = blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, $current_hash, 7 );

  		$this->assertTrue(
  			blueline_acknowledgement_covers( $acknowledgements, 'occasion:canada-day', 'ink-on-occasion-accent', 3.2, $current_hash )
  		);
  	}
  ```

- [ ] **Step 2: Run test to verify it fails**

  Run: `composer test -- --filter SettingsAcknowledgementsTest` — Expected: FAIL with "Call to undefined function blueline_record_acknowledgement()".

- [ ] **Step 3: Write minimal implementation**

  Modify `tests/SettingsAcknowledgementsTest.php`'s requires at the top of the
  file (the last test method above needs the real `blueline_settings_inputs_hash()`,
  which Task 4 put behind `cli-stubs.php`'s `wp_json_encode()` stub and
  `inc/enqueue.php`'s `blueline_stylesheet_version()`):

  ```php
  require_once __DIR__ . '/cli-stubs.php';
  require_once __DIR__ . '/../inc/enqueue.php';
  require_once __DIR__ . '/../inc/settings/validation.php';
  require_once __DIR__ . '/../inc/settings/acknowledgements.php';
  ```

  Append to `inc/settings/acknowledgements.php`:

  ```php
  /**
   * Record (or replace) the acknowledgement for one scope.
   *
   * Pure: takes the current acknowledgements map and returns a new one
   * with $scope's entry set, using the real clock (time()) for `date` --
   * the same plain time() inc/settings/snapshots.php's
   * blueline_settings_snapshot_take() already uses for its own `time`
   * field, rather than an injected clock. Only one entry is ever live per
   * scope: acknowledging the same scope again (e.g. a changed accent that
   * still fails, re-acknowledged) replaces the previous entry rather than
   * accumulating history.
   *
   * @param array<string, array<string, mixed>> $acknowledgements Current map.
   * @param string                               $scope            What was
   *                                                                 acknowledged,
   *                                                                 e.g.
   *                                                                 `occasion:canada-day`.
   * @param string                               $rule_id          The
   *                                                                 contrast-rules.json
   *                                                                 rule id
   *                                                                 the value
   *                                                                 failed.
   * @param float                                $ratio            The
   *                                                                 computed
   *                                                                 contrast
   *                                                                 ratio
   *                                                                 that
   *                                                                 failed
   *                                                                 it.
   * @param string                               $inputs_hash      blueline_settings_inputs_hash()'s
   *                                                                 value at
   *                                                                 the
   *                                                                 moment of
   *                                                                 acknowledgement.
   * @param int                                   $user_id          The
   *                                                                 acknowledging
   *                                                                 user's
   *                                                                 ID.
   * @return array<string, array<string, mixed>> The updated map.
   */
  function blueline_record_acknowledgement(
  	array $acknowledgements,
  	string $scope,
  	string $rule_id,
  	float $ratio,
  	string $inputs_hash,
  	int $user_id
  ): array {
  	$acknowledgements[ $scope ] = array(
  		'rule_id'     => $rule_id,
  		'ratio'       => $ratio,
  		'user_id'     => $user_id,
  		'date'        => time(),
  		'inputs_hash' => $inputs_hash,
  		'scope'       => $scope,
  	);

  	return $acknowledgements;
  }

  /**
   * Remove the acknowledgement for one scope, if any.
   *
   * Pure. Intended for a Phase 2.1 resolver decision (an admin
   * re-acknowledges, or a scope's underlying value changes to something
   * that passes outright, leaving nothing to acknowledge) -- this function
   * only performs the removal once asked; it does not decide when to.
   *
   * @param array<string, array<string, mixed>> $acknowledgements Current map.
   * @param string                               $scope            Scope to
   *                                                                 remove.
   * @return array<string, array<string, mixed>> The updated map.
   */
  function blueline_invalidate_acknowledgement( array $acknowledgements, string $scope ): array {
  	unset( $acknowledgements[ $scope ] );

  	return $acknowledgements;
  }

  /**
   * Whether a live acknowledgement exists for $scope that covers the exact
   * pairing being checked right now.
   *
   * "Covers" means all three: the stored entry's `rule_id` matches, its
   * `ratio` matches the freshly-computed ratio being checked (within a
   * small floating-point tolerance -- the two are independently computed
   * floats, never assumed bit-identical), and its `inputs_hash` still
   * matches $current_inputs_hash. Any mismatch -- no entry for this scope,
   * a different rule, a changed ratio (the admin edited the value since
   * acknowledging), or a stale inputs hash (style.css or
   * contrast-rules.json changed since) -- answers false: per
   * docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md §4.5,
   * that is "unacknowledged", not an error.
   *
   * Deliberately generic: it takes the rule id and ratio to check against
   * as plain parameters rather than reading anything about what an
   * Occasion is, so it is fully testable today against synthetic
   * acknowledgement data. Phase 2.1's resolver calls it once a real
   * Occasion model exists to supply those parameters from.
   *
   * @param array<string, array<string, mixed>> $acknowledgements    Current
   *                                                                   map.
   * @param string                               $scope               Scope
   *                                                                   to
   *                                                                   check.
   * @param string                               $rule_id             The
   *                                                                   contrast-rules.json
   *                                                                   rule id
   *                                                                   currently
   *                                                                   failing.
   * @param float                                $ratio               The
   *                                                                   freshly-computed
   *                                                                   contrast
   *                                                                   ratio
   *                                                                   currently
   *                                                                   failing.
   * @param string                               $current_inputs_hash blueline_settings_inputs_hash()'s
   *                                                                   current
   *                                                                   value.
   * @return bool
   */
  function blueline_acknowledgement_covers(
  	array $acknowledgements,
  	string $scope,
  	string $rule_id,
  	float $ratio,
  	string $current_inputs_hash
  ): bool {
  	if ( ! isset( $acknowledgements[ $scope ] ) || ! is_array( $acknowledgements[ $scope ] ) ) {
  		return false;
  	}

  	$entry = $acknowledgements[ $scope ];

  	if ( ( $entry['rule_id'] ?? null ) !== $rule_id ) {
  		return false;
  	}

  	if ( ( $entry['inputs_hash'] ?? null ) !== $current_inputs_hash ) {
  		return false;
  	}

  	$stored_ratio = isset( $entry['ratio'] ) ? (float) $entry['ratio'] : null;

  	if ( null === $stored_ratio || abs( $stored_ratio - $ratio ) > 0.0001 ) {
  		return false;
  	}

  	return true;
  }
  ```

- [ ] **Step 4: Run test to verify it passes**

  Run: `composer test -- --filter SettingsAcknowledgementsTest` — Expected: PASS

- [ ] **Step 5: Commit**
  ```bash
  git add inc/settings/acknowledgements.php tests/SettingsAcknowledgementsTest.php
  git commit -m "Add the occasion AA-override mechanism: record/invalidate/covers"
  ```

---

### Task 7: Full verification

**Files:**
- (none — verification only)

**Interfaces:**
- Consumes: everything produced by Tasks 1-6.
- Produces: a confirmed-green `npm run check` for this phase.

- [ ] **Step 1: Run the full gate**

  Run: `npm run check` — Expected: PASS (lint:css, lint:js, test:js, tokens:check, `composer test`, `composer lint` all green).

- [ ] **Step 2: If `composer lint` (phpcs) reports anything in the five new files**

  Fix any WordPress-Coding-Standards nit it finds (docblock alignment, spacing) with a line-level `phpcs:ignore <sniff> -- <reason>` only where the sniff is flagging something deliberate (e.g. the `error_log()` calls already carry their justified ignores) — never `phpcs:ignoreFile`, and never stack a second annotation on a line that already has a trailing one (a trailing annotation replaces a preceding-line one silently).

- [ ] **Step 3: Confirm no test from Tasks 1-6 regressed**

  Run: `composer test` — Expected: PASS, full suite (not just this phase's new files) — this catches the exact class of regression the P1b decision record's §3.1 warns about: a new guard on a write path (the `aa_acknowledgements` reserved-key/sanitizer branch) retroactively threatening an existing test that reached that value through the same path.

- [ ] **Step 4: Commit** — nothing to commit; this task is verification-only. If Step 2 required a fix, that fix was already committed as part of its own task before reaching here; re-run Step 1 to confirm.
