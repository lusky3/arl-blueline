<?php
/**
 * The SportsPress table-scroll pass on core's HTML5 parser (audit WP-17):
 * same decisions and output as the old libxml pass for every fixture that
 * pass handled, without re-serialising HTML5-only markup it used to mangle.
 *
 * Runs against WordPress core's real HTML API (tests/fixtures/wp-html-api.php).
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/wp-html-api.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Unit tests.
 */
final class SportspressTableScrollHtml5Test extends TestCase {

	/**
	 * Clear recorded HTML API notices.
	 */
	protected function setUp(): void {
		$GLOBALS['bl_test_html_api_notices'] = array();
	}

	/**
	 * No test may provoke a _doing_it_wrong() / wp_trigger_error() from core.
	 */
	protected function tearDown(): void {
		$this->assertSame( array(), $GLOBALS['bl_test_html_api_notices'] );
	}

	/**
	 * SportspressTableScrollTest's inputs, with the exact output the old
	 * libxml implementation produced for each (captured before the switch).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function legacy_fixtures(): array {
		return array(
			'no table'                     => array( '<p>no tables here</p>', '<p>no tables here</p>' ),
			'wrapped'                      => array(
				'<div class="sp-table-wrapper"><table class="sp-data-table"><tr><td>x</td></tr></table></div>',
				'<div class="sp-table-wrapper bl-table-scroll"><table class="sp-data-table"><tr><td>x</td></tr></table></div>',
			),
			'unwrapped venue'              => array(
				'<table class="sp-data-table sp-event-venue"><tr><td>map</td></tr></table>',
				'<table class="sp-data-table sp-event-venue bl-table-self-scroll"><tr><td>map</td></tr></table>',
			),
			'two unwrapped'                => array(
				'<table class="sp-a"><tr><td>1</td></tr></table><p>text</p><table class="sp-b"><tr><td>2</td></tr></table>',
				'<table class="sp-a bl-table-self-scroll"><tr><td>1</td></tr></table><p>text</p><table class="sp-b bl-table-self-scroll"><tr><td>2</td></tr></table>',
			),
			'future sp class'              => array(
				'<table class="sp-totally-new-widget-2027"><tr><td>future markup</td></tr></table>',
				'<table class="sp-totally-new-widget-2027 bl-table-self-scroll"><tr><td>future markup</td></tr></table>',
			),
			'unrelated wrapper'            => array(
				'<div class="some-other-widget"><table class="sp-c"><tr><td>3</td></tr></table></div>',
				'<div class="some-other-widget"><table class="sp-c bl-table-self-scroll"><tr><td>3</td></tr></table></div>',
			),
			'malformed'                    => array( '<table><tr><td>unclosed', '<table><tr><td>unclosed' ),
			'block table'                  => array(
				'<figure class="wp-block-table"><table><tbody><tr><td>Row</td><td>Value</td></tr></tbody></table></figure>',
				'<figure class="wp-block-table"><table><tbody><tr><td>Row</td><td>Value</td></tr></tbody></table></figure>',
			),
			'block table style variant'    => array(
				'<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><tbody><tr><td>A</td></tr></tbody></table></figure>',
				'<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><tbody><tr><td>A</td></tr></tbody></table></figure>',
			),
			'mixed'                        => array(
				'<p>Some intro copy.</p><figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure><div class="sp-table-wrapper"><table class="sp-league-table sp-data-table"><tr><td>1</td></tr></table></div>',
				'<p>Some intro copy.</p><figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure><div class="sp-table-wrapper bl-table-scroll"><table class="sp-league-table sp-data-table"><tr><td>1</td></tr></table></div>',
			),
			'unrelated sp class elsewhere' => array(
				'<div class="sp-something-unrelated">not a table</div><figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure>',
				'<div class="sp-something-unrelated">not a table</div><figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure>',
			),
		);
	}

	/**
	 * Output is byte-identical to the old implementation's.
	 *
	 * @param string $in       Content.
	 * @param string $expected Old implementation's output.
	 */
	#[DataProvider( 'legacy_fixtures' )]
	public function test_output_matches_the_libxml_implementation( string $in, string $expected ): void {
		$this->assertSame( $expected, blueline_sp_wrap_tables_for_scroll( $in ) );
	}

