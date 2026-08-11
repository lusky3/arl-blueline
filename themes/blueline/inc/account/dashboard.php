<?php
/**
 * The league-first My Account dashboard: rendering helpers consumed by
 * woocommerce/myaccount/dashboard.php, woocommerce/myaccount/navigation.php,
 * and the my-team/my-schedule endpoint handlers wired at the bottom of this
 * file.
 *
 * Inverts Task 9's ported (shop-account) dashboard: league content --
 * next game, team, season stats -- leads; billing is a visually demoted
 * group at the bottom. For the ~88% of current-season players with no
 * sp_user link (Task 11), the claim card is the primary experience, shown
 * in place of the league modules, not squeezed in alongside three empty
 * ones.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Card chrome (open half) for one dashboard module. Deliberately its own,
 * smaller component -- NOT a reuse of blueline_homepage_module_start()
 * (inc/homepage-modules.php) -- because that component's `.bl-container`
 * assumes it is a direct child of `<main>`; the account page's WooCommerce
 * content area is already inside one `.bl-container` (see
 * blueline_wc_wrapper_start() in inc/woocommerce.php), and nesting a second
 * one per module would both narrow the already-narrow content column
 * further at every level and break the homepage's own full-bleed
 * alternating-background band look, which was never the intent here.
 *
 * @param string $name       Module slug, becomes the bl-account-module--{name} modifier.
 * @param string $title      Module heading text.
 * @param string $link_url   "See more" URL, or '' to omit the link.
 * @param string $link_label "See more" link text.
 */
function blueline_account_module_start( string $name, string $title, string $link_url = '', string $link_label = '' ) {
	?>
	<section class="bl-account-module bl-account-module--<?php echo esc_attr( $name ); ?>">
		<header class="bl-account-module__header">
			<h2 class="bl-account-module__title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( $link_url ) : ?>
				<a class="bl-account-module__link" href="<?php echo esc_url( $link_url ); ?>">
					<?php echo esc_html( $link_label ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
			<?php endif; ?>
		</header>
		<div class="bl-account-module__body">
	<?php
}

/**
 * Card chrome (close half). See blueline_account_module_start().
 */
function blueline_account_module_end() {
	?>
		</div>
	</section>
	<?php
}

/**
 * A module's empty state: the blue-leaf mark plus one line of copy, per the
 * hard constraint that no module may render an empty container.
 *
 * @param string $message One line of copy.
 */
function blueline_account_module_empty_state( string $message ) {
	?>
	<div class="bl-account-module__empty">
		<?php
		if ( function_exists( 'blueline_leaf_mark' ) ) {
			blueline_leaf_mark( 'bl-account-module__empty-mark' );
		}
		?>
		<p class="bl-account-module__empty-text"><?php echo esc_html( $message ); ?></p>
	</div>
	<?php
}

/**
 * Show a one-line result notice after a redirect from the claim handler
 * (admin_post_blueline_claim_player, Task 11). Purely a display of an
 * already-decided outcome -- the state change itself was nonce-verified in
 * blueline_handle_claim_player_submission() before this page ever loads --
 * so reading the query var here needs no nonce of its own.
 *
 * Deliberately its OWN `.bl-account-notice` class, not WooCommerce's
 * `.woocommerce-message`/`.woocommerce-error`: staging (and, presumably,
 * production) carries a pre-existing site-wide custom-CSS snippet
 * (`#simple-css-output`, visible in every page's `<head>`) with
 * `.woocommerce-message { display: none !important; }` -- confirmed live
 * by actually submitting a claim and finding the "you're linked" success
 * notice invisible. Reusing that class would have made the one message a
 * newly-linked user most needs to see silently disappear.
 */
function blueline_account_render_claim_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from our own post-claim redirect, not a state-changing request.
	if ( empty( $_GET['blueline_claim'] ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
	$status = sanitize_key( wp_unslash( $_GET['blueline_claim'] ) );

	$messages = array(
		'linked'              => array( 'success', __( 'You’re linked! Your team, schedule, and stats are below.', 'blueline' ) ),
		'invalid'             => array( 'error', __( 'We couldn’t confirm that player. Please try again or contact the league.', 'blueline' ) ),
		'already_linked'      => array( 'error', __( 'That player is already linked to a different account. Contact the league if this is a mistake.', 'blueline' ) ),
		'user_already_linked' => array( 'error', __( 'Your account is already linked to a player.', 'blueline' ) ),
		'forbidden'           => array( 'error', __( 'You’re not allowed to do that.', 'blueline' ) ),
	);

	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $status ];
	?>
	<div class="bl-account-notice bl-account-notice--<?php echo esc_attr( $type ); ?>" role="alert">
		<?php echo esc_html( $text ); ?>
	</div>
	<?php
}

/**
 * The claim card: "Is this you?" plus one-click-confirm candidates, or a
 * plain "contact the league" message when there are none. This is the
 * PRIMARY experience for an unlinked user (~88% of current-season
 * players), not a fallback -- it replaces the next-game/team/season
 * modules entirely rather than sitting alongside three empty versions of
 * them.
 *
 * @param int $user_id Current WordPress user ID.
 */
function blueline_account_render_claim_card( int $user_id ) {
	$candidates = function_exists( 'blueline_find_player_candidates' ) ? blueline_find_player_candidates( $user_id ) : array();

	blueline_account_module_start( 'claim', __( 'Is this you?', 'blueline' ) );

	if ( empty( $candidates ) ) {
		blueline_account_module_empty_state(
			__( 'We couldn’t find a player profile that matches your account yet. Contact the league and we’ll get you linked up.', 'blueline' )
		);
	} else {
		?>
		<p class="bl-account-claim__intro">
			<?php esc_html_e( 'We found a player profile that looks like you. Confirm it’s yours to unlock your team, schedule, and stats here.', 'blueline' ); ?>
		</p>
		<ul class="bl-account-claim__list">
			<?php foreach ( $candidates as $candidate ) : ?>
				<li class="bl-account-claim__item">
					<span class="bl-account-claim__name"><?php echo esc_html( $candidate['name'] ); ?></span>
					<form class="bl-account-claim__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'blueline_claim_player' ); ?>
						<input type="hidden" name="action" value="blueline_claim_player">
						<input type="hidden" name="player_id" value="<?php echo esc_attr( (string) $candidate['player_id'] ); ?>">
						<button
							type="submit"
							class="bl-btn bl-btn--primary bl-account-claim__confirm"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %s: candidate player name. */ __( 'Yes, that’s me — %s', 'blueline' ), $candidate['name'] ) ); ?>"
						>
							<span class="bl-skew"><span aria-hidden="true"><?php esc_html_e( 'Yes, that’s me', 'blueline' ); ?></span></span>
						</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	blueline_account_module_end();
}

