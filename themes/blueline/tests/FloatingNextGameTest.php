<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/floating-next-game.php';

/**
 * The floating next-game widget's own markup and where it renders.
 *
 * Each test runs in its own process: blueline_current_user_player_id() lives
 * in the blueline-core plugin and must stay undefined in the shared process
 * (CorePluginAbsentTest), and the WooCommerce conditional tags are defined
 * per test below.
 */
final class FloatingNextGameTest extends TestCase {

	/**
	 * Define the plugin and WooCommerce stand-ins and a signed-in, claimed
	 * visitor with a real upcoming game. Must run inside a separate process.
	 *
	 * @param array<string, bool> $woo   WooCommerce conditional tags that should answer true.
	 * @param array               $event Fields of the next game that differ from the default one.
	 */
	private function arrange( array $woo = array(), array $event = array() ): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_woo']        = $woo;
		$GLOBALS['bl_test_next_event'] = $event + array(
			'event_id'         => 700,
			'team_id'          => 0,
			'timestamp'        => 1,
			'venue'            => 'Red',
			'venue_term_id'    => null,
			'opponent_team_id' => null,
			'is_home'          => true,
			'status'           => 'ok',
			'fingerprint'      => 'abc123',
		);

		eval( 'function blueline_current_user_player_id() { return 12; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stands in for the blueline-core function, which must stay undefined in the shared process (CorePluginAbsentTest).
		eval( 'function blueline_get_player_next_event( int $player_id ): ?array { return $GLOBALS["bl_test_next_event"]; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- same reason; player-data.php is not loaded here so the event can be fixed.
		eval( 'function is_account_page() { return ! empty( $GLOBALS["bl_test_woo"]["account"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_cart() { return ! empty( $GLOBALS["bl_test_woo"]["cart"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.
		eval( 'function is_checkout() { return ! empty( $GLOBALS["bl_test_woo"]["checkout"] ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- WooCommerce conditional tag stand-in.

		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['posts'][700]      = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/event/700',
			'type'      => 'sp_event',
			'title'     => 'Game',
		);
	}

	/**
	 * Render the widget and return its markup.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		blueline_render_floating_next_game();
		return (string) ob_get_clean();
	}

	/**
	 * The happy path still renders.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_renders_on_an_ordinary_page(): void {
		$this->arrange();

		$this->assertStringContainsString( 'bl-floating-next-game', $this->render() );
	}

	/**
	 * It would sit over the very form the visitor is filling in, and on the
	 * dashboard it repeats the My next game card.
	 *
	 * @param string $tag The WooCommerce conditional tag that answers true.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'woo_pages' )]
	public function test_renders_nothing_on_account_cart_and_checkout_pages( string $tag ): void {
		$this->arrange( array( $tag => true ) );

		$this->assertSame( '', trim( $this->render() ) );
	}

	/**
	 * The head loads the widget's stylesheet and script on the same pages the
	 * widget can render on, before the upcoming-game lookup.
	 *
	 * @param string $tag The WooCommerce conditional tag that answers true.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	#[DataProvider( 'woo_pages' )]
	public function test_may_render_is_false_on_account_cart_and_checkout( string $tag ): void {
		$this->arrange( array( $tag => true ) );

		$this->assertFalse( blueline_floating_next_game_may_render() );
	}

	/**
	 * A signed-in, claimed visitor on an ordinary page; nobody signed out.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_may_render_needs_a_signed_in_claimed_visitor(): void {
		$this->arrange();
		$this->assertTrue( blueline_floating_next_game_may_render() );

		$state                    = &blueline_test_state();
		$state['current_user_id'] = 0;
		$this->assertFalse( blueline_floating_next_game_may_render() );
	}

	/**
	 * The WooCommerce pages the widget stays off.
	 *
	 * @return array<string, array{string}>
	 */
	public static function woo_pages(): array {
		return array(
			'account'  => array( 'account' ),
			'cart'     => array( 'cart' ),
			'checkout' => array( 'checkout' ),
		);
	}

	/**
	 * It is a labelled region ("Your next game"), not an unlabelled status
	 * that is never announced because it is present at load.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_is_a_labelled_region(): void {
		$this->arrange();
		$html = $this->render();

		$this->assertStringContainsString( 'role="region"', $html );
		$this->assertStringNotContainsString( 'role="status"', $html );
		$this->assertMatchesRegularExpression( '/aria-labelledby="(bl-next-game-label)"/', $html );
		$this->assertStringContainsString( 'id="bl-next-game-label"', $html );
		$this->assertStringContainsString( 'Your next game', $html );
	}

	/**
	 * The venue link announces as a venue, not as the bare word "Red".
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_venue_has_screen_reader_context(): void {
		$this->arrange();

		$this->assertMatchesRegularExpression( '/screen-reader-text">Venue: <\/span>\s*Red/', $this->render() );
	}

	/**
	 * Reading order matches visual order: the dismiss control follows the
	 * content it dismisses.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_dismiss_control_comes_after_the_content(): void {
		$this->arrange();
		$html = $this->render();

		$this->assertGreaterThan(
			(int) strpos( $html, 'bl-floating-next-game__matchup' ),
			(int) strpos( $html, 'data-bl-next-game-dismiss' )
		);
	}

	/**
	 * A compact form of the date is printed for the phone bar; it is hidden
	 * from assistive tech there by CSS (display: none), not duplicated aloud.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_prints_a_compact_date_alongside_the_full_one(): void {
		$this->arrange();
		$html = $this->render();

		$this->assertStringContainsString( 'bl-floating-next-game__date-full', $html );
		$this->assertStringContainsString( 'bl-floating-next-game__date-short', $html );
	}

	/**
	 * A game whose time is not settled says "Time TBD" in both the card and
	 * the phone bar, never a firm-looking time.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_a_tbd_game_says_time_tbd_not_a_time(): void {
		$this->arrange( array(), array( 'status' => 'tbd' ) );
		$html = $this->render();

		$this->assertStringContainsString( 'Aug 20 · Time TBD', $html );
		$this->assertMatchesRegularExpression( '#date-short">\s*Aug 20 · Time TBD\s*<#', $html );
		$this->assertStringNotContainsString( '7:00 PM', $html );
		$this->assertStringNotContainsString( '7:00 pm', $html );
	}

	/**
	 * An on-time game keeps its time, in the card and in the bar.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_an_on_time_game_keeps_its_time(): void {
		$this->arrange();
		$html = $this->render();

		$this->assertStringContainsString( 'Aug 20 at 7:00 PM', $html );
		$this->assertStringNotContainsString( 'Time TBD', $html );
	}

	/**
	 * A player on two current teams has to be told which team is playing.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_matchup_names_the_players_own_team(): void {
		$this->arrange(
			array(),
			array(
				'team_id'          => 31,
				'opponent_team_id' => 32,
				'is_home'          => false,
			)
		);
		$state              = &blueline_test_state();
		$state['posts'][31] = array( 'title' => 'Kings' );
		$state['posts'][32] = array( 'title' => 'Ducks' );
		$html               = $this->render();

		// "@" is shown, and "at" is what a screen reader says (some voices
		// read the glyph as "at sign").
		$this->assertMatchesRegularExpression(
			'#__matchup"[^>]*>\s*Kings <span aria-hidden="true">@</span><span class="screen-reader-text">at</span> Ducks\s*</a>#',
			$html
		);
	}

	/**
	 * The "Updated" badge says what changed, for anyone who cannot infer it
	 * from where it sits.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_updated_badge_explains_itself(): void {
		$this->arrange();

		$this->assertMatchesRegularExpression(
			'#data-bl-next-game-updated hidden>\s*Updated<span class="screen-reader-text">: the details of this game changed</span>\s*</span>#',
			$this->render()
		);
	}

	/**
	 * It still reads sensibly when the team is not known.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_the_matchup_without_a_team_is_just_the_opponent(): void {
		$this->arrange();

		$this->assertMatchesRegularExpression( '#__matchup"[^>]*>\s*vs TBD\s*</a>#', $this->render() );
	}
}
