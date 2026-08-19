<?php
/**
 * Revert the 2026-08-12/13 standings correction.
 *
 * On 2026-08-13, 71 league tables were changed so their regular-season standings stop counting
 * playoff games. Each changed table carries a meta field `_arl_standings_fix_2026_08_12` holding
 * its previous configuration, so this script needs no external backup file.
 *
 * Run:
 *   scp scripts/revert-standings-fix-2026-08-12.php production-host:/tmp/arl-revert.php
 *   ssh production-host 'sudo -u www-data /usr/local/bin/wp --path=/var/www/rookiehockey.ca/htdocs \
 *     eval-file /tmp/arl-revert.php --user=9 --skip-themes; rm -f /tmp/arl-revert.php'
 *
 * To revert only SOME tables, list their ids in $ONLY below. Leave empty to revert everything.
 * Examples:
 *   S2025 only            => array( 112924, 112926, 112928, 112930, 112932 )
 *   W2025-26 only         => array( 114393, 114394, 114395, 114396, 114397 )
 *   everything but S2025  => leave $ONLY empty, then re-apply S2025 by hand, or use $EXCEPT.
 *
 * Set $DRY_RUN = true to preview without writing.
 */

$ONLY    = array();  // empty = every table carrying the marker
$EXCEPT  = array();  // ids to leave corrected
$DRY_RUN = false;

global $wpdb;

$ids = $wpdb->get_col(
	"SELECT post_id FROM {$wpdb->postmeta}
	 WHERE meta_key = '_arl_standings_fix_2026_08_12' ORDER BY post_id" );

if ( $ONLY )   { $ids = array_values( array_intersect( $ids, $ONLY ) ); }
if ( $EXCEPT ) { $ids = array_values( array_diff( $ids, $EXCEPT ) ); }

echo 'tables to revert: ', count( $ids ), ( $DRY_RUN ? "  (DRY RUN)\n" : "\n" ), "\n";

$done = 0; $fail = 0;
foreach ( $ids as $id ) {
	$raw  = get_post_meta( $id, '_arl_standings_fix_2026_08_12', true );
	$prev = json_decode( $raw, true );
	if ( ! is_array( $prev ) || ! array_key_exists( 'prev_sp_date', $prev ) ) {
		echo '  *** ', $id, ' has no usable backup meta — skipped', "\n";
		$fail++;
		continue;
	}

	printf( "  %-8s %-34s  date '%s' -> '%s'   window %s..%s -> %s..%s%s\n",
		$id, mb_substr( get_the_title( $id ), 0, 32 ),
		get_post_meta( $id, 'sp_date', true ), $prev['prev_sp_date'],
		get_post_meta( $id, 'sp_date_from', true ), get_post_meta( $id, 'sp_date_to', true ),
		$prev['prev_sp_date_from'], $prev['prev_sp_date_to'],
		( $prev['prev_seasons'] != wp_get_post_terms( $id, 'sp_season', array( 'fields' => 'ids' ) )
			? '   seasons -> ' . implode( ',', (array) $prev['prev_seasons'] ) : '' ) );

	if ( $DRY_RUN ) { $done++; continue; }

	update_post_meta( $id, 'sp_date', $prev['prev_sp_date'] );
	update_post_meta( $id, 'sp_date_from', $prev['prev_sp_date_from'] );
	update_post_meta( $id, 'sp_date_to', $prev['prev_sp_date_to'] );
	if ( ! empty( $prev['prev_seasons'] ) ) {
		wp_set_post_terms( $id, array_map( 'intval', $prev['prev_seasons'] ), 'sp_season', false );
	}
	delete_post_meta( $id, '_arl_standings_fix_2026_08_12' );
	$done++;
}

if ( ! $DRY_RUN ) { wp_cache_flush(); }
echo "\nreverted: ", $done, ( $fail ? "  failed: {$fail}" : '' ), "\n";
echo $DRY_RUN ? "nothing was written\n" : "object cache flushed\n";