/**
 * Team roster for $team_id, excluding $exclude_player_id, sorted by name.
 * A lean ids-only query plus one batched title fetch (reusing
 * blueline_get_post_titles() from Task 11's inc/account/player-link.php)
 * rather than get_the_title()/get_post() per teammate.
 *
 * @param int $team_id           sp_team post ID.
 * @param int $exclude_player_id A player id to omit (the viewer themselves).
 * @return array<int, array{player_id:int, name:string}>
 */
function blueline_get_team_roster( int $team_id, int $exclude_player_id = 0 ): array {
	if ( $team_id <= 0 || ! post_type_exists( 'sp_player' ) ) {
		return array();
	}

	$player_ids = get_posts(
		array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'none',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped to one team's own roster, not an unbounded query.
				array(
					'key'   => 'sp_current_team',
					'value' => (string) $team_id,
				),
			),
		)
	);

	$player_ids = array_values( array_diff( array_map( 'absint', $player_ids ), array( $exclude_player_id ) ) );

	if ( empty( $player_ids ) ) {
		return array();
	}

	$titles = function_exists( 'blueline_get_post_titles' ) ? blueline_get_post_titles( $player_ids ) : array();

	$roster = array();
	foreach ( $player_ids as $id ) {
		if ( empty( $titles[ $id ] ) ) {
			continue;
		}
		$roster[] = array(
			'player_id' => $id,
			'name'      => $titles[ $id ],
		);
	}

	usort( $roster, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

	return $roster;
}

/**
 * The My Team module: crest, division, my jersey number, teammate list (or
 * a count on the compact dashboard view). "Record" is deliberately not
 * recomputed here -- SportsPress's own standings table is the correct,
 * already-computed source (the same call blueline_sp_team_hero() already
 * makes for the single-team page, see inc/sportspress.php); this module
 * links straight to it instead of a second, hand-rolled, and on this site's
 * sparsely-recorded results data likely WRONG, W/L/T aggregation.
 *
 * @param int  $player_id sp_player post ID.
 * @param bool $full      True on the dedicated My Team tab (full named
 *                        roster); false on the compact dashboard card
 *                        (teammate count only).
 */
