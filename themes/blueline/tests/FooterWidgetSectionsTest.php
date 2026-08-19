<?php
/**
 * Covers the Task 5 fix round of the P1b-panel-completion plan: the four
 * `chrome_footer_widgets_N` section toggles, each gating its own real
 * `footer-N` WordPress widget area (registered in inc/setup.php's
 * blueline_widgets_init()).
 *
 * These did not exist when Task 1-4 built the Sections tab: the plan's own
 * spec named "4 footer widget areas" among the presence toggles, and no
 * earlier task built them. A first attempt at Task 5's widget-store warning
 * mapped `chrome_footer_trust` to `footer-2` instead -- caught as false by
 * this plan's coordinator, since `chrome_footer_trust` gates only the
 * separate, hardcoded trust column (contact/location/FAQs/legal), never
 * footer-2's own widget loop (inc/template-tags.php:643-649 at the time,
 * gated solely by is_active_sidebar()). This file proves the real fix:
 * each `chrome_footer_widgets_N` key genuinely withholds its own column.
 *
 * Assertions below check for the exact `<div class="bl-footer__column">`
 * opening tag, not merely the `bl-footer__column` substring -- the
 * permanent trust column's own class is `bl-footer__column bl-footer__column--trust`,
 * which contains that substring too, so a looser check would false-pass
 * "no widget columns rendered" while the trust column (on by default) was
 * still sitting right there.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/sportspress.php';
require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers the four `chrome_footer_widgets_N` toggles' effect on
 * blueline_site_footer()'s rendered widget columns.
 */
final class FooterWidgetSectionsTest extends TestCase {

	/** The exact plain widget-column opening tag (never matches the trust column's own, modified class). */
	private const PLAIN_COLUMN_TAG = '<div class="bl-footer__column">';

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * Render blueline_site_footer() and capture its output.
	 *
	 * @return string
	 */
	private function render_footer(): string {
		ob_start();
		blueline_site_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Unset means enabled (blueline_section_enabled()'s own contract): with
	 * footer-2 genuinely populated and its toggle left untouched, the column
	 * still renders -- an install that has never opened the Sections tab
	 * must keep seeing exactly the widgets it already configured.
	 */
	public function test_a_populated_footer_area_still_renders_when_its_toggle_is_left_on(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 1;

		$html = $this->render_footer();

		$this->assertStringContainsString( self::PLAIN_COLUMN_TAG, $html );
	}

	/**
	 * The regression this file exists to guard: switching
	 * `chrome_footer_widgets_2` off hides its column even though footer-2
	 * genuinely has a widget assigned (is_active_sidebar( 'footer-2' ) is
	 * true) -- proving the new toggle is a REAL gate, not decorative.
	 */
	public function test_disabling_a_footer_widgets_toggle_hides_its_populated_column(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_footer_widgets_2' => false ) );
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 1;

		$html = $this->render_footer();

		$this->assertStringNotContainsString( self::PLAIN_COLUMN_TAG, $html );
	}

	/**
	 * Each toggle only gates its own area: switching `chrome_footer_widgets_1`
	 * off must not also hide footer-2's column.
	 */
	public function test_disabling_one_footer_widgets_toggle_does_not_hide_another_areas_column(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'chrome_footer_widgets_1' => false ) );
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-1'] = 1;
		$state['active_sidebars']['footer-2'] = 1;

		$html = $this->render_footer();

		// footer-1's column is suppressed; footer-2's still prints -- checked
		// by count, since both render the identical plain-column tag.
		$this->assertSame( 1, substr_count( $html, self::PLAIN_COLUMN_TAG ), 'expected exactly one plain widget column (footer-2\'s); footer-1\'s must be suppressed' );
	}

	/**
	 * No WCAG-style floor here, unlike the homepage modules: an admin
	 * turning off all four footer widget areas is a legitimate choice, even
	 * with all four genuinely populated. blueline_site_footer() must not
	 * invent one to keep at least one column visible.
	 */
	public function test_disabling_all_four_footer_widget_toggles_leaves_no_widget_columns_even_if_all_are_populated(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'chrome_footer_widgets_1' => false,
				'chrome_footer_widgets_2' => false,
				'chrome_footer_widgets_3' => false,
				'chrome_footer_widgets_4' => false,
			)
		);
		$state = &blueline_test_state();
		for ( $i = 1; $i <= 4; $i++ ) {
			$state['active_sidebars'][ 'footer-' . $i ] = 1;
		}

		$html = $this->render_footer();

		$this->assertStringNotContainsString( self::PLAIN_COLUMN_TAG, $html, 'no floor: an admin may legitimately switch off every footer widget area' );
	}

	/**
	 * The pre-existing `is_active_sidebar()` check is untouched: with every
	 * toggle left on but every area genuinely empty (the default, untouched
	 * state), no widget column renders -- the new section gate is an
	 * ADDITIONAL AND condition, not a replacement for the existing one.
	 */
	public function test_an_enabled_but_empty_footer_area_still_renders_nothing(): void {
		$html = $this->render_footer();

		$this->assertStringNotContainsString( self::PLAIN_COLUMN_TAG, $html );
	}
}
