<?php
/**
 * One-off: back-fill `sp_user` (WordPress user <-> SportsPress player link)
 * for the current registration season, from WooCommerce order billing names.
 *
 * Context, with Task 16's CORRECTED denominator: real current-season coverage
 * is 84% (76 of the 90 sp_player posts tagged with the current sp_season term,
 * measured on staging 2026-08-11). The "about 12%" this docblock used to quote
 * (237 of 2,037) counted against `sp_current_team`, a STICKY "last team ever
 * rostered onto" field spanning two decades of players -- a retracted premise,
 * off by roughly 5x. Tasks 11 and 12 built a self-service "is this you?" claim
 * flow, which remains the PRIMARY mechanism for the ~16% gap; this script is a
 * small top-up for people who never visit their account page, not the main
 * event its original framing implied.
 *
 * "Safely" is the operative word: a WRONG link means one player sees
 * another player's team, schedule and registration status, and nothing
 * catches that except a confused member emailing the league -- much worse
 * than leaving someone unlinked, which the claim flow still catches later.
 * So this script is deliberately conservative and writes almost nothing
 * automatically:
 *
 *   AUTO   - exactly one candidate scoring >= BLUELINE_BACKFILL_AUTO_THRESHOLD
 *            (0.95). Only these are ever written, and only with `apply`.
 *   REVIEW - multiple candidates, or a single best score in
 *            [BLUELINE_MATCH_THRESHOLD, BLUELINE_BACKFILL_AUTO_THRESHOLD)
 *            i.e. [0.85, 0.95). NEVER written automatically.
 *   NONE   - no candidate scored >= BLUELINE_MATCH_THRESHOLD (0.85) at all.
 *
 * Matching itself is 100% delegated to Task 11's inc/account/player-link.php
 * (blueline_find_player_candidates() / blueline_name_match_score()) -- this
 * script does not reimplement scoring. A second, subtly different matcher
 * would be exactly the kind of hazard this task exists to avoid. That
 * delegation is also what gives this script
 * blueline_name_pair_is_specific_enough() for free: a single-token name can no
 * longer produce a candidate at all, which closes the AUTO-path hole where the
 * pool's exclusion of already-linked players could leave one WRONG namesake as
 * a unique 1.0 match and auto-write it -- the exact failure this docblock says
 * the script exists to prevent. The only
 * thing this script adds on top is: (a) which users are even worth scoring
 * (see "Candidate users" below), and (b) the AUTO/REVIEW/NONE cutoff and the
 * gated write.
 *
 * Candidate users -- current-season orders only, deliberately narrow:
 * "Current season" is the same rule Season State and the My Registration
 * module use (BLUELINE_REGISTRATION_TERM_ID in inc/season-state.php): the
 * newest child product_cat term of "Registration" (term 91) by term_id.
 * Confirmed live on staging 2026-08-11: term 673 "Winter 2026-27", holding
 * exactly the product pair the brief's SKU convention describes --
 * 116522 "Player Registration (W2026-27)" (SKU 116522-P) and 116523 "Goalie
 * Registration (W2026-27)" (SKU 116522-G), the "-P"/"-G" suffixes on a
 * shared numeric prefix equal to product 116522's own post ID, the first of
 * the pair created.
 *
 * A WooCommerce order is "for" the current season if one of its line items
 * is either of those two products. Every such order's customer_id (skipping
 * guest orders, customer_id 0 -- there is nobody to link) is a candidate
 * user; blueline_find_player_candidates() then scores that user's own
 * billing/display name (its own name resolution, already reused rather than
 * re-derived here) against unlinked current-season sp_player titles.
 *
 * IMPORTANT, verified against live staging data before writing this script
 * (not assumed): only ~100 orders on staging currently reference either of
 * the two current-season products, spanning 74 distinct real (non-guest)
 * customer user IDs -- and 69 of those 74 already carry sp_user. This is
 * far smaller than the 1,800-player gap the task's framing describes, and
 * the reason is structural, not a bug in this script: SportsPress's own
 * `sportspress-player-registration` add-on (see player-link.php's docblock
 * -- "the sp_user meta key that sportspress-player-registration also owns")
 * appears to auto-link sp_user at the point of a genuine current-season
 * checkout, which is exactly the population this script's order-based scope
 * can see. The bulk of the still-unlinked 1,800 players are current-season
 * ROSTER members (sp_current_team set) who do not have a matching
 * current-season ORDER under their own WordPress account at all -- most
 * plausibly bulk/legacy roster carry-over, guest checkouts, or a
 * teammate/manager placing one order that covers several players under
 * their own account. None of those are addressable by scoring THIS user's
 * own name against a player list; that would require assuming a name match
 * that the order itself does not evidence, which is precisely the kind of
 * guess this task was told to avoid. Widening the candidate pool beyond
 * "this exact season's orders" (e.g. any historical order, or roster/CSV
 * heuristics) is out of this task's stated scope (Task 14 only -- no
 * archive audit, that is Task 15) and is flagged in the report rather than
 * silently attempted here.
 *
 * Idempotent: a user who already carries a linked player
 * (blueline_get_linked_player_id()) is skipped entirely, every run,
 * including AUTO rows -- so a second `apply` run is a safe no-op.
 *
 * Orders are only evidence of registration if they are in a status that
 * actually means "registered": `completed`, `processing`, or `on-hold`.
 * Cancelled/refunded/failed/pending-payment orders are excluded -- a
 * cancelled order is not evidence that the billing name on it belongs to
 * this season's roster. (On staging 2026-08-11 every one of the 100
 * current-season orders is `completed` or `on-hold` already, so this made
 * no observed difference here, but it is not something a future season's
 * data can be assumed to preserve.)
 *
 * WRITE PATH (revised per Task 14 review, 2026-08-11): this script now
 * calls Task 11's own blueline_link_player_to_user()
 * (inc/account/player-link.php) to perform the write, rather than
 * re-implementing its "player not claimed by someone else" / "user not
 * already linked" invariants here -- a duplicated copy of vetted logic
 * drifts. That function's capability check
 * (`get_current_user_id() === $user_id || current_user_can( 'edit_users' )`)
 * assumes a real current-user context, which `wp eval-file` does NOT
 * provide by default (`get_current_user_id()` is 0). The fix is
 * `wp eval-file`'s own `--user=<id>` global parameter -- confirmed
 * empirically to differ from `--apply`/`--dry-run`: those are unrecognised
 * flags eval-file's parser rejects before this file loads, but `--user` is
 * a genuine WP-CLI global parameter every command (including eval-file)
 * honours, so it reaches WordPress and sets a real current user BEFORE this
 * script runs -- verified live: `wp eval-file - --user=9` (an
 * administrator on staging) makes `current_user_can('edit_users')` true.
 * `apply` mode therefore requires the operator to also pass
 * `--user=<an-administrator-id>`; this script checks
 * `current_user_can( 'edit_users' )` itself before attempting any write and
 * exits with a clear instruction if that capability is absent, rather than
 * silently collecting a WP_Error 'forbidden' on every single row. The
 * `WP_CLI` guard below is unchanged and unrelated to this -- shell access to
 * `wp` is a separate, coarser trust boundary this script still requires
 * regardless of which user context `--user` supplies.
 *
 * Usage -- `wp eval-file` only ever hands this file POSITIONAL arguments
 * for its OWN parsing (via the `$args` array WP-CLI documents for that
 * command); a bare `--apply` flag is rejected by WP-CLI's own argument
 * parser BEFORE this file is even loaded ("Error: Parameter errors: unknown
 * --apply parameter") -- the exact gotcha Task 13's migration script
 * already hit and documented, reconfirmed empirically for this script too
 * (see the task report). Pass the WORD `apply` (no dashes) as a positional
 * argument, and `--user=<id>` (a real global parameter, not rejected) to
 * satisfy the capability check above:
 *
 *   wp eval-file -                     < this-file.php   (report mode: prints the TSV, writes nothing)
 *   wp eval-file - --user=9 apply      < this-file.php   (apply: writes sp_user for AUTO rows only)
 *
 * A literal `apply`/`--apply` is also honoured via `$assoc_args` or the
 * `BLUELINE_BACKFILL_APPLY` environment variable, for callers other than a
 * bare `wp eval-file` invocation -- but `wp eval-file` itself never
 * delivers either of those for an unrecognised flag, so the positional word
 * is the only invocation that actually reaches this script that way.
 *
 * Output: STDOUT carries ONLY the TSV report (a header row, then one row
 * per evaluated user: user_id, user_login, scored_name, player_id,
 * player_name, score, classification) so it stays pipeable straight into
 * `awk -F'\t'`. All narration -- season/product resolution, per-row apply
 * results, final counts -- goes to STDERR, never mixed into STDOUT.
 * `scored_name` is deliberately the ACCOUNT's own billing/display name via
 * blueline_user_match_name() -- the exact string blueline_find_player_candidates()
 * scored -- not the order's own billing name; the two are usually identical
 * (WooCommerce syncs order billing back onto user meta at checkout, and
 * every AUTO row's order billing email matched the account email exactly
 * when hand-checked, see the task report), but printing the account's own
 * string, not the order's, is what removes the ambiguity of "which name
 * actually produced this score" for whoever reads the report.
 *
 * @package blueline
 */

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script, WP_Filesystem isn't available before WordPress has loaded (which is exactly the condition being reported).
	fwrite( STDERR, "This script must be run via `wp eval-file`, not the PHP CLI directly.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	// The write path skips blueline_link_player_to_user()'s HTTP-request-shaped
	// capability check (see file docblock) precisely because this only ever
	// runs under WP-CLI shell access -- that access IS the capability check.
	// Refusing to run outside WP_CLI keeps that substitution honest.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script; WP_Filesystem is for the filesystem, not STDERR narration.
	fwrite( STDERR, "This script must be run via WP-CLI (`wp eval-file`), which is the trust boundary its write path relies on.\n" );
	exit( 1 );
}

