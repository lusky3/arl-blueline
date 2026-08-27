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
		unset( $_POST['preference'] );
		unset( $_REQUEST['nonce'] );
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

	// -----------------------------------------------------------------------
	// blueline_persist_theme_preference()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_persist_stores_a_valid_value_and_returns_it(): void {
		$this->assertSame( 'dark', blueline_persist_theme_preference( 11, 'dark' ) );
		$this->assertSame( 'dark', blueline_get_theme_preference( 11 ) );
	}

	/**
	 * Test case.
	 */
	public function test_persist_clamps_an_invalid_value_to_system_and_returns_it(): void {
		$this->assertSame( 'system', blueline_persist_theme_preference( 11, 'not-a-real-theme' ) );
		$this->assertSame( 'system', blueline_get_theme_preference( 11 ) );
	}

	// -----------------------------------------------------------------------
	// blueline_render_theme_toggle()
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
	public function test_toggle_renders_guest_mode_with_system_pressed_and_no_nonce(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;

		$html = $this->render( 'blueline_render_theme_toggle' );

		$this->assertStringContainsString( 'data-bl-theme-toggle-mode="guest"', $html );
		$this->assertStringNotContainsString( 'data-bl-theme-toggle-nonce', $html );
		$this->assertStringNotContainsString( 'data-bl-theme-toggle-ajax-url', $html );
		$this->assertMatchesRegularExpression(
			'/data-bl-theme-toggle-option="system"\s+aria-pressed="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/data-bl-theme-toggle-option="light"\s+aria-pressed="false"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/data-bl-theme-toggle-option="dark"\s+aria-pressed="false"/',
			$html
		);
	}

	/**
	 * Test case.
	 */
	public function test_toggle_renders_account_mode_with_the_stored_preference_pressed(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 9;
		$state['user_meta'][9]['blueline_theme_preference'] = 'dark';

		$html = $this->render( 'blueline_render_theme_toggle' );

		$this->assertStringContainsString( 'data-bl-theme-toggle-mode="account"', $html );
		$this->assertStringContainsString( 'data-bl-theme-toggle-nonce="', $html );
		$this->assertStringContainsString( 'data-bl-theme-toggle-ajax-url="', $html );
		$this->assertMatchesRegularExpression(
			'/data-bl-theme-toggle-option="dark"\s+aria-pressed="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/data-bl-theme-toggle-option="system"\s+aria-pressed="false"/',
			$html
		);
	}

	/**
	 * Test case.
	 */
	public function test_toggle_renders_exactly_the_three_known_options(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;

		$html = $this->render( 'blueline_render_theme_toggle' );

		$this->assertSame( 3, preg_match_all( '/data-bl-theme-toggle-option="[^"]+"/', $html ) );
	}

	// -----------------------------------------------------------------------
	// blueline_render_guest_theme_bootstrap_script()
	// -----------------------------------------------------------------------

	/**
	 * Test case.
	 */
	public function test_bootstrap_script_renders_for_a_guest(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;

		$html = $this->render( 'blueline_render_guest_theme_bootstrap_script' );

		$this->assertStringContainsString( '<script>', $html );
		$this->assertStringContainsString( 'blueline:theme-preference', $html );
	}

	/**
	 * Test case.
	 */
	public function test_bootstrap_script_renders_nothing_for_a_logged_in_visitor(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;

		$html = $this->render( 'blueline_render_guest_theme_bootstrap_script' );

		$this->assertSame( '', $html );
	}

	// -----------------------------------------------------------------------
	// blueline_ajax_save_theme_preference()
	// -----------------------------------------------------------------------

	/**
	 * Run the AJAX handler, capturing the JSON it echoes before it ends in
	 * wp_die() (raised here as Blueline_Test_WP_Die_Exception, same as
	 * every other wp_send_json_*() call in this stub environment -- see
	 * tests/bootstrap.php's own wp_send_json() docblock). Every code path
	 * through blueline_ajax_save_theme_preference() ends this way, success
	 * or failure alike, exactly like real WordPress core.
	 *
	 * @return string The JSON-encoded response body.
	 */
	private function run_ajax_handler(): string {
		ob_start();
		try {
			blueline_ajax_save_theme_preference();
		} catch ( Blueline_Test_WP_Die_Exception $e ) {
			unset( $e );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Test case.
	 */
	public function test_ajax_handler_rejects_a_logged_out_request(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;

		$_POST['preference'] = 'dark';

		$json = $this->run_ajax_handler();

		$this->assertStringContainsString( '"success":false', $json );
		$this->assertSame( 'system', blueline_get_theme_preference( 0 ), 'nothing may be saved while logged out' );
	}

	/**
	 * Test case.
	 */
	public function test_ajax_handler_rejects_an_invalid_nonce(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 7;
		$_REQUEST['nonce']        = 'not-the-right-token'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- deliberately wrong, that is the point of this case.
		$_POST['preference']      = 'dark';

		$json = $this->run_ajax_handler();

		// check_ajax_referer()'s own wp_die( -1 ) does not echo JSON (this
		// stub's wp_die() stand-in only ever throws) -- what proves the
		// rejection here is the same thing SettingsDeleteDataTest's own
		// nonce-rejection test proves: nothing was persisted.
		$this->assertSame( '', $json );
		$this->assertSame( 'system', blueline_get_theme_preference( 7 ), 'nothing may be saved without a valid nonce' );
	}

	/**
	 * Test case.
	 */
	public function test_ajax_handler_persists_a_valid_submitted_value(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 7;
		$_REQUEST['nonce']        = wp_create_nonce( 'blueline_save_theme_preference' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['preference']      = 'dark';

		$json = $this->run_ajax_handler();

		$this->assertStringContainsString( '"success":true', $json );
		$this->assertStringContainsString( '"preference":"dark"', $json );
		$this->assertSame( 'dark', blueline_get_theme_preference( 7 ) );
	}

	/**
	 * Test case.
	 */
	public function test_ajax_handler_clamps_an_invalid_submitted_value_to_system(): void {
		$state                    = &blueline_test_state();
		$state['current_user_id'] = 7;
		$state['user_meta'][7]['blueline_theme_preference'] = 'light';
		$_REQUEST['nonce']                                  = wp_create_nonce( 'blueline_save_theme_preference' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- seeding the token the code under test verifies.
		$_POST['preference']                                = 'not-a-real-theme';

		$json = $this->run_ajax_handler();

		$this->assertStringContainsString( '"preference":"system"', $json );
		$this->assertSame( 'system', blueline_get_theme_preference( 7 ) );
	}
}
