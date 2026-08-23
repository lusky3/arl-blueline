<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/theme-preference.php';

/**
 * Covers the light/dark/system appearance preference: the read/clamp
 * accessor, the account-form save handler, and the language_attributes
 * filter that renders it onto <html>.
 */
final class ThemePreferenceTest extends TestCase {

	/**
	 * Reset every in-memory store before each test, and make sure no
	 * leftover $_POST from one test leaks into the next.
	 *
	 * Deliberately blueline_test_reset_state(), not the narrower
	 * blueline_test_reset(): this suite's first draft called the latter,
	 * which resets hooks/options/cache/cron but leaves user_meta and
	 * current_user_id untouched, and it took reusing the same user id
	 * across two tests with genuinely different stored values to surface
	 * it -- several earlier tests happened to still pass with the wrong
	 * reset, purely because their leftover value coincidentally clamped
	 * to the same expected result either way.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		unset( $_POST['blueline_theme_preference'] );
	}

	// -----------------------------------------------------------------------
	// blueline_get_theme_preference()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_get_preference_defaults_to_system_when_no_meta_is_stored(): void {
		$this->assertSame( 'system', blueline_get_theme_preference( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_get_preference_returns_a_stored_valid_value(): void {
		$state = &blueline_test_state();
		$state['user_meta'][5]['blueline_theme_preference'] = 'dark';

		$this->assertSame( 'dark', blueline_get_theme_preference( 5 ) );
	}

	/**
	 * Test case.
	 */
	public function test_get_preference_clamps_an_unrecognised_stored_value_to_system(): void {
		// A stray import or a direct meta edit could leave anything here --
		// only the three known values are ever trusted.
		$state = &blueline_test_state();
		$state['user_meta'][5]['blueline_theme_preference'] = 'solarized';

		$this->assertSame( 'system', blueline_get_theme_preference( 5 ) );
	}

	// -----------------------------------------------------------------------
	// blueline_render_theme_preference_field()
	// -----------------------------------------------------------------------

	/**
	 * Capture a renderer's echoed output.
	 *
	 * @param callable $renderer Zero-arg callable that echoes markup.
	 * @return string
	 */
	private function render( callable $renderer ): string {
		ob_start();
		$renderer();
		return (string) ob_get_clean();
	}

	/**
	 * Test case.
	 */
	public function test_field_marks_the_stored_preference_as_selected(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;
		$state['user_meta'][9]['blueline_theme_preference'] = 'dark';

		$html = $this->render( 'blueline_render_theme_preference_field' );

		$this->assertMatchesRegularExpression(
			'/<option value="dark"\s+selected="selected">/',
			$html,
			"the stored 'dark' preference must be the selected <option>"
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="light" selected/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/<option value="system" selected/',
			$html
		);
	}

	/**
	 * Test case.
	 */
	public function test_field_defaults_to_system_selected_when_nothing_is_stored(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;

		$html = $this->render( 'blueline_render_theme_preference_field' );

		$this->assertMatchesRegularExpression( '/<option value="system"\s+selected="selected">/', $html );
	}

	/**
	 * Test case.
	 */
	public function test_field_renders_exactly_the_three_known_preferences(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;

		$html = $this->render( 'blueline_render_theme_preference_field' );

		$this->assertSame( 3, preg_match_all( '/<option value="[^"]+"/', $html ) );
		$this->assertStringContainsString( '<option value="system"', $html );
		$this->assertStringContainsString( '<option value="light"', $html );
		$this->assertStringContainsString( '<option value="dark"', $html );
	}

	/**
	 * Test case.
	 */
	public function test_field_labels_the_select_for_accessibility(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;

		$html = $this->render( 'blueline_render_theme_preference_field' );

		$this->assertStringContainsString( '<label for="blueline_theme_preference">', $html );
		$this->assertStringContainsString( 'id="blueline_theme_preference"', $html );
	}

	// -----------------------------------------------------------------------
	// blueline_save_theme_preference()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_save_persists_a_valid_submitted_value(): void {
		$_POST['blueline_theme_preference'] = 'light';

		blueline_save_theme_preference( 7 );

		$this->assertSame( 'light', blueline_get_theme_preference( 7 ) );
	}

	/**
	 * Test case.
	 */
	public function test_save_clamps_an_unrecognised_submitted_value_to_system(): void {
		// Anything a browser could plausibly send that isn't one of the
		// <select>'s own three <option> values -- e.g. a tampered request.
		$_POST['blueline_theme_preference'] = 'not-a-real-theme';

		blueline_save_theme_preference( 7 );

		$this->assertSame( 'system', blueline_get_theme_preference( 7 ) );
	}

	/**
	 * Test case.
	 */
	public function test_save_defaults_to_system_when_the_field_is_missing_entirely(): void {
		// The field is always rendered by blueline_render_theme_preference_field(),
		// but the save handler must not fatal or warn on a request that
		// omits it (e.g. a modified form submission).
		blueline_save_theme_preference( 7 );

		$this->assertSame( 'system', blueline_get_theme_preference( 7 ) );
	}

	// -----------------------------------------------------------------------
	// blueline_theme_preference_html_attribute()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_html_attribute_adds_nothing_for_a_guest(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;

		// Even a guest with a matching preference row (impossible in
		// practice, since guests can't save one) must not render it --
		// is_user_logged_in() is the gate, not merely "does meta exist".
		$state['user_meta'][0]['blueline_theme_preference'] = 'dark';

		$this->assertSame( 'lang="en-US"', blueline_theme_preference_html_attribute( 'lang="en-US"' ) );
	}

	/**
	 * Test case.
	 */
	public function test_html_attribute_adds_nothing_for_a_logged_in_user_on_system(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		// No meta stored at all -- the documented "absence is system" case.

		$this->assertSame( 'lang="en-US"', blueline_theme_preference_html_attribute( 'lang="en-US"' ) );
	}

	/**
	 * Test case.
	 */
	public function test_html_attribute_appends_data_theme_dark(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['user_meta'][5]['blueline_theme_preference'] = 'dark';

		$this->assertSame(
			'lang="en-US" data-theme="dark"',
			blueline_theme_preference_html_attribute( 'lang="en-US"' )
		);
	}

	/**
	 * Test case.
	 */
	public function test_html_attribute_appends_data_theme_light(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['user_meta'][5]['blueline_theme_preference'] = 'light';

		$this->assertSame(
			'lang="en-US" data-theme="light"',
			blueline_theme_preference_html_attribute( 'lang="en-US"' )
		);
	}
}
