<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * C-12/C-21/B-23: stock SportsPress templates print `<h4 class="sp-table-caption">`
 * straight under the page's h1 (event Details/Results, player lists, Fixtures).
 */
final class SportspressCaptionRetagTest extends TestCase {

	/**
	 * Runs before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Stock h4 captions take the contextual level; other headings are untouched.
	 */
	public function test_h4_captions_are_retagged(): void {
		$html = '<h4 class="sp-table-caption">Details</h4><table></table>'
			. '<h4 class="sp-table-caption">Results</h4>'
			. '<h2 class="sp-table-caption">Upcoming Games</h2>'
			. '<h4 class="sp-table-caption bl-sp-team-list__group">Goalies</h4>'
			. '<h4>Author heading</h4>';

		$out = blueline_sp_retag_captions( $html, 2 );

		$this->assertStringContainsString( '<h2 class="sp-table-caption">Details</h2>', $out );
		$this->assertStringContainsString( '<h2 class="sp-table-caption">Results</h2>', $out );
		$this->assertStringContainsString( '<h2 class="sp-table-caption">Upcoming Games</h2>', $out );
		$this->assertStringContainsString( '<h4 class="sp-table-caption bl-sp-team-list__group">Goalies</h4>', $out );
		$this->assertStringContainsString( '<h4>Author heading</h4>', $out );
		$this->assertStringNotContainsString( '<h4 class="sp-table-caption">', $out );
	}

	/**
	 * Event-block titles and scores (Past Meetings, Fixtures) follow one and two levels below.
	 */
	public function test_event_block_headings_follow_the_caption(): void {
		$html = '<h4 class="sp-table-caption">Fixtures</h4><h4 class="sp-event-title" itemprop="name">A vs B</h4><h5 class="sp-event-results">2 - 4</h5>';

		$this->assertSame(
			'<h2 class="sp-table-caption">Fixtures</h2><h3 class="sp-event-title" itemprop="name">A vs B</h3><h4 class="sp-event-results">2 - 4</h4>',
			blueline_sp_retag_captions( $html, 2 )
		);
		$this->assertStringContainsString( '<h5 class="sp-event-title">', blueline_sp_retag_captions( '<h4 class="sp-table-caption">X</h4><h5 class="sp-event-title">Y</h5>', 3 ) );
	}

	/**
	 * A caption repeating the page title is removed, whatever its level or entities.
	 */
	public function test_caption_equal_to_the_page_title_is_removed(): void {
		$html = '<h2 class="sp-table-caption">Soy Saucers &#124; ARL</h2><table></table>'
			. '<h4 class="sp-table-caption">Points  Leaders</h4>';

		$out = blueline_sp_retag_captions( $html, 2, 'Soy Saucers | ARL' );

		$this->assertStringNotContainsString( 'Soy Saucers', $out );
		$this->assertStringContainsString( '<h2 class="sp-table-caption">Points  Leaders</h2>', $out );
		$this->assertSame( '<table></table>', blueline_sp_retag_captions( '<h4 class="sp-table-caption">points leaders</h4><table></table>', 3, 'Points Leaders' ) );
	}

	/**
	 * The filter uses the singular-page level (2) and only drops the duplicate on table/calendar/list singulars.
	 */
	public function test_content_filter_context(): void {
		$state                      = &blueline_test_state();
		$state['posts'][7]['title'] = 'Division 1';
		$state['queried_object_id'] = 7;
		blueline_test_set_queried_post_type( 'sp_event' );

		$html = '<h4 class="sp-table-caption">Division 1</h4>';
		$this->assertSame( '<h2 class="sp-table-caption">Division 1</h2>', blueline_sp_content_caption_levels( $html ) );

		blueline_test_set_queried_post_type( 'sp_table' );
		$this->assertSame( '', blueline_sp_content_caption_levels( $html ) );

		$this->assertSame( '<p>no captions</p>', blueline_sp_content_caption_levels( '<p>no captions</p>' ) );
	}
}
