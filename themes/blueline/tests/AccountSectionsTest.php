<?php
/**
 * Covers Task 3 of the P1b-panel-completion plan: the four My Account
 * dashboard cards (next game, my team, season stats, registration) each obey
 * their own `blueline_section_enabled()` toggle. Guarded at the top of each
 * renderer, not at the three call sites (the dashboard template and the two
 * custom-endpoint handlers at the bottom of inc/account/dashboard.php) --
 * a future template calling one of these renderers directly must not be
 * able to bypass the toggle.
 *
 * The claim card and the claim notice are deliberately excluded from this
 * coverage: they are the only route an unlinked player has to become linked,
 * so blueline_section_definitions() carries no entry for either, and this
 * file proves that omission stays true rather than silently regaining a
 * toggle nobody asked for.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/account/endpoints.php';

/**
 * Covers the section-toggle guard on each of the four account dashboard
 * card renderers.
 */
final class AccountSectionsTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

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
	 * The exact case the brief writes out: disabling `account_my_team`
	 * through the real save pipeline empties the My Team card entirely,
	 * even though the player id passed in is otherwise a normal, renderable
	 * one.
	 */
	public function test_a_disabled_account_card_does_not_render(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_my_team' => false ) );

		$html = $this->render( static fn() => blueline_account_render_my_team( 66 ) );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * `account_next_game` guards blueline_account_render_next_game() the
	 * same way -- a separate assertion because each renderer's guard is its
	 * own early return, not a shared helper the four could silently drift
	 * out of sync with.
	 */
	public function test_a_disabled_next_game_card_does_not_render(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_next_game' => false ) );

		$html = $this->render( static fn() => blueline_account_render_next_game( 66 ) );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * `account_season_stats` guards blueline_account_render_season_stats().
	 */
	public function test_a_disabled_season_stats_card_does_not_render(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_season_stats' => false ) );

		$html = $this->render( static fn() => blueline_account_render_season_stats( 66 ) );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * `account_registration` guards blueline_account_render_registration().
	 */
	public function test_a_disabled_registration_card_does_not_render(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_registration' => false ) );

		$html = $this->render( static fn() => blueline_account_render_registration( 1 ) );

		$this->assertSame( '', trim( $html ) );
	}

	/**
	 * Unset means enabled (blueline_section_enabled()'s own contract): an
	 * install that has never opened the Sections tab must keep rendering
	 * every account card exactly as it did before this task, so the guard
	 * itself must never be the reason a card goes missing on a fresh
	 * install. `66` has no registered team/player data in this bare stub
	 * environment, so the module renders its own empty state rather than
	 * nothing -- proving the guard let it past, not that the module always
	 * has content.
	 */
	public function test_an_untouched_install_still_renders_the_my_team_card(): void {
		$html = $this->render( static fn() => blueline_account_render_my_team( 66 ) );

		$this->assertNotSame( '', trim( $html ) );
		$this->assertStringContainsString( 'bl-account-module--my-team', $html );
	}

	/**
	 * The claim card and its post-claim notice are deliberately NOT
	 * toggleable: each is the only route an unlinked player has to become
	 * linked ("link a player profile"), so hiding either would strand that
	 * player with no way forward. blueline_section_definitions() must carry
	 * no entry for it.
	 */
	public function test_the_claim_card_has_no_toggle(): void {
		$this->assertArrayNotHasKey( 'account_claim_card', blueline_section_definitions() );
	}

	/**
	 * Same guarantee for the claim notice -- there is no plausible key name
	 * for it either, since it shares the claim card's "no toggle, ever"
	 * status rather than having simply been forgotten.
	 */
	public function test_the_claim_notice_has_no_toggle(): void {
		$this->assertArrayNotHasKey( 'account_claim_notice', blueline_section_definitions() );
	}

	/**
	 * Fix round 1, Important 4: switching off `account_my_team` blanked the
	 * dedicated `/account/my-team/` page (its endpoint handler renders
	 * blueline_account_render_my_team(), which Task 3's guard now empties)
	 * while the nav still listed a "My Team" link to it -- a linked player
	 * following that link would land on an empty page. The menu item itself
	 * must disappear along with the card.
	 */
	public function test_the_my_team_menu_item_disappears_when_its_card_is_off(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_my_team' => false ) );

		$items = blueline_account_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertArrayNotHasKey(
			blueline_account_slug_query_var( 'my-team' ),
			$items,
			'the my-team endpoint must not appear in the nav once its card is switched off'
		);
	}

	/**
	 * The equivalent guarantee for `/account/my-schedule/` and
	 * `account_next_game`.
	 */
	public function test_the_my_schedule_menu_item_disappears_when_its_card_is_off(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_next_game' => false ) );

		$items = blueline_account_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertArrayNotHasKey(
			blueline_account_slug_query_var( 'my-schedule' ),
			$items,
			'the my-schedule endpoint must not appear in the nav once its card is switched off'
		);
	}

	/**
	 * Companion accept path: with both toggles left on (the default), both
	 * endpoints still appear in the nav -- proves the new exclusion didn't
	 * also silently drop them for every untouched install.
	 */
	public function test_the_my_team_and_my_schedule_menu_items_still_render_when_enabled(): void {
		$items = blueline_account_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertArrayHasKey( blueline_account_slug_query_var( 'my-team' ), $items );
		$this->assertArrayHasKey( blueline_account_slug_query_var( 'my-schedule' ), $items );
	}

	/**
	 * Billing-group endpoints have no toggle at all (per
	 * blueline_section_definitions()'s own "deliberately NOT here" note) and
	 * so must never be affected by either account_* toggle -- proves
	 * blueline_account_endpoint_section_keys()'s mapping is scoped to
	 * exactly the two league endpoints that have a matching card, not
	 * applied blanket to every endpoint.
	 */
	public function test_billing_group_menu_items_are_unaffected_by_either_toggle(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'account_my_team'   => false,
				'account_next_game' => false,
			)
		);

		$items = blueline_account_menu_items(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertArrayHasKey( blueline_account_slug_query_var( 'registrations' ), $items );
		$this->assertArrayHasKey( blueline_account_slug_query_var( 'edit-account' ), $items );
	}

	/**
	 * Task 8 (fix round 2): the "no upcoming game" empty-state line comes
	 * from `account_empty_next_game`, not a hardcoded literal -- a real save
	 * changes what renders, not merely what's stored.
	 */
	public function test_the_next_game_empty_state_comes_from_settings(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_next_game' => 'Nothing on the schedule for you just yet.' ) );

		$html = $this->render( static fn() => blueline_account_render_next_game( 66 ) );

		$this->assertStringContainsString( 'Nothing on the schedule for you just yet.', $html );
	}

	/**
	 * The equivalent guarantee for the season-stats hint line and
	 * `account_empty_stats` -- rendered whenever every stat is still zero
	 * (blueline_get_player_season_stats()'s own zero-filled contract for an
	 * unknown player id, per that function's docblock).
	 */
	public function test_the_season_stats_empty_state_comes_from_settings(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_stats' => 'Check back after your first game.' ) );

		$html = $this->render( static fn() => blueline_account_render_season_stats( 66 ) );

		$this->assertStringContainsString( 'Check back after your first game.', $html );
	}
}
