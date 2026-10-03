<?php
/**
 * Stubs for the seo-meta module tests.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	/**
	 * Stand-in for get_gmt_from_date(): treats the site timezone as UTC.
	 *
	 * @param string $date_string Local 'Y-m-d H:i:s' date.
	 * @return string
	 */
	function get_gmt_from_date( $date_string ) {
		return (string) $date_string;
	}
}
