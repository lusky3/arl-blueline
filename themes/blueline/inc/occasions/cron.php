<?php
/**
 * Occasions: the single WP-Cron event that purges the page cache at the
 * next window boundary, its scheduling hooks and its theme-switch cleanup.
 *
 * Loaded by inc/occasions.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The WP-Cron hook name for the boundary purge. A single event is ever
 * scheduled against this hook at a time (design spec §5/§7.8: "a single
 * WP-Cron event").
 */
const BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK = 'blueline_occasion_boundary_purge';

/**
 * The next annual occurrence of calendar date $md (00:00:00, site
 * timezone) strictly after $after.
 *
 * A `02-29` $md in a target year that is not itself a leap year rolls
 * forward to March 1st, which is PHP's own DateTimeImmutable date-parsing
 * behaviour for an out-of-range day, accepted here rather than worked
 * around: none of the four shipped presets (blueline_occasion_presets())
 * uses `02-29`, and this is a purge-TIMING calculation only (design spec
 * §5/§7.8), so a purge landing a day off in a leap-adjacent year for a
 * hypothetical future `02-29` occasion delays cache visibility by at
 * most a day, never producing a wrong resolved result (the resolver
 * is unaffected by this function entirely).
 *
 * @param string $md    'MM-DD'.
 * @param int    $after Unix timestamp; the returned timestamp is always
 *                       strictly greater than this.
 * @return int Unix timestamp.
 */
function blueline_occasion_next_occurrence_timestamp( string $md, int $after ): int {
	$tz   = wp_timezone();
	$year = (int) ( new DateTimeImmutable( '@' . $after ) )->setTimezone( $tz )->format( 'Y' );

	list( $month, $day ) = array_map( 'intval', explode( '-', $md ) );

	$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ), $tz );

	if ( $candidate->getTimestamp() <= $after ) {
		$candidate = new DateTimeImmutable( sprintf( '%04d-%02d-%02d 00:00:00', $year + 1, $month, $day ), $tz );
	}

	return $candidate->getTimestamp();
}

/**
 * The `MM-DD` on which a window ending on $end_md actually DEACTIVATES:
 * the day AFTER it.
 *
 * The window (blueline_occasion_window_contains()) is INCLUSIVE of the
 * occasion's `end_md`: it is active through all of `end_md` and stops at
 * `end_md + 1 day, 00:00`. So `end_md 00:00` is not a boundary at all;
 * nothing changes at that instant, because the occasion has already been
 * active since `start_md`. The instant something changes is the start of
 * the following day, which is what this returns the calendar date of.
 *
 * Calendar arithmetic, never `+86400`: month lengths (`01-31` -> `02-01`)
 * and the year wrap (`12-31` -> `01-01`) both have to come out right.
 * Stepped in UTC because the value being produced is a recurring `MM-DD`
 * with no clock in it, so there is no wall-clock time here for a DST
 * transition to shift, and the site-timezone 00:00 resolution happens
 * afterwards, in blueline_occasion_next_occurrence_timestamp(). Stepped
 * from 2024, the same leap year blueline_occasion_valid_md() validates
 * against, so a `02-29` end_md is a real date to advance off rather than
 * one PHP would roll forward before the addition ever ran.
 *
 * @param string $end_md 'MM-DD'; callers validate it with
 *                        blueline_occasion_valid_md() first.
 * @return string 'MM-DD'.
 */
function blueline_occasion_end_boundary_md( string $end_md ): string {
	return ( new DateTimeImmutable( '2024-' . $end_md . ' 00:00:00', new DateTimeZone( 'UTC' ) ) )
		->add( new DateInterval( 'P1D' ) )
		->format( 'm-d' );
}

/**
 * The soonest "something changes" instant across every stored auto-mode
 * occasion's activation AND deactivation boundary: the moment WP-Cron
 * should next fire blueline_occasion_cron_boundary_purge().
 * `force_on`/`force_off` occasions are excluded: their activation is not
 * date-driven, so they have no boundary to purge for.
 *
 * The activation boundary is `start_md 00:00`. The deactivation boundary
 * is the start of the day AFTER `end_md`, not `end_md 00:00`; see
 * blueline_occasion_end_boundary_md() for why the inclusive window makes
 * `end_md 00:00` an instant at which nothing changes.
 *
 * Never the correctness mechanism itself (design spec §5/§7.8); only
 * ever a purge-timing hint. blueline_resolve_active_occasion()
 * always recomputes from scratch on every request regardless of whether
 * this ever ran.
 *
 * @param array<string, array<string, mixed>> $occasions blueline_settings( 'occasions' )'s stored value.
 * @param int                                 $now       Unix timestamp to measure "next" from.
 * @return int|null The soonest boundary strictly after $now, or null if
 *                   there are no auto-mode occasions stored at all.
 */
