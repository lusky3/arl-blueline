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

	/**
	 * Resets stored options/hooks/etc. between tests.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Builds a Task-1-shaped Occasion, with overrides.
	 *
	 * @param array<string, mixed> $overrides Keys to override.
	 * @return array<string, mixed>
	 */
	private function occasion( array $overrides = array() ): array {
		return array_merge(
			array(
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
	 * Every id and every `<label for>` inside the `<template>` row
	 * carries the `__TEMPLATE__` placeholder -- the server half of the
	 * contract assets/src/js/settings-occasions.js's
	 * blOccasionApplyRowKey() relies on when it clones this row. If a
	 * field's id ever stopped being derived from the row key, the clone
	 * would have nothing to rewrite and every JS-added row would share
	 * that id (and every caption would point at the first added row's
	 * input). Its JS half is pinned in
	 * assets/src/js/settings-occasions.test.mjs.
	 */
	public function test_the_template_rows_ids_and_label_associations_carry_the_placeholder(): void {
		ob_start();
		blueline_settings_render_occasions_tab();
		$html = (string) ob_get_clean();

		foreach ( array( 'label', 'type', 'start', 'end', 'accent', 'motif', 'line', 'mode' ) as $field ) {
			$this->assertStringContainsString( 'id="bl-occasion-__TEMPLATE__-' . $field . '"', $html );
			$this->assertStringContainsString( 'for="bl-occasion-__TEMPLATE__-' . $field . '"', $html );
		}
	}

	/**
	 * The always-present hidden marker row (see
	 * blueline_settings_render_occasions_tab()'s own docblock): it must
	 * render whether or not any occasion is stored, and must sit OUTSIDE
	 * the repeater `<ul>`, since a "Remove" click only ever removes a
	 * `<li>` inside that list. Without it, an admin deleting every row
	 * posts no `blueline_settings[occasions]` key at all and the save is
	 * a silent no-op.
	 */
	public function test_the_marker_row_always_renders_outside_the_repeater_list(): void {
		$marker = 'name="blueline_settings[occasions][__none__][label]"';

		ob_start();
		blueline_settings_render_occasions_tab();
		$empty_html = (string) ob_get_clean();

		update_option( BLUELINE_SETTINGS_OPTION, array( 'occasions' => array( 'canada-day' => $this->occasion() ) ) );

		ob_start();
		blueline_settings_render_occasions_tab();
		$populated_html = (string) ob_get_clean();

		$this->assertStringContainsString( $marker, $empty_html );
		$this->assertStringContainsString( $marker, $populated_html );

		// Outside the <ul>: it appears before the list opens, so no
		// per-row removal can ever take it with it.
		$this->assertLessThan(
			(int) strpos( $populated_html, '<ul' ),
			(int) strpos( $populated_html, $marker ),
			'The marker field must be rendered before (outside) the repeater list.'
		);
	}

	/**
	 * An occasion with an EMPTY accent renders the REAL resolved default
	 * (blueline_occasion_accent_default()) in its colour swatch and
	 * contrast readout -- not the BLUELINE_TOKEN_INK fallback
	 * blueline_settings_render_occasion_row() only uses when the default
	 * cannot be resolved at all.
	 *
	 * This is the discriminating half of "empty accent means the theme
	 * default": in tests/OccasionsTest.php's
	 * blueline_occasions_apply_aa_overrides() coverage, a resolved-and-
	 * passing default and an unresolvable '' are observationally
	 * identical (both end with no acknowledgement). Here they are not --
	 * the swatch is literally one value or the other.
	 */
	public function test_an_empty_accent_renders_the_real_resolved_default(): void {
		$default = blueline_occasion_accent_default();

		$this->assertNotSame( '', $default, 'Fixture guard: the real stylesheet default must resolve.' );
		$this->assertNotSame( BLUELINE_TOKEN_INK, $default, 'Fixture guard: the default must differ from the failure fallback, or this test proves nothing.' );

		ob_start();
		blueline_settings_render_occasion_row( 'blueline_settings[occasions]', 'canada-day', $this->occasion( array( 'accent' => '' ) ), 'test-hash', array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="' . $default . '" data-bl-occasion-color', $html );
		$this->assertStringNotContainsString( 'value="' . BLUELINE_TOKEN_INK . '" data-bl-occasion-color', $html );

		// The readout is computed from that same resolved value.
		$ratio = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $default );
		$this->assertStringContainsString( number_format( $ratio, 1 ) . ':1', $html );
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
