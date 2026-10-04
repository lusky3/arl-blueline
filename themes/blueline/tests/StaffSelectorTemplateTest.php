<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * B-01: staff pages shipped SportsPress's stock selector -- no label, browser
 * default styling, and navigation on `change`. The override must mirror the
 * player selector's accessible shape (source-level: rendering needs
 * SportsPress functions the stub suite does not define).
 */
final class StaffSelectorTemplateTest extends TestCase {

	/**
	 * Template source.
	 *
	 * @return string
	 */
	private function src(): string {
		return (string) file_get_contents( __DIR__ . '/../sportspress/staff-selector.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
	}

	/**
	 * A visible label is tied to the select by id.
	 */
	public function test_label_is_associated_with_the_select(): void {
		$src = $this->src();

		$this->assertMatchesRegularExpression( '/<label class="bl-sp-player-selector__label" for="<\?php echo esc_attr\( \$select_id \); \?>">/', $src );
		$this->assertStringContainsString( '<select id="<?php echo esc_attr( $select_id ); ?>"', $src );
	}

	/**
	 * No change-to-navigate class; an explicit Go button the shared script handles.
	 */
	public function test_navigation_needs_the_go_button(): void {
		$src = $this->src();

		$this->assertStringNotContainsString( 'sp-selector-redirect', $src );
		$this->assertStringContainsString( 'bl-sp-player-selector__go', $src );
		$this->assertStringContainsString( 'bl-sp-player-selector__row', $src );

		$js = (string) file_get_contents( __DIR__ . '/../assets/src/js/player-selector.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.
		$this->assertStringContainsString( "row.querySelector( 'select' )", $js );
	}

	/**
	 * Option values and names are escaped (the stock template echoed them raw).
	 */
	public function test_options_are_escaped(): void {
		$src = $this->src();

		$this->assertStringContainsString( 'esc_url( get_post_permalink( $staff->ID ) )', $src );
		$this->assertStringContainsString( 'esc_html( $staff->post_title )', $src );
	}
}
