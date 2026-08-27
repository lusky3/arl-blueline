<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/preferences.php';

/**
 * Covers the pure "what team summary to show" decision behind the
 * Preferences page's linked-team section.
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
}