const BLUELINE_BACKFILL_AUTO_THRESHOLD = 0.95;

$blueline_backfill_args       = isset( $args ) && is_array( $args ) ? $args : array();
$blueline_backfill_assoc_args = isset( $assoc_args ) && is_array( $assoc_args ) ? $assoc_args : array();
$blueline_backfill_apply_env  = getenv( 'BLUELINE_BACKFILL_APPLY' );

$blueline_backfill_apply = in_array( 'apply', $blueline_backfill_args, true )
	|| in_array( '--apply', $blueline_backfill_args, true )
	|| ! empty( $blueline_backfill_assoc_args['apply'] )
	|| ( false !== $blueline_backfill_apply_env && '' !== $blueline_backfill_apply_env && '0' !== $blueline_backfill_apply_env );

/**
 * Required dependencies -- fail loudly and early rather than half-run.
 */
$blueline_backfill_missing = array();
if ( ! function_exists( 'blueline_find_player_candidates' ) ) {
	$blueline_backfill_missing[] = 'blueline_find_player_candidates() (inc/account/player-link.php)';
}
if ( ! function_exists( 'blueline_get_linked_player_id' ) ) {
	$blueline_backfill_missing[] = 'blueline_get_linked_player_id() (inc/account/player-link.php)';
}
if ( ! function_exists( 'blueline_link_player_to_user' ) ) {
	$blueline_backfill_missing[] = 'blueline_link_player_to_user() (inc/account/player-link.php)';
}
if ( ! function_exists( 'blueline_user_match_name' ) ) {
	$blueline_backfill_missing[] = 'blueline_user_match_name() (inc/account/player-link.php)';
}
if ( ! defined( 'BLUELINE_PLAYER_USER_META' ) ) {
	$blueline_backfill_missing[] = 'BLUELINE_PLAYER_USER_META (inc/account/player-link.php)';
}
if ( ! defined( 'BLUELINE_REGISTRATION_TERM_ID' ) ) {
	$blueline_backfill_missing[] = 'BLUELINE_REGISTRATION_TERM_ID (inc/season-state.php)';
}
if ( ! function_exists( 'wc_get_order' ) || ! taxonomy_exists( 'product_cat' ) ) {
	$blueline_backfill_missing[] = 'WooCommerce (wc_get_order() / product_cat taxonomy)';
}
if ( ! post_type_exists( 'sp_player' ) ) {
	$blueline_backfill_missing[] = 'SportsPress (sp_player post type)';
}