function blueline_occasion_next_boundary_timestamp( array $occasions, int $now ): ?int {
	$boundaries = array();

	foreach ( $occasions as $occasion ) {
		if ( ! is_array( $occasion ) ) {
			continue;
		}

		$mode = $occasion['mode'] ?? 'auto';

		if ( 'auto' !== $mode ) {
			continue;
		}

		$window = $occasion['window'] ?? array();
		$start  = $window['start_md'] ?? '';
		$end    = $window['end_md'] ?? '';

		if ( ! blueline_occasion_valid_md( $start ) || ! blueline_occasion_valid_md( $end ) ) {
			continue;
		}

		$boundaries[] = blueline_occasion_next_occurrence_timestamp( $start, $now );
		$boundaries[] = blueline_occasion_next_occurrence_timestamp( blueline_occasion_end_boundary_md( $end ), $now );
	}

	if ( array() === $boundaries ) {
		return null;
	}

	return min( $boundaries );
}

/**
 * (Re)schedule the single WP-Cron event that purges the page cache at
 * the next occasion window boundary (design spec §5/§7.8). A no-op when
 * no auto-mode occasion is stored (the common case today: `occasions`
 * defaults to an empty array, and 2.1a ships no UI to populate it); any
 * previously scheduled event is cleared in that case. Also a no-op
 * when the correct boundary is ALREADY scheduled, mirroring
 * blueline_settings_migrate()'s own early-exit shape
 * (inc/settings/store.php).
 *
 * @param int|null $now_override Unix timestamp to measure "next" from;
 *                                defaults to the current time. Exists
 *                                purely for testability, matching Task
 *                                3's `blueline_resolve_active_occasion()`
 *                                precedent; never passed by the real
 *                                hook registrations below.
 * @return void
 */
function blueline_occasion_schedule_next_boundary_purge( ?int $now_override = null ): void {
	$now = $now_override ?? time();

	$occasions = blueline_settings( 'occasions' );
	$occasions = is_array( $occasions ) ? $occasions : array();

	$next      = blueline_occasion_next_boundary_timestamp( $occasions, $now );
	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

	if ( null === $next ) {
		if ( false !== $scheduled ) {
			wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
		}
		return;
	}

	if ( $scheduled === $next ) {
		return; // Already scheduled for the right instant.
	}

	if ( false !== $scheduled ) {
		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
	}

	wp_schedule_single_event( $next, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
}
// Scheduled on activation, and re-derived on every write to the settings
// option: exactly the two hooks inc/settings/cache.php's own purge
// trigger already uses for "react to a settings write" (add_option_/
// update_option_; see that file's own add_action() pair), rather than
// polling on `init` for every ordinary page view.
//
// All three pass `10, 0` deliberately. Every one of these hooks fires with
// arguments this callback must never receive: `after_switch_theme` passes
// the OUTGOING theme's NAME (a string), and the option hooks pass option
// values. Against the `?int $now_override` signature, PHP's coercive
// typing throws a TypeError on a non-numeric string, which is a fatal at the
// exact moment an admin activates the theme. Zero accepted args is what
// keeps the real hook registrations honest about "never passed by the real
// hook registrations below" in that parameter's own docblock.
add_action( 'after_switch_theme', 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );
add_action( 'add_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );
add_action( 'update_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_occasion_schedule_next_boundary_purge', 10, 0 );

/**
 * The cron callback itself: purge, then reschedule for the FOLLOWING
 * boundary (never the same one twice), per design spec §5/§7.8's "purges
 * ... and reschedules".
 *
 * @param int|null $now_override Forwarded to
 *                                blueline_occasion_schedule_next_boundary_purge();
 *                                see that function's own docblock.
 * @return void
 */
function blueline_occasion_cron_boundary_purge( ?int $now_override = null ): void {
	blueline_maybe_purge_page_cache();
	blueline_occasion_schedule_next_boundary_purge( $now_override );
}
add_action( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK, 'blueline_occasion_cron_boundary_purge' );

/**
 * Clear the scheduled boundary-purge event when this theme is switched
 * away from. `switch_theme` fires on the OUTGOING theme, the closest
 * thing a theme has to a deactivation hook. Tidiness, not a correctness requirement: a stray scheduled event on a
 * theme that is no longer active simply never finds this callback again
 * once it is deactivated, since add_action() above is registered only
 * while this theme's functions.php actually runs.
 *
 * @return void
 */
function blueline_occasion_clear_scheduled_boundary_purge(): void {
	$scheduled = wp_next_scheduled( BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );

	if ( false !== $scheduled ) {
		wp_unschedule_event( $scheduled, BLUELINE_OCCASION_BOUNDARY_PURGE_HOOK );
	}
}
add_action( 'switch_theme', 'blueline_occasion_clear_scheduled_boundary_purge' );
