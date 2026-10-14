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
require_once __DIR__ . '/../inc/sportspress.php';
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
	 * @param array<string, bool> $woo WooCommerce conditional tags that should answer true.
	 */
	private function arrange( array $woo = array() ): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_woo'] = $woo;

		eval( 'function blueline_current_user_player_id() { return 12; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- stands in for the blueline-core function, which must stay undefined in the shared process (CorePluginAbsentTest).
		eval( 'function blueline_get_player_next_event( int $player_id ): ?array { return array( "event_id" => 700, "timestamp" => 1, "venue" => "Red", "venue_term_id" => null, "opponent_team_id" => null, "is_home" => true, "fingerprint" => "abc123" ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- same reason; player-data.php is not loaded here so the event can be fixed.
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
}
