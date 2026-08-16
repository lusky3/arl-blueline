<?php
/**
 * Covers Task 5 of the P1b-panel-completion plan:
 * blueline_section_widget_warning() -- the message that stops an admin
 * switching a widget-bearing section off and assuming their widgets were
 * deleted. Switching a section off only hides it (blueline_section_enabled());
 * it never touches the widget store, and this warning names the live widget
 * count so an admin doesn't rebuild something that's still there.
 *
 * Fix round: the first version of this warning mapped `chrome_footer_trust`
 * to `footer-2`, which the plan's coordinator caught as false --
 * `chrome_footer_trust` gates only a separate, hardcoded trust column, never
 * footer-2's own widget loop. The real fix built the four missing
 * `chrome_footer_widgets_N` sections (each genuinely gating its own
 * `footer-N`, per inc/template-tags.php's blueline_site_footer()), so this
 * file now targets those instead.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/setup.php'; // blueline_active_widget_count(), moved here since it owns the widget store, not sections.

/**
 * Covers blueline_section_widget_warning() and blueline_active_widget_count().
 */
final class WidgetAreaWarningTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * A populated widget area (`footer-2`, behind `chrome_footer_widgets_2`)
	 * warns with its own live count.
	 */
	public function test_a_populated_widget_area_warns_with_its_count(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 3;

		$warning = blueline_section_widget_warning( 'chrome_footer_widgets_2' );

		$this->assertStringContainsString( '3', $warning );
	}

	/**
	 * An empty widget area -- no test seeded it, matching the default,
	 * always-inactive state -- produces no warning at all.
	 */
	public function test_an_empty_widget_area_produces_no_warning(): void {
		$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_widgets_2' ) );
	}

	/**
	 * Each of the other three `chrome_footer_widgets_N` keys maps to its own
	 * area independently -- seeding `footer-1` warns behind
	 * `chrome_footer_widgets_1` but not behind `chrome_footer_widgets_3` or
	 * `_4`, proving the mapping is per-key, not "any footer widgets toggle
	 * warns about any populated footer area".
	 */
	public function test_each_footer_widgets_key_only_warns_for_its_own_area(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-1'] = 2;

		$this->assertStringContainsString( '2', blueline_section_widget_warning( 'chrome_footer_widgets_1' ) );
		$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_widgets_3' ) );
		$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_widgets_4' ) );
	}

	/**
	 * `chrome_footer_trust` gates a separate, hardcoded footer column, not
	 * any widget area -- it must never warn, even with footer-2 populated.
	 * This is the exact mistake a fix round of this plan made once already:
	 * mapping this key to footer-2's count when nothing about this section's
	 * own toggle actually hides that area.
	 */
	public function test_chrome_footer_trust_never_warns_even_with_footer_2_populated(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 3;

		$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_trust' ) );
	}

	/**
	 * A section key with no widget-area mapping at all (e.g. `chrome_sponsors`)
	 * never warns, even if a test seeds an active sidebar under some other id
	 * entirely -- there is nothing to invent a mapping for.
	 */
	public function test_a_section_with_no_widget_area_mapping_never_warns(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 3;

		$this->assertSame( '', blueline_section_widget_warning( 'chrome_sponsors' ) );
	}

	/**
	 * Reads the real widget count for a given area straight from
	 * wp_get_sidebars_widgets(), independent of the warning message's own
	 * wording.
	 */
	public function test_active_widget_count_reads_the_real_sidebar_widget_count(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 5;

		$this->assertSame( 5, blueline_active_widget_count( 'footer-2' ) );
	}

	/**
	 * An area with no widgets at all (never registered, or registered but
	 * empty) reports zero, not an error.
	 */
	public function test_active_widget_count_is_zero_for_an_unpopulated_area(): void {
		$this->assertSame( 0, blueline_active_widget_count( 'footer-2' ) );
	}
}
