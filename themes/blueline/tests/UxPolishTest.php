<?php
/**
 * UX polish: schedule team names without archive suffixes, player photos
 * only when their file exists, the empty states' next-step links, the
 * "Never played? Perfect." module's closing button, and the header's search
 * button, panel and drawer row.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * See this file's own docblock.
 */
final class UxPolishTest extends TestCase {

	/**
	 * A scratch uploads folder for the photo tests.
	 *
	 * @var string
	 */
	private string $uploads = '';

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Remove the scratch uploads folder.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_upload_basedir'] );

		if ( '' !== $this->uploads && is_dir( $this->uploads ) ) {
			foreach ( (array) glob( $this->uploads . '/2018/*' ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test scratch file.
			}
			rmdir( $this->uploads . '/2018' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test scratch dir.
			rmdir( $this->uploads ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test scratch dir.
		}
	}

	/**
	 * An archived team's "(Retired)" prefix and season codes are dropped in
	 * a schedule cell; ordinary names (sponsor in brackets too) are untouched.
	 */
	public function test_schedule_team_names_drop_archive_suffixes(): void {
		$this->assertSame( 'Woodchester Nissan (Rogues)', blueline_sp_table_team_name( '(Retired) Woodchester Nissan (Rogues) (W2015-16)' ) );
		$this->assertSame( 'Bruins (BMG School)', blueline_sp_table_team_name( '(Retired) Bruins (BMG School) (S2016, W2016-17)' ) );
		$this->assertSame( 'Ice Bears', blueline_sp_table_team_name( '(Retired) Ice Bears (W2015-16)' ) );
		$this->assertSame( 'B-Town Bulldogs (TeamSmulders.com)', blueline_sp_table_team_name( 'B-Town Bulldogs (TeamSmulders.com)' ) );
		$this->assertSame( 'Whalers', blueline_sp_table_team_name( 'Whalers' ) );
		$this->assertSame( '(W2015-16)', blueline_sp_table_team_name( '(W2015-16)' ), 'never strips a name down to nothing' );
	}

	/**
	 * Seed player 700 with featured image 701, stored in the uploads folder,
	 * with a 150x150 thumbnail file that does or does not exist.
	 *
	 * @param bool $thumb_exists Whether the thumbnail file is on disk.
	 */
	private function seed_photo( bool $thumb_exists ): void {
		$this->uploads = sys_get_temp_dir() . '/bl-uploads-' . getmypid();
		mkdir( $this->uploads . '/2018', 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test scratch dir.
		$GLOBALS['bl_test_upload_basedir'] = $this->uploads;

		touch( $this->uploads . '/2018/jim.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test scratch file.
		if ( $thumb_exists ) {
			touch( $this->uploads . '/2018/jim-150x150.jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- test scratch file.
		}

		$state               = &blueline_test_state();
		$state['posts'][700] = array(
			'status'       => 'publish',
			'thumbnail_id' => 701,
		);
		$state['posts'][701] = array(
			'status' => 'inherit',
			'file'   => $this->uploads . '/2018/jim.jpg',
			'meta'   => array( 'sizes' => array( 'thumbnail' => array( 'file' => 'jim-150x150.jpg' ) ) ),
		);
	}

	/**
	 * A photo whose thumbnail file is on disk is shown.
	 */
	public function test_a_photo_with_its_file_is_shown(): void {
		$this->seed_photo( true );

		$this->assertTrue( blueline_player_photo_available( 700 ) );
	}

	/**
	 * A photo whose thumbnail file has gone missing is not printed, so no
	 * broken image and no shifted roster.
	 */
	public function test_a_photo_whose_file_is_missing_is_not_shown(): void {
		$this->seed_photo( false );

		$this->assertFalse( blueline_player_photo_available( 700 ) );
	}

	/**
	 * No featured image, no photo; media stored outside the uploads folder
	 * is trusted; the filter has the last word.
	 */
	public function test_no_image_outside_uploads_and_the_filter(): void {
		$this->assertFalse( blueline_player_photo_available( 700 ) );

		$state               = &blueline_test_state();
		$state['posts'][700] = array( 'thumbnail_id' => 701 );
		$state['posts'][701] = array( 'file' => 's3://bucket/jim.jpg' );
		$this->assertTrue( blueline_player_photo_available( 700 ), 'offloaded media is not checked on disk' );

		add_filter( 'blueline_player_photo_available', static fn() => false );
		$this->assertFalse( blueline_player_photo_available( 700 ) );
	}

	/**
	 * The dead-end links: Schedule, Standings and Register, in that order.
	 */
	public function test_empty_states_offer_three_next_steps(): void {
		ob_start();
		blueline_empty_state_links();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<ul class="bl-empty-state__links">', $html );
		$this->assertSame( 3, substr_count( $html, '<li>' ) );
		$this->assertMatchesRegularExpression( '#Schedule.*Standings.*Register to Play#s', $html );
		$this->assertStringContainsString( 'bl-btn--primary', $html );
	}

	/**
	 * The no-results page ends on those links, not on a search box alone.
	 */
	public function test_the_no_results_state_has_the_links(): void {
		$GLOBALS['bl_test_is_search'] = true;

		ob_start();
		include dirname( __DIR__ ) . '/content-none.php';
		$html = (string) ob_get_clean();

		unset( $GLOBALS['bl_test_is_search'] );

		$this->assertStringContainsString( 'No results found', $html );
		$this->assertStringContainsString( 'bl-empty-state__links', $html );
		$this->assertStringContainsString( 'class="search-form"', $html );
	}

	/**
	 * "Never played? Perfect." ends on the step a newcomer can take today.
	 */
	public function test_new_here_cta_follows_what_is_on_offer(): void {
		$register = blueline_homepage_new_here_cta( true, false );
		$this->assertSame( 'Register now', $register['label'] );
		$this->assertSame( blueline_resolve_link( 'page_register' ), $register['url'] );
		$this->assertSame( 'primary', $register['variant'] );

		$waitlist = blueline_homepage_new_here_cta( false, true );
		$this->assertSame( 'Join the waitlist', $waitlist['label'] );
		$this->assertSame( blueline_resolve_link( 'page_register' ), $waitlist['url'] );

		$offseason = blueline_homepage_new_here_cta( false, false );
		$this->assertSame( 'Join the mailing list', $offseason['label'] );
		$this->assertSame( blueline_contact_url(), $offseason['url'] );
		$this->assertSame( 'secondary', $offseason['variant'] );
	}

	/**
	 * The header carries a named search button (printed hidden until its
	 * script runs) that controls a hidden panel holding a search landmark,
	 * and the drawer's menu list ends with its own search row.
	 */
	public function test_the_header_has_a_search_button_panel_and_drawer_row(): void {
		ob_start();
		blueline_site_header();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '#<button type="button" class="bl-search-toggle" aria-expanded="false" aria-controls="bl-header-search" hidden>\s*<svg[^>]*aria-hidden="true"[^>]*>.*?</svg>\s*<span class="screen-reader-text">Search</span>\s*</button>#s', $html );
		$this->assertMatchesRegularExpression( '#<div class="bl-header__search" id="bl-header-search" hidden>\s*<div class="bl-container">\s*<form role="search" aria-label="Site"#', $html );

		$item = blueline_nav_search_item();
		$this->assertStringStartsWith( '<li class="bl-nav__item bl-nav__search">', $item );
		$this->assertStringContainsString( '<form role="search" aria-label="Site"', $item );
	}
}