if ( ! empty( $blueline_backfill_missing ) ) {
	fwrite( STDERR, "Missing required dependencies, aborting:\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	foreach ( $blueline_backfill_missing as $dep ) {
		fwrite( STDERR, ' - ' . $dep . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}
	exit( 1 );
}

/**
 * The current-season product_cat term: the newest child of "Registration"
 * (BLUELINE_REGISTRATION_TERM_ID) by term_id -- identical rule to
 * inc/season-state.php's blueline_season_state_data() and
 * inc/account/player-data.php's blueline_get_user_registration_status(), so
 * "current season" means the same thing everywhere on this site.
 *
 * NOTE (flagged, not fixed, per Task 14 review): this get_terms() call is a
 * verbatim duplicate of the one in inc/season-state.php's
 * blueline_season_state_data() (and a near-duplicate of
 * inc/account/player-data.php's blueline_get_user_registration_status()).
 * Left as-is because this is a one-off script, not code that runs on every
 * page load -- but if this script is ever scheduled to re-run periodically
 * rather than by hand, factoring this out into one shared helper (e.g.
 * `blueline_current_registration_season_term()`) that all three call would
 * be worth doing then, so the rule can't drift between copies.
 *
 * @return WP_Term|null
 */
function blueline_backfill_current_season_term() {
	$season_terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'parent'     => BLUELINE_REGISTRATION_TERM_ID,
			'orderby'    => 'term_id',
			'order'      => 'DESC',
			'number'     => 1,
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $season_terms ) || empty( $season_terms ) ) {
		return null;
	}

	return $season_terms[0];
}