	/**
	 * Those fixtures go through the HTML5 pass, not the libxml fallback.
	 */
	public function test_ordinary_content_uses_the_html5_pass(): void {
		$this->assertSame(
			'<table class="sp-a bl-table-self-scroll"><tr><td>1</td></tr></table>',
			blueline_sp_tables_scroll_html5( '<table class="sp-a"><tr><td>1</td></tr></table>' )
		);
	}

	/**
	 * HTML5-only markup around a SportsPress table: only the table's class
	 * attribute changes; everything else comes back byte-for-byte.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function html5_markup(): array {
		return array(
			'inline svg'          => array( '<svg viewBox="0 0 10 10"><foreignObject><div class="x">a</div></foreignObject><linearGradient gradientUnits="userSpaceOnUse"/><path d="M0 0h10"/></svg>' ),
			'template'            => array( '<template id="row"><tr><td>slot</td></tr></template>' ),
			'custom element'      => array( '<rink-map data-zoom="3" aria-label="Map"><span slot="title">Rink A</span></rink-map>' ),
			'entities in script'  => array( '<script>if (a < b && c > d) { label = "&amp; &lt;b&gt;"; }</script>' ),
			'named entities'      => array( '<p>Caf&eacute; &nbsp;&copy; &#x1F3D2; &notit;</p>' ),
			'unquoted attributes' => array( '<p data-x=1 hidden>bare</p>' ),
		);
	}

	/**
	 * HTML5-only markup is not mangled, and the table still gets the class.
	 *
	 * @param string $markup Markup placed before the table.
	 */
	#[DataProvider( 'html5_markup' )]
	public function test_html5_markup_is_not_mangled( string $markup ): void {
		$table = '<table class="sp-data-table"><tr><td>1</td></tr></table>';

		$this->assertSame(
			$markup . '<table class="sp-data-table bl-table-self-scroll"><tr><td>1</td></tr></table>',
			blueline_sp_wrap_tables_for_scroll( $markup . $table )
		);
	}

	/**
	 * An sp- class on any ancestor makes a classless table SportsPress's.
	 */
	public function test_sp_class_on_an_ancestor_counts(): void {
		$this->assertSame(
			'<div class="sp-template sp-template-event-venue"><section><table class="bl-table-self-scroll"><tr><td>1</td></tr></table></section></div>',
			blueline_sp_wrap_tables_for_scroll( '<div class="sp-template sp-template-event-venue"><section><table><tr><td>1</td></tr></table></section></div>' )
		);
	}

	/**
	 * A nested SportsPress table inside one that just got the class is left
	 * alone (its ancestor scrolls), exactly like the DOM pass.
	 */
	public function test_nested_table_inside_a_self_scrolling_table_is_left_alone(): void {
		$this->assertSame(
			'<table class="sp-outer bl-table-self-scroll"><tr><td><table class="sp-inner"><tr><td>n</td></tr></table></td></tr></table>',
			blueline_sp_wrap_tables_for_scroll( '<table class="sp-outer"><tr><td><table class="sp-inner"><tr><td>n</td></tr></table></td></tr></table>' )
		);
	}

	/**
	 * A closed scroll wrapper does not shelter a later sibling table.
	 */
	public function test_a_closed_scroll_wrapper_does_not_cover_a_later_table(): void {
		$this->assertSame(
			'<div class="bl-table-scroll"><p>x</p></div><table class="sp-a bl-table-self-scroll"><tr><td>1</td></tr></table>',
			blueline_sp_wrap_tables_for_scroll( '<div class="bl-table-scroll"><p>x</p></div><table class="sp-a"><tr><td>1</td></tr></table>' )
		);
	}

	/**
	 * Markup the HTML5 parser refuses (text foster-parented out of a table)
	 * falls back to the old libxml pass, so the table is still handled.
	 */
	public function test_unparseable_markup_falls_back_to_the_libxml_pass(): void {
		$in = '<table class="sp-x">stray text<tr><td>1</td></tr></table>';

		$this->assertNull( blueline_sp_tables_scroll_html5( $in ) );
		$this->assertStringContainsString( 'class="sp-x bl-table-self-scroll"', blueline_sp_wrap_tables_for_scroll( $in ) );
	}

	/**
	 * A table that already carries the class is returned unchanged.
	 */
	public function test_already_self_scrolling_table_is_unchanged(): void {
		$in = '<table class="sp-x bl-table-self-scroll"><tr><td>1</td></tr></table>';

		$this->assertSame( $in, blueline_sp_wrap_tables_for_scroll( $in ) );
	}
}
