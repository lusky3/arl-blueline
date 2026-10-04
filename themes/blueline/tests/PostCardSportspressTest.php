<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * C-20/B-13: league, season and position archives list SportsPress posts as
 * blog cards: say what each one is, and skip excerpts that are junk.
 */
final class PostCardSportspressTest extends TestCase {

	/**
	 * Each SportsPress type gets a short label; games carry their date.
	 */
	public function test_labels(): void {
		$this->assertSame( 'Team', blueline_sp_post_card_label( 'sp_team' ) );
		$this->assertSame( 'Player', blueline_sp_post_card_label( 'sp_player', 'ignored' ) );
		$this->assertSame( 'Player list', blueline_sp_post_card_label( 'sp_list' ) );
		$this->assertSame( 'Game · March 6, 2020', blueline_sp_post_card_label( 'sp_event', 'March 6, 2020' ) );
		$this->assertSame( 'Game', blueline_sp_post_card_label( 'sp_event' ) );
		$this->assertSame( '', blueline_sp_post_card_label( 'post' ) );
		$this->assertSame( '', blueline_sp_post_card_label( 'page' ) );
	}

	/**
	 * Posts, pages and games keep the excerpt; SportsPress data posts only with a manual one.
	 */
	public function test_excerpt_visibility(): void {
		$this->assertTrue( blueline_post_card_shows_excerpt( 'post', false ) );
		$this->assertTrue( blueline_post_card_shows_excerpt( 'page', false ) );
		$this->assertTrue( blueline_post_card_shows_excerpt( 'sp_event', false ) );
		$this->assertFalse( blueline_post_card_shows_excerpt( 'sp_team', false ) );
		$this->assertFalse( blueline_post_card_shows_excerpt( 'sp_player', false ) );
		$this->assertTrue( blueline_post_card_shows_excerpt( 'sp_team', true ) );
	}

	/**
	 * The teaser template uses both helpers.
	 */
	public function test_content_template_wiring(): void {
		$src = (string) file_get_contents( __DIR__ . '/../content.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( 'blueline_sp_post_card_label(', $src );
		$this->assertStringContainsString( 'blueline_post_card_shows_excerpt(', $src );
	}
}
