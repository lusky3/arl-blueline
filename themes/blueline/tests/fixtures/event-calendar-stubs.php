<?php
/**
 * A real get_gmt_from_date() for EventCalendarDstTest.
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests: player-schedule-stubs.php
 * defines a fixed-answer get_gmt_from_date() for its own tests, and a PHP
 * function cannot be defined twice in one process.
 *
 * @package blueline
 */

/**
 * Core's get_gmt_from_date(), minus its legacy gmt_offset fallback: reads
 * the site-local date in the site's zone (wp_timezone(), America/Toronto in
 * this suite) and formats it in UTC. Toronto observes daylight saving, which
 * is what lets a test put games on both sides of a clock change.
 *
 * @param string $date_string Site-local date.
 * @param string $format      Output format.
 * @return string|false UTC date, or false when $date_string does not parse.
 */
function get_gmt_from_date( $date_string, $format = 'Y-m-d H:i:s' ) {
	$datetime = date_create( $date_string, wp_timezone() );

	if ( false === $datetime ) {
		return false;
	}

	return $datetime->setTimezone( new DateTimeZone( 'UTC' ) )->format( $format );
}
