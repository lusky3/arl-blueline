<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- the wp_parse_args() stub lives beside the one test that needs it.

use PHPUnit\Framework\TestCase;

if ( ! defined( 'BLUELINE_URI' ) ) {
	define( 'BLUELINE_URI', 'https://example.test/wp-content/themes/blueline' );
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Minimal stand-in for wp_parse_args(): arrays pass through, strings are query strings.
	 *
	 * @param array|string $args     Arguments.
	 * @param array        $defaults Defaults.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_string( $args ) ) {
			parse_str( $args, $args );
		}

		return array_merge( $defaults, (array) $args );
	}
}

require_once __DIR__ . '/../inc/sportspress/default-team-logo.php';

/**
 * A team with no logo gets the placeholder badge; nothing else changes.
 */
final class DefaultTeamLogoTest extends TestCase {

	/**
	 * Reset state and register one team and one player.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
		unset( $GLOBALS['bl_test_is_admin'] );

		$state              = &blueline_test_state();
		$state['posts'][10] = array( 'type' => 'sp_team' );
		$state['posts'][11] = array(
			'type'         => 'sp_team',
			'thumbnail_id' => 77,
		);
		$state['posts'][20] = array( 'type' => 'sp_player' );
	}

	/**
	 * Test case.
	 */
	public function test_a_team_without_a_logo_reports_the_placeholder_id(): void {
		$this->assertSame( BLUELINE_DEFAULT_TEAM_LOGO_ID, blueline_default_team_logo_id( 0, 10 ) );
		$this->assertSame( BLUELINE_DEFAULT_TEAM_LOGO_ID, blueline_default_team_logo_id( false, 10 ) );
	}

	/**
	 * Test case.
	 */
	public function test_a_real_logo_and_non_team_posts_are_left_alone(): void {
		$this->assertSame( 77, blueline_default_team_logo_id( 77, 11 ) );
		$this->assertSame( 0, blueline_default_team_logo_id( 0, 20 ), 'A player with no photo keeps no thumbnail.' );
	}

	/**
	 * Test case.
	 */
	public function test_the_admin_keeps_seeing_the_real_no_logo_state(): void {
		$GLOBALS['bl_test_is_admin'] = true;

		$this->assertSame( 0, blueline_default_team_logo_id( 0, 10 ) );
	}

	/**
	 * Test case.
	 */
	public function test_the_placeholder_id_gets_the_default_image_markup(): void {
		$html = blueline_default_team_logo_html( '', 10, BLUELINE_DEFAULT_TEAM_LOGO_ID, 'thumbnail', '' );

		$this->assertStringStartsWith( '<img', $html );
		$this->assertStringContainsString( 'src="' . BLUELINE_URI . '/assets/images/default-team-logo.svg?ver=', $html );
		$this->assertStringContainsString( 'alt=""', $html );
		$this->assertStringContainsString( 'class="attachment-thumbnail size-thumbnail wp-post-image bl-default-team-logo"', $html );
	}

	/**
	 * Test case.
	 */
	public function test_caller_attributes_are_merged_and_escaped(): void {
		$html = blueline_default_team_logo_html(
			'',
			10,
			BLUELINE_DEFAULT_TEAM_LOGO_ID,
			array( 24, 24 ),
			array(
				'itemprop' => 'logo',
				'class'    => 'team-logo',
				'title'    => 'A "quoted" team',
			)
		);

		$this->assertStringContainsString( 'itemprop="logo"', $html );
		$this->assertStringContainsString( 'size-24x24', $html );
		$this->assertStringContainsString( 'bl-default-team-logo team-logo"', $html );
		$this->assertStringNotContainsString( 'A "quoted"', $html );
	}

	/**
	 * Test case.
	 */
	public function test_real_logo_markup_is_never_replaced(): void {
		$real = '<img src="logo.png" />';

		$this->assertSame( $real, blueline_default_team_logo_html( $real, 11, 77, 'thumbnail', '' ) );
	}

	/**
	 * Test case.
	 */
	public function test_a_real_logo_whose_image_file_is_missing_gets_the_default(): void {
		$html = blueline_default_team_logo_html( '', 11, 77, 'thumbnail', '' );

		$this->assertStringContainsString( 'default-team-logo.svg', $html );
	}

	/**
	 * Test case.
	 */
	public function test_non_team_posts_never_get_a_default_image(): void {
		$this->assertSame( '', blueline_default_team_logo_html( '', 20, 0, 'thumbnail', '' ) );
	}

	/**
	 * Test case.
	 */
	public function test_the_url_filter_answers_only_for_a_logo_less_team(): void {
		$this->assertStringStartsWith( BLUELINE_URI . '/assets/images/default-team-logo.svg?ver=', blueline_default_team_logo_url_filter( false, 10 ) );
		$this->assertSame( 'https://x/logo.png', blueline_default_team_logo_url_filter( 'https://x/logo.png', 11 ) );
		$this->assertFalse( blueline_default_team_logo_url_filter( false, 20 ) );
	}

	/**
	 * Test case.
	 */
	public function test_the_bundled_default_image_exists(): void {
		$this->assertFileExists( BLUELINE_DIR . '/assets/images/default-team-logo.svg' );
	}
}