/**
 * Every `product` post in $season_term, any status -- an order can reference
 * a product that has since been unpublished, and we still want to find it.
 *
 * @param WP_Term $season_term Current-season product_cat term.
 * @return int[] Product post IDs.
 */
function blueline_backfill_season_product_ids( WP_Term $season_term ): array {
	return get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-off script, scoped to a single small season term (2 products on staging), not an unbounded query.
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $season_term->term_id,
				),
			),
		)
	);
}

/**
 * Every WooCommerce order ID with at least one line item for one of
 * $product_ids, found directly against the order-items tables rather than
 * `wc_get_orders()` (which has no "contains this product" filter of its
 * own). These tables (`woocommerce_order_items` /
 * `woocommerce_order_itemmeta`) are the same regardless of whether HPOS
 * custom order tables are enabled -- confirmed on staging, where
 * `woocommerce_custom_orders_table_enabled` is `no` and orders are plain
 * `shop_order` posts, but this query does not depend on that either way.
 *
 * @param int[] $product_ids Product post IDs.
 * @return int[] Distinct order IDs.
 */
function blueline_backfill_order_ids_for_products( array $product_ids ): array {
	$product_ids = array_values( array_unique( array_map( 'absint', $product_ids ) ) );
	if ( empty( $product_ids ) ) {
		return array();
	}

	global $wpdb;

	// $placeholders is a fixed string of %d tokens sized to count( $product_ids ), never user input;
	// every value is bound via prepare() immediately below.
	$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one-off script; no core API exposes "orders containing product X"; table names and %d placeholders only (no user input, sniff can't see the %d tokens through the $placeholders interpolation), values bound via prepare() args.
	$order_ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oi.order_id FROM {$wpdb->prefix}woocommerce_order_items oi INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id WHERE oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value IN ({$placeholders})", $product_ids ) );

	return array_map( 'absint', (array) $order_ids );
}

/**
 * Order statuses that actually mean "this person registered" -- everything
 * else (cancelled, refunded, failed, pending-payment-never-completed, trash)
 * is not evidence that the billing name on the order belongs to this
 * season's roster and must not count as a candidate-producing order.
 */
const BLUELINE_BACKFILL_REGISTERED_STATUSES = array( 'completed', 'processing', 'on-hold' );

/**
 * For each of $order_ids, the owning user_id -- provided their order is in
 * a status that means "registered" (BLUELINE_BACKFILL_REGISTERED_STATUSES)
 * -- keyed to their most recent qualifying order's ID and date among them.
 * customer_id 0 (guest, or a deleted/anonymised order) is skipped, there is
 * nobody to link. "Most recent" matters because some customers placed more
 * than one current-season order (5 of staging's 74 distinct customers did).
 *
 * Does NOT read the order's own billing name -- see the file docblock's
 * "Output" section for why the TSV's `scored_name` column is deliberately
 * the ACCOUNT's own billing/display name (blueline_user_match_name()),
 * read later once we know which candidates survived, not the order's.
 *
 * @param int[] $order_ids Order IDs to inspect.
 * @return array<int, array{order_id:int, date_ts:int}> user_id => most recent qualifying order.
 */
function blueline_backfill_user_orders( array $order_ids ): array {
	$by_user = array();

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			continue;
		}

		if ( ! $order->has_status( BLUELINE_BACKFILL_REGISTERED_STATUSES ) ) {
			continue; // Cancelled/refunded/failed/pending -- not evidence of registration.
		}

		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			continue; // Guest order -- nobody to link.
		}

		$created  = $order->get_date_created();
		$date_ts  = $created ? $created->getTimestamp() : 0;
		$existing = $by_user[ $user_id ] ?? null;

		if ( null !== $existing && $existing['date_ts'] >= $date_ts ) {
			continue; // Already holding an equally-or-more-recent order for this user.
		}

		$by_user[ $user_id ] = array(
			'order_id' => $order_id,
			'date_ts'  => $date_ts,
		);
	}

	return $by_user;
}

