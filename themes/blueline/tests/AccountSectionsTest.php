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
	 * from `account_empty_next_game`, not a hardcoded literal.
	 *
	 * This write DOES go through the full save pipeline -- the
	 * `sanitize_option_blueline_settings` filter and the cross-tab merge --
	 * so the test covers the whole path from update_option() to rendered
	 * output, not just the read.
	 *
	 * An earlier version of this docblock claimed the opposite, reasoning
	 * that "this file requires neither inc/settings/sanitize.php nor
	 * inc/settings/page.php". That reasoning is wrong, and the mistake is
	 * worth naming because it is easy to make again: PER-FILE `require_once`
	 * LISTS DO NOT SCOPE HOOK REGISTRATION. PHPUnit require_once's every
	 * test file while building the suite, before any setUp() runs, so
	 * page.php's file-scope `add_filter` has already executed by then; and
	 * blueline_test_reset_hooks() captures its baseline AFTER that, so it
	 * restores those registrations rather than clearing them
	 * (tests/bootstrap.php documents this directly). Both halves were
	 * checked by probe: a stray-`%` write here is refused, and a partial
	 * write preserves an untouched key.
	 */
	public function test_the_next_game_empty_state_comes_from_settings(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_next_game' => 'Nothing on the schedule for you just yet.' ) );

		// Premise, and the claim the docblock above now makes: the value
		// survived the sanitizer and the merge rather than being refused.
		$this->assertSame( 'Nothing on the schedule for you just yet.', blueline_settings( 'account_empty_next_game' ) );

		$html = $this->render( static fn() => blueline_account_render_next_game( 66 ) );

		$this->assertStringContainsString( 'Nothing on the schedule for you just yet.', $html );
	}

	/**
	 * The stronger claim the corrected mechanism above makes available, and
	 * which the old "this bypasses the pipeline" framing ruled out: writing
	 * ONE account copy field leaves the other alone. That is the cross-tab
	 * merge doing its job on this file's own writes, and it is the property
	 * an admin actually depends on when they edit a single field.
	 */
	public function test_writing_one_account_copy_field_leaves_the_other_intact(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_stats' => 'Kept.' ) );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_next_game' => 'Changed.' ) );

		$this->assertSame( 'Kept.', blueline_settings( 'account_empty_stats' ) );
		$this->assertSame( 'Changed.', blueline_settings( 'account_empty_next_game' ) );
	}

	/**
	 * And the sanitizer really is live on this file's writes: a stray `%`
	 * in a copy field is refused rather than stored, exactly as it would be
	 * from wp-admin. This is the probe that disproved the old docblock,
	 * kept as a test so the claim cannot rot back.
	 */
	public function test_this_files_writes_do_run_the_sanitizer(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_stats' => 'Save 50% today' ) );

		$this->assertNotSame( 'Save 50% today', blueline_settings( 'account_empty_stats' ) );
	}

	/**
	 * The equivalent guarantee for the season-stats hint line and
	 * `account_empty_stats` -- rendered whenever every stat is still zero
	 * (blueline_get_player_season_stats()'s own zero-filled contract for an
	 * unknown player id, per that function's docblock). Like its sibling
	 * above, this write runs the full sanitize/merge pipeline -- see that
	 * docblock for why the per-file require list has no bearing on which
	 * hooks are live.
	 */
	public function test_the_season_stats_empty_state_comes_from_settings(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'account_empty_stats' => 'Check back after your first game.' ) );

		$html = $this->render( static fn() => blueline_account_render_season_stats( 66 ) );

		$this->assertStringContainsString( 'Check back after your first game.', $html );
	}
}
