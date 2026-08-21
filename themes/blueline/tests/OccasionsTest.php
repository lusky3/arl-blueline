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
}