function blueline_account_render_my_team( int $player_id, bool $full = false ) {
	$team = blueline_get_player_team( $player_id );

	blueline_account_module_start(
		'my-team',
		__( 'My team', 'blueline' ),
		$team ? get_permalink( $team['team_id'] ) : '',
		__( 'View team & standings', 'blueline' )
	);

	if ( ! $team ) {
		blueline_account_module_empty_state( __( 'You’re not on a roster yet. Once you’re added to a team, it will show up here.', 'blueline' ) );
	} else {
		$roster = blueline_get_team_roster( $team['team_id'], $player_id );
		?>
		<div class="bl-account-team">
			<div class="bl-account-team__identity">
				<?php if ( $team['logo_id'] ) : ?>
					<span class="bl-account-team__crest"><?php echo wp_get_attachment_image( $team['logo_id'], 'thumbnail' ); ?></span>
				<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
					<span class="bl-account-team__crest bl-account-team__crest--fallback">
						<?php blueline_leaf_mark( 'bl-account-team__crest-mark' ); ?>
					</span>
				<?php endif; ?>

				<div class="bl-account-team__meta">
					<p class="bl-account-team__name"><?php echo esc_html( $team['name'] ); ?></p>
					<?php if ( $team['division'] ) : ?>
						<p class="bl-account-team__division"><?php echo esc_html( $team['division'] ); ?></p>
					<?php endif; ?>
					<?php if ( $team['number'] ) : ?>
						<p class="bl-account-team__number">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: jersey number. */
									__( 'Jersey #%s', 'blueline' ),
									$team['number']
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $roster && $full ) : ?>
				<ul class="bl-account-team__roster">
					<?php foreach ( $roster as $mate ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $mate['player_id'] ) ); ?>"><?php echo esc_html( $mate['name'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php elseif ( $roster ) : ?>
				<p class="bl-account-team__roster-summary">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: teammate count. */
							_n( '%d teammate', '%d teammates', count( $roster ), 'blueline' ),
							count( $roster )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	blueline_account_module_end();
}

