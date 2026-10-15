<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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
	 * Render the teaser template for a post of $type.
	 *
	 * @param string $type    Post type.
	 * @param string $excerpt A hand-written excerpt, '' for none.
	 * @return string
	 */
	private function render_card( string $type, string $excerpt = '' ): string {
		require_once __DIR__ . '/fixtures/loop-stubs.php';

		blueline_test_reset_state();
		$state             = &blueline_test_state();
		$state['posts'][0] = array(
			'type'      => $type,
			'title'     => 'Kings',
			'permalink' => 'https://example.test/kings/',
			'excerpt'   => $excerpt,
		);

		ob_start();
		include __DIR__ . '/../content.php';

		return (string) ob_get_clean();
	}

	/**
	 * The teaser template uses both helpers: a SportsPress team card carries its type label and no
	 * excerpt, and has no "continue reading" link (the card itself is the destination).
	 */
	#[RunInSeparateProcess]
	public function test_a_team_card_is_labelled_and_has_no_excerpt_or_continue_link(): void {
		$html = $this->render_card( 'sp_team' );

		$this->assertStringContainsString( '<span class="bl-entry-meta__byline">Team</span>', $html );
		$this->assertStringNotContainsString( 'bl-post-card__excerpt', $html );
		$this->assertStringNotContainsString( 'bl-post-card__more', $html );
		$this->assertStringContainsString( '<h2 class="bl-post-card__title"><a href="https://example.test/kings/" rel="bookmark">Kings</a></h2>', $html );
	}

	/**
	 * A team with a hand-written excerpt does show it.
	 */
	#[RunInSeparateProcess]
	public function test_a_team_card_with_a_hand_written_excerpt_shows_it(): void {
		$html = $this->render_card( 'sp_team', 'Founded 2012.' );

		$this->assertStringContainsString( '<div class="bl-post-card__excerpt">', $html );
		$this->assertStringContainsString( 'Founded 2012.', $html );
	}

	/**
	 * A game card names itself with its date, keeps its excerpt and its continue link; an ordinary post
	 * has no label at all.
	 */
	#[RunInSeparateProcess]
	public function test_a_game_card_is_dated_and_an_ordinary_post_is_unlabelled(): void {
		$game = $this->render_card( 'sp_event' );

		$this->assertStringContainsString( '<span class="bl-entry-meta__byline">Game · Aug 20</span>', $game );
		$this->assertStringContainsString( 'bl-post-card__excerpt', $game );
		$this->assertStringContainsString( 'bl-post-card__more', $game );

		$post = $this->render_card( 'post' );

		$this->assertStringNotContainsString( 'bl-entry-meta__byline', $post );
		$this->assertStringContainsString( 'bl-post-card__more', $post );
	}
}
