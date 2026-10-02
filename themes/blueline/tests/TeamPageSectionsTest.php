<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * The theme's team-page blocks registered as orderable SportsPress sections.
 */
final class TeamPageSectionsTest extends TestCase {

	/**
	 * Test case.
	 */
	public function test_registers_calendar_and_schedule_after_existing_sections(): void {
		$existing = array(
			'link' => array(
				'title'  => 'Visit Site',
				'option' => 'sportspress_team_show_link',
			),
		);

		$templates = blueline_register_team_page_sections( $existing );

		$this->assertSame( array( 'link', 'calendar', 'schedule' ), array_keys( $templates ) );
		$this->assertSame( 'sportspress_team_show_calendar', $templates['calendar']['option'] );
		$this->assertSame( 'blueline_output_team_calendar_section', $templates['calendar']['action'] );
		$this->assertSame( 'sportspress_team_show_schedule', $templates['schedule']['option'] );
		$this->assertSame( 'blueline_output_team_schedule_section', $templates['schedule']['action'] );
		$this->assertSame( 'yes', $templates['calendar']['default'] );
		$this->assertSame( 'yes', $templates['schedule']['default'] );
		$this->assertTrue( function_exists( $templates['calendar']['action'] ) );
		$this->assertTrue( function_exists( $templates['schedule']['action'] ) );
	}
}
