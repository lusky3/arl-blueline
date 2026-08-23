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
	 */
	protected function setUp(): void {
		blueline_test_reset();
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