/**
 * Classify a blueline_find_player_candidates() result per this script's
 * conservative rule -- see the file docblock for the AUTO/REVIEW/NONE
 * definitions.
 *
 * @param array $candidates Return value of blueline_find_player_candidates().
 * @return string One of AUTO|REVIEW|NONE.
 */
function blueline_backfill_classify( array $candidates ): string {
	if ( empty( $candidates ) ) {
		return 'NONE';
	}

	if ( 1 === count( $candidates ) && $candidates[0]['score'] >= BLUELINE_BACKFILL_AUTO_THRESHOLD ) {
		return 'AUTO';
	}

	return 'REVIEW';
}

/**
 * A TSV-safe cell: tabs/newlines flattened to a single space, since they
 * would otherwise corrupt the single-line-per-row TSV contract STDOUT makes.
 *
 * @param string $value Raw value.
 * @return string
 */
function blueline_backfill_tsv_cell( string $value ): string {
	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

/**
 * One row's rendered TSV line (no trailing newline) -- built as its own
 * statement so the single `echo` at the call site is a single physical
 * line, which is what lets one phpcs:ignore comment cover it entirely.
 *
 * @param array $row One entry of $blueline_backfill_rows.
 * @return string
 */
function blueline_backfill_row_to_tsv_line( array $row ): string {
	return implode(
		"\t",
		array(
			(string) $row['user_id'],
			blueline_backfill_tsv_cell( $row['user_login'] ),
			blueline_backfill_tsv_cell( $row['scored_name'] ),
			(string) $row['player_id'],
			blueline_backfill_tsv_cell( (string) $row['player_name'] ),
			(string) $row['score'],
			$row['classification'],
		)
	);
}

// --- Resolve season, products, orders. Narration to STDERR only. ---

$blueline_backfill_season_term = blueline_backfill_current_season_term();

if ( ! $blueline_backfill_season_term ) {
	fwrite( STDERR, 'No current-season product_cat term found under Registration (term ' . BLUELINE_REGISTRATION_TERM_ID . "). Nothing to do.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite( STDERR, sprintf( "Current season: %s (term_id %d)\n", $blueline_backfill_season_term->name, $blueline_backfill_season_term->term_id ) );

$blueline_backfill_product_ids = blueline_backfill_season_product_ids( $blueline_backfill_season_term );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite( STDERR, 'Season products: ' . implode( ', ', $blueline_backfill_product_ids ) . "\n" );

$blueline_backfill_order_ids = blueline_backfill_order_ids_for_products( $blueline_backfill_product_ids );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite( STDERR, 'Orders referencing a season product: ' . count( $blueline_backfill_order_ids ) . "\n" );

$blueline_backfill_user_orders = blueline_backfill_user_orders( $blueline_backfill_order_ids );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite( STDERR, 'Distinct non-guest customers among them: ' . count( $blueline_backfill_user_orders ) . "\n" );
fwrite( STDERR, ( $blueline_backfill_apply ? "Mode: APPLY -- AUTO rows will be written.\n" : "Mode: REPORT -- no writes will be made.\n" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

// --- Score each candidate user, build the report rows. ---

$blueline_backfill_rows          = array();
$blueline_backfill_skipped_count = 0;
$blueline_backfill_counts        = array(
	'AUTO'   => 0,
	'REVIEW' => 0,
	'NONE'   => 0,
);

$blueline_backfill_candidate_user_ids = array_keys( $blueline_backfill_user_orders );
sort( $blueline_backfill_candidate_user_ids );

foreach ( $blueline_backfill_candidate_user_ids as $blueline_backfill_user_id ) {
	if ( null !== blueline_get_linked_player_id( $blueline_backfill_user_id ) ) {
		++$blueline_backfill_skipped_count; // Idempotent: already linked, nothing to do.
		continue;
	}

	$blueline_backfill_candidates     = blueline_find_player_candidates( $blueline_backfill_user_id );
	$blueline_backfill_classification = blueline_backfill_classify( $blueline_backfill_candidates );
	$blueline_backfill_top            = $blueline_backfill_candidates[0] ?? null;
	$blueline_backfill_user           = get_userdata( $blueline_backfill_user_id );

	++$blueline_backfill_counts[ $blueline_backfill_classification ];

	$blueline_backfill_rows[] = array(
		'user_id'        => $blueline_backfill_user_id,
		'user_login'     => $blueline_backfill_user ? $blueline_backfill_user->user_login : '(deleted user)',
		// The ACCOUNT's own billing/display name, via the same resolver
		// blueline_find_player_candidates() itself used to produce the score
		// above -- not the order's billing name (see file docblock).
		'scored_name'    => blueline_user_match_name( $blueline_backfill_user_id ),
		'player_id'      => $blueline_backfill_top ? $blueline_backfill_top['player_id'] : '',
		'player_name'    => $blueline_backfill_top ? $blueline_backfill_top['name'] : '',
		'score'          => $blueline_backfill_top ? $blueline_backfill_top['score'] : '',
		'classification' => $blueline_backfill_classification,
		'candidates'     => $blueline_backfill_candidates,
	);
}

// --- Capability refusal comes BEFORE the report. ---
//
// The TSV below carries user logins, the billing names those accounts set
// themselves, and the names of the candidate PLAYERS they were scored against
// -- i.e. other members' names -- so it is not something to emit and only then
// discover the operator was never permitted to act. An `apply` run that cannot
// apply stops here, having printed nothing.

if ( $blueline_backfill_apply && ! current_user_can( 'edit_users' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Refusing to apply: no current user with the edit_users capability.\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "blueline_link_player_to_user() requires this. Re-run with --user=<an-administrator-id>,\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "a real WP-CLI global parameter (unlike --apply), e.g.:\n" );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "  wp eval-file - --user=9 apply   < this-file.php\n" );
	exit( 1 );
}

// --- STDOUT: the TSV report, and ONLY the TSV report. ---

echo implode( "\t", array( 'user_id', 'user_login', 'scored_name', 'player_id', 'player_name', 'score', 'classification' ) ) . PHP_EOL;

foreach ( $blueline_backfill_rows as $blueline_backfill_row ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI STDOUT TSV row via `wp eval-file`, not browser-rendered HTML; esc_html() would corrupt the tab-separated values a consumer pipes into awk/cut.
	echo blueline_backfill_row_to_tsv_line( $blueline_backfill_row ) . PHP_EOL;
}

// --- Apply: write sp_user for AUTO rows only, via blueline_link_player_to_user().
// Narration to STDERR. See file docblock's "WRITE PATH" section for why this
// calls Task 11's own vetted function rather than duplicating its invariants,
// and why that requires --user=<admin-id> on the wp eval-file invocation. ---

$blueline_backfill_applied_count = 0;

if ( $blueline_backfill_apply ) {
	// The edit_users refusal already ran, above the report -- see there.
	foreach ( $blueline_backfill_rows as $blueline_backfill_row ) {
		if ( 'AUTO' !== $blueline_backfill_row['classification'] ) {
			continue;
		}

		$blueline_backfill_user_id   = (int) $blueline_backfill_row['user_id'];
		$blueline_backfill_player_id = (int) $blueline_backfill_row['player_id'];

		$blueline_backfill_result = blueline_link_player_to_user( $blueline_backfill_player_id, $blueline_backfill_user_id );

		if ( is_wp_error( $blueline_backfill_result ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite(
				STDERR,
				"SKIP user_id={$blueline_backfill_user_id} -> player_id={$blueline_backfill_player_id}: "
				. $blueline_backfill_result->get_error_code() . ' - ' . $blueline_backfill_result->get_error_message() . "\n"
			);
			continue;
		}

		++$blueline_backfill_applied_count;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( STDERR, "APPLIED user_id={$blueline_backfill_user_id} -> player_id={$blueline_backfill_player_id}\n" );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
fwrite(
	STDERR,
	sprintf(
		"\nEvaluated %d candidate user(s): AUTO=%d REVIEW=%d NONE=%d. Already linked (skipped, idempotent): %d.\n",
		count( $blueline_backfill_rows ),
		$blueline_backfill_counts['AUTO'],
		$blueline_backfill_counts['REVIEW'],
		$blueline_backfill_counts['NONE'],
		$blueline_backfill_skipped_count
	)
);

if ( $blueline_backfill_apply ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Applied {$blueline_backfill_applied_count} AUTO link(s). REVIEW and NONE rows were never written.\n" );
} else {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "Report mode -- no writes made. Re-run with a positional `apply` argument to write AUTO rows.\n" );
}
