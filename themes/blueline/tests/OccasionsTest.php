<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php'; // wp_json_encode(), used by blueline_settings_inputs_hash(), which blueline_occasions_classify_acknowledgements() calls.
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/settings/validation.php';
require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers blueline_occasion_accent_default()'s narrow, one-hop resolution
 * of --bl-occasion-accent's declared default against a real or fixture
 * style.css.
 */
final class OccasionsTest extends TestCase {

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
		blueline_occasion_accent_default_read_failure( '/nonexistent/style.css', 'missing or unreadable' );
		blueline_occasion_accent_default_read_failure( '/nonexistent/style.css', 'missing or unreadable' );

		$this->addToAssertionCount( 1 );
	}

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
			'window' => array(
				'start_md' => '07-01',
				'end_md'   => '07-01',
			),
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
	 * dropped rather than trusted -- mirrors the `aa_acknowledgements`
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
		$bad          = $this->valid_occasion();
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
	 * rejected -- there is no legitimate reading of an occasion with no
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

		$bad_calendar_date           = $this->valid_occasion();
		$bad_calendar_date['window'] = array(
			'start_md' => '02-30',
			'end_md'   => '02-30',
		);

		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $missing_bound ) ) );
		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $bad_calendar_date ) ) );
	}

	/**
	 * Asserts Feb 29th is accepted as a valid recurring MM-DD -- validated
	 * against a leap year (2024), since this is an ANNUALLY RECURRING date,
	 * not an instant, so "no such day in a non-leap year" is not a reason
	 * to reject it outright.
	 */
	public function test_sanitize_occasions_leap_day_window_is_accepted(): void {
		$entry           = $this->valid_occasion();
		$entry['window'] = array(
			'start_md' => '02-29',
			'end_md'   => '02-29',
		);

		$clean = blueline_sanitize_occasions( array( 'canada-day' => $entry ) );

		$this->assertArrayHasKey( 'canada-day', $clean );
	}

	/**
	 * Asserts a window that crosses the year boundary (start after end,
	 * e.g. New Year's own 12-27..01-02) is accepted at the sanitizer level
	 * -- the resolver (Task 3) is what interprets the wraparound, not this
	 * validator, which only checks that both bounds are individually
	 * well-formed calendar dates.
	 */
	public function test_sanitize_occasions_year_boundary_crossing_window_is_accepted(): void {
		$entry           = $this->valid_occasion();
		$entry['window'] = array(
			'start_md' => '12-27',
			'end_md'   => '01-02',
		);

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
		$empty           = $this->valid_occasion();
		$empty['accent'] = '';
		$this->assertSame( '', blueline_sanitize_occasions( array( 'canada-day' => $empty ) )['canada-day']['accent'] );

		$normalised           = $this->valid_occasion();
		$normalised['accent'] = '#ABC';
		$this->assertSame( '#aabbcc', blueline_sanitize_occasions( array( 'canada-day' => $normalised ) )['canada-day']['accent'] );

		$invalid           = $this->valid_occasion();
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
	 * value containing a literal '%' is rejected outright -- this value is
	 * never passed through sprintf(), so there is no legitimate
	 * placeholder for it to carry at all (design spec §5/§7.1's "no
	 * placeholders permitted").
	 */
	public function test_sanitize_occasions_line_handling(): void {
		$absent = $this->valid_occasion();
		unset( $absent['line'] );
		$this->assertSame( '', blueline_sanitize_occasions( array( 'canada-day' => $absent ) )['canada-day']['line'] );

		$with_percent         = $this->valid_occasion();
		$with_percent['line'] = 'Save 50%!';
		$this->assertArrayNotHasKey( 'canada-day', blueline_sanitize_occasions( array( 'canada-day' => $with_percent ) ) );

		$normal         = $this->valid_occasion();
		$normal['line'] = '  Happy Canada Day!  ';
		$this->assertSame( 'Happy Canada Day!', blueline_sanitize_occasions( array( 'canada-day' => $normal ) )['canada-day']['line'] );
	}

	/**
	 * Asserts the three small enumerations blueline_sanitize_occasions()
	 * validates against are exactly what the design spec's §5/§7.1 model
	 * declares -- pinned independently so a future edit to one of them is a
	 * deliberate, visible change rather than a silent drift.
	 */
	public function test_occasion_enumerations_match_the_design_spec(): void {
		$this->assertSame( array( 'decorative', 'commemorative' ), blueline_occasion_types() );
		$this->assertSame( array( 'none', 'maple-leaf', 'poppy', 'snowflake', 'sparkle' ), blueline_occasion_motifs() );
		$this->assertSame( array( 'auto', 'force_on', 'force_off' ), blueline_occasion_modes() );
	}

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
		$this->assertSame(
			array(
				'start_md' => '07-01',
				'end_md'   => '07-01',
			),
			$presets['canada-day']['window']
		);
		$this->assertSame( 'maple-leaf', $presets['canada-day']['motif'] );

		$this->assertSame( 'commemorative', $presets['remembrance-day']['type'] );
		$this->assertSame(
			array(
				'start_md' => '11-11',
				'end_md'   => '11-11',
			),
			$presets['remembrance-day']['window']
		);
		$this->assertSame( 'poppy', $presets['remembrance-day']['motif'] );

		$this->assertSame( 'decorative', $presets['christmas']['type'] );
		$this->assertSame(
			array(
				'start_md' => '12-01',
				'end_md'   => '12-26',
			),
			$presets['christmas']['window']
		);
		$this->assertSame( 'snowflake', $presets['christmas']['motif'] );

		$this->assertSame( 'decorative', $presets['new-year']['type'] );
		// Crosses the year boundary deliberately -- this is the case Task
		// 3's resolver and Task 6's cron boundary calculation both have to
		// handle correctly, not hypothetically.
		$this->assertSame(
			array(
				'start_md' => '12-27',
				'end_md'   => '01-02',
			),
			$presets['new-year']['window']
		);
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

	/* ------------------------------------------------ assign_unique_ids */

	/**
	 * Asserts a brand-new row (empty `_original_id`) derives its `id`
	 * from `sanitize_title( $label )`.
	 */
	public function test_assign_unique_ids_derives_a_slug_from_the_label(): void {
		$result = blueline_occasions_assign_unique_ids(
			array(
				'row-1' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
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
				'row-1' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
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
				'row-1' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
				'row-2' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
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
			'canada-day' => array(
				'id'    => 'canada-day',
				'label' => 'Canada Day',
			),
		);

		$result = blueline_occasions_assign_unique_ids(
			array(
				'row-1' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
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
			'canada-day' => array(
				'id'    => 'canada-day',
				'label' => 'Canada Day',
			),
		);

		$result = blueline_occasions_assign_unique_ids(
			array(
				'canada-day' => array(
					'_original_id' => 'canada-day',
					'label'        => 'Canada Day',
				),
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
			'canada-day' => array(
				'id'    => 'canada-day',
				'label' => 'Canada Day',
			),
		);

		$result = blueline_occasions_assign_unique_ids(
			array(
				'canada-day' => array(
					'_original_id' => 'canada-day',
					'label'        => 'Canada Day Long Weekend',
				),
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
			'canada-day' => array(
				'id'    => 'canada-day',
				'label' => 'Canada Day',
			),
			'christmas'  => array(
				'id'    => 'christmas',
				'label' => 'Christmas',
			),
		);

		$result = blueline_occasions_assign_unique_ids(
			array(
				'canada-day' => array(
					'_original_id' => 'canada-day',
					'label'        => 'Christmas',
				),
			),
			$stored
		);

		$this->assertArrayHasKey( 'christmas-2', $result );
		$this->assertArrayNotHasKey( 'canada-day', $result );
	}

	/**
	 * Asserts two rows swapping ids within the SAME save -- row A renaming
	 * into row B's old slot while row B renames into row A's old slot --
	 * resolve cleanly to each other's target id, with no spurious `-2`
	 * suffix from either row transiently reading as "still occupied" by
	 * the other's not-yet-processed old slot.
	 */
	public function test_assign_unique_ids_a_same_save_id_swap_is_not_deduped(): void {
		$stored = array(
			'canada-day'  => array(
				'id'    => 'canada-day',
				'label' => 'Canada Day',
			),
			'victoria-day' => array(
				'id'    => 'victoria-day',
				'label' => 'Victoria Day',
			),
		);

		$result = blueline_occasions_assign_unique_ids(
			array(
				'canada-day'   => array(
					'_original_id' => 'canada-day',
					'label'        => 'Victoria Day',
				),
				'victoria-day' => array(
					'_original_id' => 'victoria-day',
					'label'        => 'Canada Day',
				),
			),
			$stored
		);

		$this->assertArrayHasKey( 'victoria-day', $result );
		$this->assertArrayHasKey( 'canada-day', $result );
		$this->assertArrayNotHasKey( 'victoria-day-2', $result );
		$this->assertArrayNotHasKey( 'canada-day-2', $result );
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
				'row-1' => array(
					'_original_id' => '',
					'label'        => '',
				),
				'row-2' => array(
					'_original_id' => '',
					'label'        => '   ',
				),
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
				'row-2' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
				),
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
				'row-1' => array(
					'_original_id' => '',
					'label'        => 'Canada Day',
					'motif'        => 'maple-leaf',
					'override_aa'  => '1',
				),
			),
			array()
		);

		$this->assertSame( 'maple-leaf', $result['canada-day']['motif'] );
		$this->assertSame( '1', $result['canada-day']['override_aa'] );
	}

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
				'window' => array(
					'start_md' => '01-01',
					'end_md'   => '12-31',
				),
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

		$passing = $this->failing_occasion(
			array(
				'id'     => 'passing',
				'accent' => '#ffffff',
			)
		);

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
	 * default) is evaluated against that resolved default, and against
	 * the SAME value blueline_occasion_accent_default() itself returns.
	 *
	 * The bare `assertArrayNotHasKey()` this test used to carry was not
	 * discriminating on its own: "the default resolved and passed" and
	 * "the default failed to resolve at all" both end in the same
	 * no-acknowledgement state, because the unresolvable-accent branch
	 * also removes. The assertions below pin the parts that ARE
	 * observable here:
	 *
	 * 1. The default really does resolve to a hex value (a fixture
	 *    guard, so a broken stylesheet read fails HERE, loudly, instead
	 *    of silently making the expectation below vacuous).
	 * 2. Its contrast against ink genuinely passes -- so "no
	 *    acknowledgement" is the right expectation for the reason this
	 *    test claims, and a default that ever changed to a failing
	 *    colour would fail here rather than quietly flipping this test's
	 *    meaning.
	 * 3. An empty accent produces byte-for-byte the same result as
	 *    passing that resolved default EXPLICITLY -- which is what
	 *    "resolves to the default" means -- while a resolution to
	 *    anything else that fails contrast (ink, say) records an
	 *    acknowledgement instead. The failing control below proves the
	 *    record path is live under this exact fixture, so the empty
	 *    accent's absence is a real "it passed", not a dead code path.
	 *
	 * The one distinction this function genuinely cannot observe -- a
	 * PASSING default versus an unresolvable '' -- is pinned where the
	 * resolved value IS visible: tests/SettingsOccasionsTabTest.php's
	 * test_an_empty_accent_renders_the_real_resolved_default() (the
	 * rendered swatch is the default, not the BLUELINE_TOKEN_INK
	 * failure fallback) and tests/OccasionsResolverTest.php's own
	 * resolved_accent assertion.
	 */
	public function test_apply_aa_overrides_resolves_an_empty_accent_to_the_default(): void {
		$default = blueline_occasion_accent_default();

		$this->assertNotSame( '', $default, 'Fixture guard: the real stylesheet default must resolve.' );
		$this->assertGreaterThanOrEqual(
			blueline_contrast_threshold( 'body' ),
			blueline_contrast_ratio( BLUELINE_TOKEN_INK, $default ),
			'Fixture guard: the resolved default is expected to PASS contrast against ink.'
		);

		$empty = blueline_occasions_apply_aa_overrides(
			array(
				'default-accent' => $this->failing_occasion(
					array(
						'id'     => 'default-accent',
						'accent' => '',
					)
				),
			),
			array( 'default-accent' => true ),
			array(),
			'test-hash',
			7
		);

		$explicit = blueline_occasions_apply_aa_overrides(
			array(
				'default-accent' => $this->failing_occasion(
					array(
						'id'     => 'default-accent',
						'accent' => $default,
					)
				),
			),
			array( 'default-accent' => true ),
			array(),
			'test-hash',
			7
		);

		// Same inputs bar the empty-vs-explicit accent: same result.
		$this->assertSame( $explicit, $empty );

		// The resolved default passes, so nothing is recorded for it...
		$this->assertArrayNotHasKey( 'occasion:default-accent', $empty );

		// ...and that absence is meaningful, not vacuous: the same
		// fixture, resolved to a FAILING colour instead, does record.
		$control = blueline_occasions_apply_aa_overrides(
			array(
				'default-accent' => $this->failing_occasion(
					array(
						'id'     => 'default-accent',
						'accent' => BLUELINE_TOKEN_INK,
					)
				),
			),
			array( 'default-accent' => true ),
			array(),
			'test-hash',
			7
		);

		$this->assertArrayHasKey( 'occasion:default-accent', $control );
		$this->assertSame( BLUELINE_TOKEN_INK, $control['occasion:default-accent']['value'] );
	}

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
				'window' => array(
					'start_md' => '07-01',
					'end_md'   => '07-01',
				),
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
}
