<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * The homepage standings module's division tab strip: a named radio group
 * whose radios are named "Division N", while the visible chips stay short.
 */
final class HomepageStandingsTabsTest extends TestCase {

	/**
	 * "Division N" tables get a short visible chip and the full name for
	 * assistive tech; other conventions keep their own name for both.
	 */
	public function test_tab_names(): void {
		$this->assertSame(
			array(
				'short' => 'Div 3',
				'full'  => 'Division 3',
			),
			blueline_homepage_standings_tab_names( 'Division 3 | W2026-27' )
		);
		$this->assertSame(
			array(
				'short' => 'Div 4',
				'full'  => 'Division 4',
			),
			blueline_homepage_standings_tab_names( 'Division 4 | Playoffs S2026' )
		);
		$this->assertSame(
			array(
				'short' => 'Group A',
				'full'  => 'Group A',
			),
			blueline_homepage_standings_tab_names( 'Group A | W2019-20' )
		);
		$this->assertSame(
			array(
				'short' => 'Open Division',
				'full'  => 'Open Division',
			),
			blueline_homepage_standings_tab_names( 'Open Division | S2019' )
		);
	}

	/**
	 * The radios sit in a fieldset whose legend names the group, each
	 * radio's label reads "Division N" to assistive tech and "Div N" on
	 * screen, and the panels stay outside the group.
	 */
	public function test_tab_strip_is_a_named_group_of_named_radios(): void {
		ob_start();
		blueline_homepage_standings_tabs(
			array(
				array(
					'id'    => 11,
					'short' => 'Div 1',
					'full'  => 'Division 1',
					'html'  => '<table id="panel-one"></table>',
				),
				array(
					'id'    => 12,
					'short' => 'Group B',
					'full'  => 'Group B',
					'html'  => '<table id="panel-two"></table>',
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<fieldset class="bl-standings-tabs__group">\s*<legend class="screen-reader-text">Choose a division<\/legend>/', $html );
		$this->assertMatchesRegularExpression( '/<fieldset\b.*id="bl-standings-tab-11".*id="bl-standings-tab-12".*<\/fieldset>/s', $html );
		$this->assertMatchesRegularExpression( '/<\/fieldset>\s*<div class="bl-standings-tabs__panel">\s*<table id="panel-one">/', $html );
		$this->assertStringNotContainsString( 'aria-describedby', $html );

		$this->assertStringContainsString( '<span aria-hidden="true">Div 1</span><span class="screen-reader-text">Division 1</span>', $html );
		$this->assertMatchesRegularExpression( '/<label class="bl-standings-tabs__label" for="bl-standings-tab-12">\s*<span class="bl-skew"><span>Group B<\/span><\/span>/', $html );
	}
}
