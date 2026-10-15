<?php
/**
 * Stand-in for the SportsPress Sponsors helper sportspress/sponsors-content.php calls.
 *
 * @package blueline
 */

if ( ! function_exists( 'sp_set_post_impressions' ) ) {
	/**
	 * Records which sponsors were shown, in place of SportsPress's impression counter.
	 *
	 * @param int $post_id Sponsor post ID.
	 * @return void
	 */
	function sp_set_post_impressions( $post_id ) {
		$GLOBALS['bl_test_sponsor_impressions'][] = (int) $post_id;
	}
}
