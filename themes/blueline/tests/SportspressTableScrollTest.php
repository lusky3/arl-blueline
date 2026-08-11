<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Regression coverage for blueline_sp_wrap_tables_for_scroll() and its
 * DOMDocument-based helpers -- the mechanism behind the "page body must
 * never scroll horizontally" hard invariant.
 *
 * This exists specifically because the throwaway manual script used to
 * verify this logic during Task 8's first fix round was never committed,
 * and this exact logic went on to produce a real regression in the very
 * next review round (the DOM pass touching ordinary, non-SportsPress
 * tables site-wide). The scoping fix from that regression -- only ever
 * touching a <table> that carries an "sp-" prefixed class somewhere in its
 * own ancestry -- is exactly what several of these cases exist to pin
 * down permanently.
 */
final class SportspressTableScrollTest extends TestCase {

	public function test_content_with_no_table_is_untouched(): void {
		$in = '<p>no tables here</p>';
		$this->assertSame( $in, blueline_sp_wrap_tables_for_scroll( $in ) );
	}

	public function test_wrapped_sportspress_table_gets_bl_table_scroll(): void {
		$in  = '<div class="sp-table-wrapper"><table class="sp-data-table"><tr><td>x</td></tr></table></div>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringContainsString( 'class="sp-table-wrapper bl-table-scroll"', $out );
		$this->assertStringNotContainsString( 'bl-table-self-scroll', $out );
	}

	public function test_unwrapped_sportspress_table_gets_self_scroll_class_added_to_itself(): void {
		// event-venue.php's real shape: no .sp-table-wrapper around it.
		$in  = '<table class="sp-data-table sp-event-venue"><tr><td>map</td></tr></table>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringContainsString( 'bl-table-self-scroll', $out );
		$this->assertStringContainsString( 'sp-data-table', $out );
		$this->assertStringContainsString( 'sp-event-venue', $out );
		$this->assertStringNotContainsString( '<div', $out, 'the self-scroll path must not introduce a wrapping div' );
	}

	public function test_two_unwrapped_sportspress_tables_are_both_handled_independently(): void {
		$in  = '<table class="sp-a"><tr><td>1</td></tr></table><p>text</p><table class="sp-b"><tr><td>2</td></tr></table>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertSame( 2, substr_count( $out, 'bl-table-self-scroll' ) );
	}

	public function test_a_table_with_an_unrecognised_future_sp_class_is_still_caught(): void {
		// Defence in depth: a future SportsPress markup change is still
		// caught as long as it keeps the sp- prefix convention, which is
		// the actual signal this mechanism relies on, not one exact class.
		$in  = '<table class="sp-totally-new-widget-2027"><tr><td>future markup</td></tr></table>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringContainsString( 'bl-table-self-scroll', $out );
	}

	public function test_sportspress_table_inside_an_unrelated_wrapper_still_gets_self_scroll(): void {
		$in  = '<div class="some-other-widget"><table class="sp-c"><tr><td>3</td></tr></table></div>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringContainsString( 'bl-table-self-scroll', $out );
	}

	public function test_malformed_table_markup_does_not_crash(): void {
		$in  = '<table><tr><td>unclosed';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertIsString( $out );
		$this->assertNotSame( '', $out );
	}

	public function test_running_the_filter_twice_does_not_duplicate_the_class(): void {
		$in    = '<table class="sp-data-table sp-event-venue"><tr><td>map</td></tr></table>';
		$once  = blueline_sp_wrap_tables_for_scroll( $in );
		$twice = blueline_sp_wrap_tables_for_scroll( $once );

		$this->assertSame( 1, substr_count( $twice, 'bl-table-self-scroll' ) );
	}

	/**
	 * The regression this file exists to prevent from ever recurring
	 * silently: an ordinary WordPress block-editor table in a ordinary
	 * blog post or page must be left completely untouched -- no
	 * bl-table-self-scroll, no display:block, nothing.
	 */
	public function test_ordinary_block_editor_table_is_completely_untouched(): void {
		$in  = '<figure class="wp-block-table"><table><tbody><tr><td>Row</td><td>Value</td></tr></tbody></table></figure>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertSame( $in, $out, 'a non-SportsPress table must not be modified at all' );
		$this->assertStringNotContainsString( 'bl-table-self-scroll', $out );
		$this->assertStringNotContainsString( 'bl-table-scroll', $out );
	}

	public function test_block_editor_table_with_a_style_variant_is_also_untouched(): void {
		$in  = '<figure class="wp-block-table is-style-stripes"><table class="has-fixed-layout"><tbody><tr><td>A</td></tr></tbody></table></figure>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertSame( $in, $out );
	}

	/**
	 * Mixed content: a real SportsPress table (e.g. the standings page's
	 * own [team_standings] shortcode output) alongside an ordinary
	 * block-editor table in the same post body. Only the SportsPress one
	 * may be touched.
	 */
	public function test_mixed_content_only_touches_the_sportspress_table(): void {
		$in = '<p>Some intro copy.</p>'
			. '<figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure>'
			. '<div class="sp-table-wrapper"><table class="sp-league-table sp-data-table"><tr><td>1</td></tr></table></div>';

		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringContainsString( 'sp-table-wrapper bl-table-scroll', $out );
		$this->assertSame( 1, substr_count( $out, 'bl-table-scroll' ), 'exactly one scroll-related class addition, on the SP table only' );
		$this->assertStringContainsString( '<table><tbody><tr><td>Plain</td></tr></tbody></table>', $out, 'the plain table is byte-for-byte unchanged' );
	}

	/**
	 * The word "sp-" appearing somewhere in the content (e.g. in a class
	 * on an unrelated element) must not cause an ordinary table elsewhere
	 * in the same content to be touched -- the per-table ancestry check,
	 * not just the cheap whole-content pre-check, is what actually decides.
	 */
	public function test_unrelated_sp_prefixed_class_elsewhere_does_not_taint_an_unrelated_table(): void {
		$in  = '<div class="sp-something-unrelated">not a table</div><figure class="wp-block-table"><table><tbody><tr><td>Plain</td></tr></tbody></table></figure>';
		$out = blueline_sp_wrap_tables_for_scroll( $in );

		$this->assertStringNotContainsString( 'bl-table-self-scroll', $out );
		$this->assertStringNotContainsString( 'bl-table-scroll', $out );
	}
}
