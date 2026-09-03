<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/preferences.php';

/**
 * Covers the pure decisions behind the Preferences page: the linked-team
 * summary, and the "Show next game widget again" confirmation copy.
 */
final class AccountPreferencesTest extends TestCase {

	/**
	 * A null team (unclaimed, or claimed but currently rosterless) must
	 * produce a null summary, not throw or fabricate placeholder data.
	 */
	public function test_null_team_produces_null_summary(): void {
		$this->assertNull( blueline_preferences_team_summary( null ) );
	}

	/**
	 * A real team array passes through as a formatted summary line.
	 */
	public function test_real_team_produces_a_summary_line(): void {
		$summary = blueline_preferences_team_summary(
			array(
				'team_id'  => 42,
				'name'     => 'Puck Dynasty',
				'logo_id'  => null,
				'division' => 'Division 1',
				'number'   => '99',
			)
		);

		$this->assertSame( 'Puck Dynasty · Division 1 · #99', $summary );
	}

	/**
	 * A team with no division or number omits those segments rather than
	 * rendering an empty "· ·".
	 */
	public function test_summary_omits_missing_segments(): void {
		$summary = blueline_preferences_team_summary(
			array(
				'team_id'  => 42,
				'name'     => 'Puck Dynasty',
				'logo_id'  => null,
				'division' => '',
				'number'   => null,
			)
		);

		$this->assertSame( 'Puck Dynasty', $summary );
	}

	/**
	 * With an upcoming game, the confirmation can honestly promise the
	 * widget will show again on the next page view.
	 */
	public function test_confirmation_promises_next_page_view_when_a_game_is_upcoming(): void {
		$this->assertSame(
			'Done — it will show again on your next page view.',
			blueline_preferences_widget_confirmation_text( true )
		);
	}

	/**
	 * With no upcoming game, the confirmation must NOT promise the widget
	 * will show on the next page view -- it won't, since
	 * blueline_render_floating_next_game() renders nothing without one --
	 * and instead explains why nothing will visibly change yet.
	 */
	public function test_confirmation_explains_no_upcoming_game_instead_of_lying(): void {
		$text = blueline_preferences_widget_confirmation_text( false );

		$this->assertStringNotContainsString( 'next page view', $text );
		$this->assertStringContainsString( 'don’t have an upcoming game', $text );
	}
}
