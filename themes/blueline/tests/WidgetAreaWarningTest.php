<?php
/**
 * Covers Task 5 of the P1b-panel-completion plan:
 * blueline_section_widget_warning() -- the message that stops an admin
 * switching a widget-bearing section off and assuming their widgets were
 * deleted. Switching a section off only hides it (blueline_section_enabled());
 * it never touches the widget store, and this warning names the live widget
 * count so an admin doesn't rebuild something that's still there.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/sections.php';

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
	 * A populated widget area (`footer-2`, behind `chrome_footer_trust`)
	 * warns with its own live count.
	 */
	public function test_a_populated_widget_area_warns_with_its_count(): void {
		$state                                = &blueline_test_state();
		$state['active_sidebars']['footer-2'] = 3;

		$warning = blueline_section_widget_warning( 'chrome_footer_trust' );

		$this->assertStringContainsString( '3', $warning );
	}

	/**
	 * An empty widget area -- no test seeded it, matching the default,
	 * always-inactive state -- produces no warning at all.
	 */
	public function test_an_empty_widget_area_produces_no_warning(): void {
		$this->assertSame( '', blueline_section_widget_warning( 'chrome_footer_trust' ) );
	}

	/**
	 * A section key with no widget-area mapping at all (every key besides
	 * `chrome_footer_trust` today) never warns, even if a test seeds an
	 * active sidebar under some other id entirely -- there is nothing to
	 * invent a mapping for.
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
