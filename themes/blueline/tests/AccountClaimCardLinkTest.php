<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/account/dashboard.php';

/**
 * Live-site review fixes covered here:
 *
 * 1. The claim card's "no candidates" message used to leave "Contact the
 *    league" as plain, unclickable text -- a dead end for exactly the
 *    player who most needs to reach the league. It must now be a real
 *    link to blueline_contact_url().
 * 2. /account/my-team and /account/my-schedule used to show the exact
 *    same claim card, with no hint of what each page will actually show
 *    once the account is linked. blueline_account_render_claim_card()'s
 *    new $context parameter adds that hint.
 *
 * blueline_find_player_candidates() is never required here, so
 * post_type_exists( 'sp_player' ) is false and blueline_find_player_candidates()
 * (wherever else in the suite it may be defined) short-circuits to an empty
 * array -- deterministically exercising the "no candidates" branch every
 * test in this file needs, the same way it would for a real site with
 * SportsPress inactive.
 */
final class AccountClaimCardLinkTest extends TestCase {

	/**
	 * Reset every in-memory store this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Render the claim card and capture its output.
	 *
	 * @param string $context blueline_account_render_claim_card()'s context arg.
	 * @return string
	 */
	private function render_claim_card( string $context = 'dashboard' ): string {
		ob_start();
		blueline_account_render_claim_card( 1, $context );
		return (string) ob_get_clean();
	}

	/**
	 * The load-bearing case: "Contact the league" is now a real <a href>,
	 * pointing at the same URL blueline_contact_url() resolves to.
	 */
	public function test_contact_the_league_is_a_real_link(): void {
		$html = $this->render_claim_card();

		$this->assertStringContainsString(
			'<a href="' . esc_url( blueline_contact_url() ) . '">Contact the league</a>',
			$html
		);
	}

	/**
	 * Proves the fix isn't vacuous: the old plain-text phrasing (no tag
	 * around "Contact the league") must be gone, not merely joined by a
	 * link somewhere else on the card.
	 */
	public function test_contact_the_league_is_no_longer_plain_text(): void {
		$html = $this->render_claim_card();

		$this->assertStringNotContainsString( 'yet. Contact the league and', $html );
	}

	/**
	 * The rest of the sentence must survive the change to a link -- this
	 * is a markup fix, not a copy rewrite.
	 */
	public function test_surrounding_copy_is_unchanged(): void {
		$html = $this->render_claim_card();

		$this->assertStringContainsString(
			'We couldn’t find a player profile that matches your account yet.',
			$html
		);
		$this->assertStringContainsString( 'and we’ll get you linked up.', $html );
	}

	/**
	 * The default (dashboard) context adds no extra framing -- the
	 * next-game/team/season modules it replaces already say what will
	 * appear once linked.
	 */
	public function test_dashboard_context_has_no_added_hint(): void {
		$html = $this->render_claim_card( 'dashboard' );

		$this->assertStringNotContainsString( 'will appear here', $html );
	}

	/**
	 * Landing directly on /account/my-team while unlinked now says what
	 * will appear there once linked.
	 */
	public function test_my_team_context_adds_its_own_hint(): void {
		$html = $this->render_claim_card( 'my-team' );

		$this->assertStringContainsString( 'Once you’re linked, your team will appear here.', $html );
	}

	/**
	 * Same fix, /account/my-schedule's own wording.
	 */
	public function test_my_schedule_context_adds_its_own_hint(): void {
		$html = $this->render_claim_card( 'my-schedule' );

		$this->assertStringContainsString( 'Once you’re linked, your schedule will appear here.', $html );
	}

	/**
	 * An unrecognised context degrades to the dashboard's own (no hint)
	 * behaviour rather than fataling or printing a blank hint.
	 */
	public function test_unknown_context_degrades_to_no_hint(): void {
		$html = $this->render_claim_card( 'something-else' );

		$this->assertStringNotContainsString( 'will appear here', $html );
	}
}