/**
 * The My Next Game module: date, time, rink AND pad, opponent, and an
 * add-to-calendar link. Venue is shown as a link straight to its own
 * sp_venue archive (taxonomy-venue.php, Task 8) rather than inventing a
 * second "which arena" label here -- that page already carries the street
 * address and, when the arena is split into named pads sharing one
 * address (e.g. the Red/Black pair), a cross-link to the sibling pad.
 *
 * Reuses blueline_sp_event_calendar_url() (inc/sportspress.php) for the
 * add-to-calendar link rather than a new .ics/template_redirect endpoint:
 * the brief offers a Google Calendar URL as an explicit alternative, and
 * this one is already shipped, tested in production use on single-event
 * pages, and adds no new query var or request handler to secure.
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_account_render_next_game( int $player_id ) {
	$event = blueline_get_player_next_event( $player_id );

	blueline_account_module_start( 'next-game', __( 'My next game', 'blueline' ) );

	if ( ! $event ) {
		blueline_account_module_empty_state( __( 'No upcoming game on your schedule yet.', 'blueline' ) );
	} else {
		$opponent_name = $event['opponent_team_id']
			? ( function_exists( 'blueline_sp_title' ) ? blueline_sp_title( $event['opponent_team_id'] ) : get_the_title( $event['opponent_team_id'] ) )
			: __( 'TBD', 'blueline' );

		$calendar_url = function_exists( 'blueline_sp_event_calendar_url' ) ? blueline_sp_event_calendar_url( $event['event_id'] ) : '';
		$venue_url    = ( $event['venue_term_id'] && taxonomy_exists( 'sp_venue' ) ) ? get_term_link( $event['venue_term_id'], 'sp_venue' ) : null;
		?>
		<div class="bl-account-next-game">
			<p class="bl-account-next-game__date">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: event date, 2: event time. */
						__( '%1$s at %2$s', 'blueline' ),
						get_the_date( 'D, M j', $event['event_id'] ),
						get_the_time( get_option( 'time_format' ), $event['event_id'] )
					)
				);
				?>
			</p>
			<p class="bl-account-next-game__matchup">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: "vs" (home) or "@" (away), 2: opponent team name. */
						__( '%1$s %2$s', 'blueline' ),
						$event['is_home'] ? __( 'vs', 'blueline' ) : __( '@', 'blueline' ),
						$opponent_name
					)
				);
				?>
			</p>
			<?php if ( $event['venue'] ) : ?>
				<p class="bl-account-next-game__venue">
					<?php if ( $venue_url && ! is_wp_error( $venue_url ) ) : ?>
						<a href="<?php echo esc_url( $venue_url ); ?>"><?php echo esc_html( $event['venue'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $event['venue'] ); ?>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<div class="bl-account-next-game__actions">
				<a class="bl-account-module__link" href="<?php echo esc_url( get_permalink( $event['event_id'] ) ); ?>">
					<?php esc_html_e( 'Game details', 'blueline' ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
				<?php if ( $calendar_url ) : ?>
					<a class="bl-btn bl-btn--secondary bl-account-next-game__calendar" href="<?php echo esc_url( $calendar_url ); ?>">
						<span class="bl-skew"><span><?php esc_html_e( 'Add to calendar', 'blueline' ); ?></span></span>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	blueline_account_module_end();
}

/**
 * The My Season module: GP / G / A / PIM. Always renders the four tiles --
 * blueline_get_player_season_stats() always returns a fully zero-filled
 * shape by contract, and that zero-filled grid ("GP 0 G 0 A 0 PIM 0") IS
 * the explicit empty state here, not a placeholder needing a second
 * leaf-mark treatment on top of it.
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_account_render_season_stats( int $player_id ) {
	$stats = blueline_get_player_season_stats( $player_id );

	blueline_account_module_start( 'season-stats', __( 'My season', 'blueline' ) );
	?>
	<dl class="bl-account-stats">
		<div class="bl-account-stats__item">
			<dt><?php esc_html_e( 'GP', 'blueline' ); ?></dt>
			<dd><?php echo esc_html( (string) $stats['gp'] ); ?></dd>
		</div>
		<div class="bl-account-stats__item">
			<dt><?php esc_html_e( 'G', 'blueline' ); ?></dt>
			<dd><?php echo esc_html( (string) $stats['g'] ); ?></dd>
		</div>
		<div class="bl-account-stats__item">
			<dt><?php esc_html_e( 'A', 'blueline' ); ?></dt>
			<dd><?php echo esc_html( (string) $stats['a'] ); ?></dd>
		</div>
		<div class="bl-account-stats__item">
			<dt><?php esc_html_e( 'PIM', 'blueline' ); ?></dt>
			<dd><?php echo esc_html( (string) $stats['pim'] ); ?></dd>
		</div>
	</dl>
	<?php if ( ! array_filter( $stats ) ) : ?>
		<p class="bl-account-stats__hint"><?php esc_html_e( 'Stats update after each game is scored.', 'blueline' ); ?></p>
	<?php endif; ?>
	<?php
	blueline_account_module_end();
}

/**
 * The My Registration module: season, paid/unpaid, and a receipt link to
 * that one order's own view-order page (not the full order-history list --
 * that already lives at the demoted "My Registrations" billing endpoint
 * below).
 *
 * @param int $user_id WordPress user ID.
 */
function blueline_account_render_registration( int $user_id ) {
	$status = blueline_get_user_registration_status( $user_id );

	blueline_account_module_start( 'registration', __( 'My registration', 'blueline' ) );

	if ( ! $status ) {
		blueline_account_module_empty_state( __( 'No registration found for the current season yet.', 'blueline' ) );
	} else {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $status['order_id'] ) : false;
		?>
		<div class="bl-account-registration">
			<p class="bl-account-registration__season"><?php echo esc_html( $status['season'] ); ?></p>
			<p class="bl-account-registration__status bl-account-registration__status--<?php echo esc_attr( $status['paid'] ? 'paid' : 'unpaid' ); ?>">
				<?php echo esc_html( $status['paid'] ? __( 'Paid', 'blueline' ) : __( 'Unpaid', 'blueline' ) ); ?>
			</p>
			<?php if ( $order && is_a( $order, 'WC_Order' ) ) : ?>
				<a class="bl-account-module__link" href="<?php echo esc_url( $order->get_view_order_url() ); ?>">
					<?php esc_html_e( 'View receipt', 'blueline' ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	blueline_account_module_end();
}

/**
 * The URL for one blueline_account_endpoints() slug, resolved through
 * WooCommerce's actual query-var key -- mirrors the same
 * arl-slug-to-query-var flip blueline_account_menu_items() (Task 10,
 * inc/account/endpoints.php) already uses for 'registrations'/'store-credit',
 * whose real WooCommerce query-var keys are 'orders'/'credit'.
 *
 * @param string $slug A key from blueline_account_endpoints().
 * @return string Empty string if WooCommerce is inactive.
 */
function blueline_account_endpoint_url( string $slug ): string {
	if ( ! function_exists( 'wc_get_account_endpoint_url' ) || ! function_exists( 'blueline_account_legacy_redirect_map' ) ) {
		return '';
	}

	$slug_to_query_var = array_flip( blueline_account_legacy_redirect_map() );
	$query_var         = $slug_to_query_var[ $slug ] ?? $slug;

	return wc_get_account_endpoint_url( $query_var );
}

/**
 * The demoted "Account & billing" group: a plain link list to every
 * billing-group endpoint from blueline_account_endpoints() (Task 10),
 * always last on the dashboard. account.css keeps this visually smaller
 * and quieter than the league modules above it -- billing is still
 * reachable in one click, never buried, just no longer the first thing a
 * player sees.
 */
function blueline_account_render_billing_group() {
	if ( ! function_exists( 'blueline_account_endpoints' ) ) {
		return;
	}

	$billing = array_filter(
		blueline_account_endpoints(),
		static fn( $config ) => 'billing' === $config['group']
	);

	if ( empty( $billing ) ) {
		return;
	}

	uasort( $billing, static fn( $a, $b ) => $a['order'] <=> $b['order'] );

	blueline_account_module_start( 'billing', __( 'Account & billing', 'blueline' ) );
	?>
	<ul class="bl-account-billing__list">
		<?php foreach ( $billing as $slug => $config ) : ?>
			<?php $url = blueline_account_endpoint_url( $slug ); ?>
			<?php if ( ! $url ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<li><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $config['label'] ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<?php
	blueline_account_module_end();
}

/**
 * Group wc_get_account_menu_items()'s already league-then-billing-ordered
 * list (Task 10's blueline_account_menu_items() filter) into the shape the
 * Blue Line nav rail renders: each item tagged with its
 * blueline_account_endpoints() group, or null for the two WooCommerce-owned
 * items that map to no ARL slug (dashboard, customer-logout).
 *
 * @param array<string,string> $menu_items wc_get_account_menu_items()'s ordered endpoint => label list.
 * @return array<int, array{endpoint:string, label:string, group:?string}>
 */
function blueline_account_nav_items( array $menu_items ): array {
	$query_to_group = array();

	if ( function_exists( 'blueline_account_endpoints' ) && function_exists( 'blueline_account_legacy_redirect_map' ) ) {
		$slug_to_query_var = array_flip( blueline_account_legacy_redirect_map() );

		foreach ( blueline_account_endpoints() as $slug => $config ) {
			$query_var                    = $slug_to_query_var[ $slug ] ?? $slug;
			$query_to_group[ $query_var ] = $config['group'];
		}
	}

	$items = array();
	foreach ( $menu_items as $endpoint => $label ) {
		$items[] = array(
			'endpoint' => $endpoint,
			'label'    => $label,
			'group'    => $query_to_group[ $endpoint ] ?? null,
		);
	}

	return $items;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

add_action( 'woocommerce_account_my-team_endpoint', 'blueline_account_my_team_endpoint' );
/**
 * Content for the custom /account/my-team/ tab (Task 10 registered the
 * rewrite endpoint; this is the handler that fills it in). Unlinked users
 * get the same claim card as the dashboard rather than a bare "no team"
 * message -- landing here directly should not be a dead end.
 */
function blueline_account_my_team_endpoint() {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id );
		return;
	}

	blueline_account_render_my_team( $player_id, true );
}

add_action( 'woocommerce_account_my-schedule_endpoint', 'blueline_account_my_schedule_endpoint' );
/**
 * Content for the custom /account/my-schedule/ tab. Same claim-card
 * fallback as My Team.
 */
function blueline_account_my_schedule_endpoint() {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id );
		return;
	}

	blueline_account_render_next_game( $player_id );
}
