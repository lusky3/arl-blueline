<?php
/**
 * The league-first My Account dashboard: rendering helpers consumed by
 * woocommerce/myaccount/dashboard.php, woocommerce/myaccount/navigation.php,
 * and the my-team/my-schedule endpoint handlers wired at the bottom of this
 * file.
 *
 * Inverts Task 9's ported (shop-account) dashboard: league content --
 * next game, team, season stats -- leads; billing is a visually demoted
 * group at the bottom. For the ~16% of current-season players with no
 * sp_user link (Task 16's corrected figure -- 84% ARE linked; the "~88%
 * unlinked" this file used to claim came from the retracted, sticky
 * sp_current_team denominator), the claim card is shown in place of the
 * league modules rather than squeezed in alongside three empty ones. It is
 * now a minority path to serve well, not the default experience.
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
	// $name is already a unique per-module slug (the bl-account-module--{name}
	// modifier above); reused as the heading id so <section> can name itself
	// via aria-labelledby instead of landing on the page's landmark list with
	// no accessible name at all -- confirmed live, 2026-09-04 UX audit.
	$blueline_title_id = 'bl-account-module-title--' . $name;
	?>
	<section class="bl-account-module bl-account-module--<?php echo esc_attr( $name ); ?>" aria-labelledby="<?php echo esc_attr( $blueline_title_id ); ?>">
		<header class="bl-account-module__header">
			<h2 class="bl-account-module__title" id="<?php echo esc_attr( $blueline_title_id ); ?>"><?php echo esc_html( $title ); ?></h2>
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
	blueline_account_module_empty_state_html( esc_html( $message ) );
}

/**
 * Variant of blueline_account_module_empty_state() for the one empty state
 * that needs an inline link rather than plain text -- the claim card's
 * "no candidates" message (live-site review: "Contact the league" rendered
 * as plain, unclickable text, a dead end for exactly the player who most
 * needs to reach the league). Same chrome (leaf mark + text) as the plain
 * version; the difference is entirely in how $html_message is escaped.
 *
 * $html_message must already be safe markup -- built the way
 * blueline_account_render_claim_card() below does it, via wp_kses() over a
 * translatable string with %1$s/%2$s placeholders for caller-supplied
 * esc_url()'d tags (the same idiom inc/homepage-modules.php's gear-guide
 * link uses). wp_kses_post() is the actual escaping boundary here, not a
 * decorative extra -- this function does not accept arbitrary caller input
 * as trusted.
 *
 * @param string $html_message Pre-built, already-escaped markup.
 */
function blueline_account_module_empty_state_html( string $html_message ) {
	?>
	<div class="bl-account-module__empty">
		<?php blueline_leaf_mark( 'bl-account-module__empty-mark' ); ?>
		<p class="bl-account-module__empty-text"><?php echo wp_kses_post( $html_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() IS the escaping boundary; see this function's own docblock. ?></p>
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
 * `.woocommerce-message`/`.woocommerce-error`: at the time this was
 * written, staging (and, presumably, production) carried a site-wide
 * custom-CSS snippet (`#simple-css-output`, visible in every page's
 * `<head>`) with `.woocommerce-message { display: none !important; }` --
 * confirmed live by actually submitting a claim and finding the "you're
 * linked" success notice invisible. Reusing that class would have made
 * the one message a newly-linked user most needs to see silently
 * disappear.
 *
 * That specific rule is gone as of the theme-toggle work's own staging
 * check (2026-08-23): `#simple-css-output` still exists but no longer
 * contains a `.woocommerce-message` rule at all -- it was evidently
 * removed in the Task 16 (2026-08-11) prune this same snippet's own
 * comment describes, or sometime after. `.bl-account-notice` is kept
 * as-is rather than migrated back: it works, and the focus-management
 * reasoning below is a genuine, independent reason to keep a
 * `tabindex="-1"` notice for this specific redirect-driven flow either
 * way (see assets/src/js/account.js, since broadened to also cover
 * WooCommerce's own now-visible-again notices for the same reason).
 *
 * This is a server-rendered notice on a normal (non-AJAX) page load, not
 * a live region injected after the fact, so `role="alert"` alone is not
 * reliably announced by every screen reader on load. `tabindex="-1"`
 * makes it a valid programmatic focus target; assets/src/js/account.js
 * moves focus to it on load, the standard "you just navigated here, read
 * this" pattern, without pretending this is a live-region interruption it
 * is not.
 *
 * Rendered as a `<section>`, not a `<div>` -- tests/NoticeDivGuardTest.php
 * (Task 7's fix rounds, inc/settings/page.php and inc/settings/cache.php)
 * bans any theme-emitted `<div>` whose class contains "notice", "error",
 * "warning", "info" or "updated" as a substring, since a third-party
 * wp-admin plugin on the production install sweeps exactly that pattern
 * from the DOM. This element is front-end only, so that specific plugin
 * was never actually a risk to it, but the guard is deliberately blanket
 * (no per-file exceptions) rather than trusted to be re-scoped correctly
 * by hand every time -- see that test's own docblock. `.bl-account-notice`
 * is a plain class selector in account.css with no tag qualifier, so
 * styling is unaffected.
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
		'not_eligible'        => array( 'error', __( 'Registered players are linked by the league. Contact the league to connect your player profile.', 'blueline' ) ),
	);

	if ( ! isset( $messages[ $status ] ) ) {
		return;
	}

	list( $type, $text ) = $messages[ $status ];
	?>
	<section class="bl-account-notice bl-account-notice--<?php echo esc_attr( $type ); ?>" role="alert" tabindex="-1">
		<?php echo esc_html( $text ); ?>
	</section>
	<?php
}

/**
 * Whether the CURRENT request is viewing the site's configured standings
 * page -- resolved through blueline_resolve_link() (inc/settings/links.php),
 * the same single source of truth every other "which page is this"
 * decision in this theme already goes through (the homepage standings
 * module's "Full standings" link, the header's Standings nav item),
 * rather than a second, independently-drifting page-ID or hardcoded-URL
 * check. No dedicated "is this the standings page" helper existed before
 * this feature needed one.
 *
 * @return bool
 */
function blueline_is_standings_page(): bool {
	if ( ! is_page() ) {
		return false;
	}

	$current = get_permalink();
	$target  = blueline_resolve_link( 'page_standings' );

	if ( ! $current || ! $target ) {
		return false;
	}

	return untrailingslashit( $current ) === untrailingslashit( $target );
}

/**
 * A short, single-line nudge for a signed-in visitor who has not yet
 * claimed a player -- shown on the standings page (blueline_is_standings_page())
 * and on any team's own page (single-team.php), the two places a viewer
 * would most want to see their own team called out but currently can't,
 * because blueline_current_user_team_ids() has nothing to work with until
 * they link a player.
 *
 * Mirrors blueline_account_render_claim_notice()'s own markup/class/focus
 * convention: a `<section role="alert" tabindex="-1">`, never a `<div>`
 * (tests/NoticeDivGuardTest.php bans a theme-emitted notice `<div>`
 * outright), and the bare, unmodified `.bl-account-notice` class so
 * assets/src/js/account.js's existing "move focus to the notice on load"
 * behaviour picks it up with no JS changes of its own. This is deliberately
 * a much shorter nudge than blueline_account_render_claim_card() (Task 11)
 * -- no candidate matching, no confirm form -- since it renders on pages
 * that are not the account dashboard, where that full card would be out of
 * place; it only ever points there.
 *
 * A logged-out visitor gets nothing: there is no account yet to link a
 * player to, and a claim nudge would only send them into a login form with
 * no way back to what they were looking at.
 */
function blueline_render_claim_nudge(): void {
	if ( ! is_user_logged_in() || ! function_exists( 'blueline_current_user_player_id' ) || blueline_current_user_player_id() ) {
		return;
	}

	$account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';

	if ( ! $account_url ) {
		return;
	}

	$message = sprintf(
		wp_kses(
			/* translators: 1: opening <a> tag to My Account, 2: closing </a> tag. */
			__( 'Want to see your own team highlighted? %1$sLink your player in My Account%2$s.', 'blueline' ),
			array( 'a' => array( 'href' => array() ) )
		),
		'<a href="' . esc_url( $account_url ) . '">',
		'</a>'
	);
	?>
	<section class="bl-account-notice" role="alert" tabindex="-1">
		<?php echo wp_kses_post( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() over an already wp_kses()'d string built from a translatable format string with a caller-escaped URL, the same idiom blueline_account_render_claim_card() uses for its "Contact the league" link. ?>
	</section>
	<?php
}

/**
 * Page-specific framing for the claim card's "no candidates" message,
 * appended after the contact-the-league sentence. Live-site review:
 * /account/my-team and /account/my-schedule showed the exact same claim
 * card, with the exact same copy, as the main dashboard -- landing
 * directly on one of those tabs while unlinked gave no hint of what would
 * actually appear there once linked. This is a minimal copy parameter on
 * the existing shared renderer, not a restructuring: candidate matching,
 * the claim form, and every other behaviour stay identical across all
 * four contexts.
 *
 * @param string $context One of 'dashboard' (default, no added hint), 'my-team', 'my-schedule', 'player-profile'.
 * @return string Empty string for 'dashboard' (its own module modules already say what will appear).
 */
function blueline_claim_card_context_hint( string $context ): string {
	switch ( $context ) {
		case 'my-team':
			return __( 'Once you’re linked, your team will appear here.', 'blueline' );
		case 'my-schedule':
			return __( 'Once you’re linked, your schedule will appear here.', 'blueline' );
		case 'player-profile':
			return __( 'Once you’re linked, your player profile will appear here.', 'blueline' );
		default:
			return '';
	}
}

/**
 * The claim card: "Is this you?" plus one-click-confirm candidates, or a
 * "contact the league" message (a real link, see below) when there are
 * none. This is the whole experience for an unlinked user (~16% of
 * current-season players, per Task 16's corrected figure) -- it replaces
 * the next-game/team/season modules entirely rather than sitting alongside
 * three empty versions of them.
 *
 * Candidates come from blueline_find_player_candidates(), which refuses to
 * offer anything for a single-token account name -- see
 * blueline_name_pair_is_specific_enough() in blueline-core's player-link module.
 * "No candidates" is therefore a legitimate, expected outcome here, not a
 * bug to loosen the matcher for.
 *
 * Live-site review: "Contact the league" used to be plain, unclickable
 * text -- a dead end for exactly the player who most needs to reach the
 * league. It is now a real link to the site's Contact Us page, resolved
 * through blueline_contact_url() (inc/template-tags.php ->
 * blueline_resolve_link(), inc/settings/links.php) rather than a hardcoded
 * URL, so it inherits that resolver's own "never link to an unpublished or
 * deleted page" guarantee and stays in sync with whatever the Links tab has
 * configured.
 *
 * @param int    $user_id Current WordPress user ID.
 * @param string $context blueline_claim_card_context_hint()'s context key.
 */
function blueline_account_render_claim_card( int $user_id, string $context = 'dashboard' ) {
	$candidates = function_exists( 'blueline_find_player_candidates' ) ? blueline_find_player_candidates( $user_id ) : array();

	blueline_account_module_start( 'claim', __( 'Is this you?', 'blueline' ) );

	if ( empty( $candidates ) ) {
		$hint    = blueline_claim_card_context_hint( $context );
		$contact = blueline_contact_url();

		$message = sprintf(
			wp_kses(
				/* translators: 1: opening <a> tag to the Contact Us page, 2: closing </a> tag. */
				__( 'We couldn’t find a player profile that matches your account yet. %1$sContact the league%2$s and we’ll get you linked up.', 'blueline' ),
				array( 'a' => array( 'href' => array() ) )
			),
			'<a href="' . esc_url( $contact ) . '">',
			'</a>'
		);

		if ( '' !== $hint ) {
			$message .= ' ' . esc_html( $hint );
		}

		blueline_account_module_empty_state_html( $message );
	} else {
		?>
		<p class="bl-account-claim__intro">
			<?php esc_html_e( 'We found a player profile that looks like you. Confirm it’s yours to unlock your team, schedule, and stats here.', 'blueline' ); ?>
		</p>
		<ul class="bl-account-claim__list">
			<?php foreach ( $candidates as $candidate ) : ?>
				<?php $detail = blueline_format_candidate_detail( $candidate ); ?>
				<li class="bl-account-claim__item">
					<span class="bl-account-claim__identity">
						<span class="bl-account-claim__name"><?php echo esc_html( $candidate['name'] ); ?></span>
						<?php if ( '' !== $detail ) : ?>
							<span class="bl-account-claim__detail"><?php echo esc_html( $detail ); ?></span>
						<?php endif; ?>
					</span>
					<form class="bl-account-claim__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'blueline_claim_player' ); ?>
						<input type="hidden" name="action" value="blueline_claim_player">
						<input type="hidden" name="player_id" value="<?php echo esc_attr( (string) $candidate['player_id'] ); ?>">
						<button
							type="submit"
							class="bl-btn bl-btn--primary bl-account-claim__confirm"
							aria-label="<?php echo esc_attr( blueline_candidate_aria_label( $candidate, $detail ) ); ?>"
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
 * One human-readable disambiguating line for a claim candidate --
 * "Team · Season · #Number", each segment included only when present. Two
 * same-named players are otherwise indistinguishable rows (P4 finding 6);
 * this is the whole difference between a coin flip and an informed choice.
 *
 * @param array{team?:string, season?:string, number?:string} $candidate One row from blueline_find_player_candidates().
 * @return string Empty if none of the three fields are available.
 */
function blueline_format_candidate_detail( array $candidate ): string {
	$number = $candidate['number'] ?? '';

	$parts = array_filter(
		array(
			$candidate['team'] ?? '',
			$candidate['season'] ?? '',
			'' !== $number ? sprintf(
				/* translators: %s: jersey number. */
				__( '#%s', 'blueline' ),
				$number
			) : '',
		),
		static fn( $part ) => '' !== $part
	);

	return implode( ' · ', $parts );
}

/**
 * The confirm button's accessible name. Carries the same disambiguating
 * detail as the visible row: an aria-label that only ever said the player's
 * name would leave a screen-reader user facing the exact "two Mike Browns"
 * ambiguity the visible detail line exists to resolve.
 *
 * @param array{name:string} $candidate One row from blueline_find_player_candidates().
 * @param string             $detail    blueline_format_candidate_detail()'s result for the same candidate.
 * @return string
 */
function blueline_candidate_aria_label( array $candidate, string $detail ): string {
	if ( '' === $detail ) {
		return sprintf(
			/* translators: %s: candidate player name. */
			__( 'Yes, that’s me — %s', 'blueline' ),
			$candidate['name']
		);
	}

	return sprintf(
		/* translators: 1: candidate player name, 2: disambiguating detail (team, season, jersey number). */
		__( 'Yes, that’s me — %1$s, %2$s', 'blueline' ),
		$candidate['name'],
		$detail
	);
}

/**
 * Team roster for $team_id, excluding $exclude_player_id, sorted by name.
 * A lean ids-only query plus one batched title fetch (reusing
 * blueline_get_post_titles() from blueline-core's player-link module)
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
 * A human-readable "W-L-T · N PTS" summary of blueline_get_team_record()'s
 * raw row, or null if the row doesn't carry the win/loss/tie columns this
 * formatter knows how to read (this site's hockey config uses
 * gp/w/l/tie/ot/pts; a differently-configured SportsPress install could
 * use different column keys, in which case this degrades to a points-only
 * summary, or to null if even that is missing -- never a fatal, and never
 * a fabricated "0-0-0").
 *
 * @param array<string,mixed> $row blueline_get_team_record()'s row.
 * @return string|null
 */
function blueline_format_team_record( array $row ): ?string {
	if ( isset( $row['w'], $row['l'], $row['tie'] ) ) {
		$record = sprintf( '%s-%s-%s', $row['w'], $row['l'], $row['tie'] );

		if ( isset( $row['pts'] ) && '' !== $row['pts'] ) {
			$record .= ' · ' . sprintf(
				/* translators: %s: points total. */
				__( '%s PTS', 'blueline' ),
				$row['pts']
			);
		}

		return $record;
	}

	if ( isset( $row['pts'] ) && '' !== $row['pts'] ) {
		return sprintf(
			/* translators: %s: points total. */
			__( '%s PTS', 'blueline' ),
			$row['pts']
		);
	}

	return null;
}

/**
 * The My Team module: crest, division, record, my jersey number, and
 * teammate list (or a count on the compact dashboard view) -- all five
 * fields the brief's Step 6 names for this module.
 *
 * Record is read from SportsPress's own already-computed standings
 * (blueline_get_team_record(), via the team's current-season sp_table),
 * never hand-rolled from event-level sp_results -- see that function's
 * docblock for why a naive per-event tally would be unreliable on this
 * site's sparsely-recorded results. When no current-season table rosters
 * this team, the field still renders -- with an explicit "not available
 * yet" state, matching every other module's empty-state convention --
 * rather than being silently dropped, so a viewer can never confuse "not
 * shown" with "0-0-0."
 *
 * @param int  $player_id sp_player post ID.
 * @param bool $full      True on the dedicated My Team tab (full named
 *                        roster); false on the compact dashboard card
 *                        (teammate count only).
 */
function blueline_account_render_my_team( int $player_id, bool $full = false ) {
	if ( ! blueline_section_enabled( 'account_my_team' ) ) {
		return;
	}

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
		$roster       = blueline_get_team_roster( $team['team_id'], $player_id );
		$record_row   = blueline_get_team_record( $team['team_id'] );
		$record_label = $record_row ? blueline_format_team_record( $record_row ) : null;
		?>
		<div class="bl-account-team">
			<div class="bl-account-team__identity">
				<?php if ( $team['logo_id'] ) : ?>
					<span class="bl-account-team__crest"><?php echo wp_get_attachment_image( $team['logo_id'], 'thumbnail' ); ?></span>
				<?php else : ?>
					<span class="bl-account-team__crest bl-account-team__crest--fallback">
						<?php blueline_leaf_mark( 'bl-account-team__crest-mark' ); ?>
					</span>
				<?php endif; ?>

				<div class="bl-account-team__meta">
					<p class="bl-account-team__name"><?php echo esc_html( $team['name'] ); ?></p>
					<?php if ( $team['division'] ) : ?>
						<p class="bl-account-team__division"><?php echo esc_html( $team['division'] ); ?></p>
					<?php endif; ?>
					<p class="bl-account-team__record">
						<?php if ( $record_label ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: record summary, e.g. "5-6-3 · 13 PTS". */
									__( 'Record: %s', 'blueline' ),
									$record_label
								)
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Record not available yet.', 'blueline' ); ?>
						<?php endif; ?>
					</p>
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
 * `data-bl-next-game-account` carries `event_id:fingerprint`, the same
 * pair shape and the same `blueline:next-game-dismissed` localStorage key
 * assets/src/js/floating-next-game.js uses -- see the schedule-change-
 * notice design spec (docs/superpowers/specs/2026-08-25-blueline-schedule-
 * change-notice-design.md). This card is never dismissible -- it's
 * permanent account content, not ambient chrome, so it renders no dismiss
 * control -- but assets/src/js/account-next-game.js reveals
 * `[data-bl-next-game-account-updated]` (rendered here, hidden, same
 * "always render server-side, JS decides what shows" convention as the
 * floating widget) when this event's own fingerprint differs from what
 * this browser last stored for it. Viewing this card ALSO writes the
 * current pair to that same key, deliberately: seeing "Updated" here
 * counts as having seen the change, so the floating widget elsewhere
 * doesn't keep flagging an already-acknowledged change.
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_account_render_next_game( int $player_id ) {
	if ( ! blueline_section_enabled( 'account_next_game' ) ) {
		return;
	}

	$event = blueline_get_player_next_event( $player_id );

	blueline_account_module_start( 'next-game', __( 'My next game', 'blueline' ) );

	if ( ! $event ) {
		blueline_account_module_empty_state( blueline_settings( 'account_empty_next_game' ) );
	} else {
		$opponent_name = $event['opponent_team_id']
			? blueline_sp_title( $event['opponent_team_id'] )
			: __( 'TBD', 'blueline' );

		/*
		 * The team's whole season, not this one game. A subscription puts every
		 * fixture in the reader's calendar in one action and keeps correcting
		 * itself when a game moves -- a single-event "add" leaves a stale entry
		 * behind on a reschedule, which for a league that moves games is the
		 * worse failure. Falls back to nothing (the button simply does not
		 * render) when a team has no published calendar.
		 */
		$bl_team_id    = blueline_player_current_team_id( $player_id );
		$team_calendar = $bl_team_id ? blueline_team_calendar_urls( $bl_team_id ) : null;
		$venue_url     = ( $event['venue_term_id'] && taxonomy_exists( 'sp_venue' ) ) ? get_term_link( $event['venue_term_id'], 'sp_venue' ) : null;
		?>
		<div class="bl-account-next-game" data-bl-next-game-account="<?php echo esc_attr( $event['event_id'] . ':' . $event['fingerprint'] ); ?>">
			<p class="bl-account-next-game__updated" data-bl-next-game-account-updated hidden>
				<?php esc_html_e( 'Updated since you last checked', 'blueline' ); ?>
			</p>
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
				<?php if ( $team_calendar ) : ?>
					<?php
					/*
					 * Both destinations render, always. assets/src/js/
					 * calendar-links.js marks the one matching the reader's
					 * platform so it comes first and reads as the primary
					 * action -- it never hides the other, because a wrong guess
					 * would then leave someone with no way to subscribe at all,
					 * and a desktop reader legitimately wants whichever their
					 * own calendar is.
					 */
					?>
					<div class="bl-account-next-game__calendar" data-calendar-links>
						<span class="bl-account-next-game__calendar-label">
							<?php esc_html_e( 'Add your season to:', 'blueline' ); ?>
						</span>
						<a class="bl-btn bl-btn--secondary" data-calendar="apple" href="<?php echo esc_url( $team_calendar['webcal'], array( 'webcal', 'http', 'https' ) ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Apple / Outlook', 'blueline' ); ?></span></span>
						</a>
						<a class="bl-btn bl-btn--secondary" data-calendar="google" href="<?php echo esc_url( $team_calendar['google'] ); ?>">
							<span class="bl-skew"><span><?php esc_html_e( 'Google', 'blueline' ); ?></span></span>
						</a>
					</div>
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
	if ( ! blueline_section_enabled( 'account_season_stats' ) ) {
		return;
	}

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
		<p class="bl-account-stats__hint"><?php echo esc_html( blueline_settings( 'account_empty_stats' ) ); ?></p>
	<?php endif; ?>
	<?php
	blueline_account_module_end();
}

/**
 * The "no online order on record" message for the My Registration module,
 * chosen between two framings of the exact same null result from
 * blueline_get_user_registration_status() -- that reader's own logic is
 * unchanged; only the copy differs.
 *
 * Live-site review: a player who registered by an offline/manual method
 * (e.g. e-transfer handled outside WooCommerce, a league-run signup) but IS
 * currently rostered onto a team has clearly, verifiably registered --
 * blueline_get_user_registration_status() simply has no ONLINE order to
 * show them, because they paid a different way, not because anything
 * failed. The plain "No registration found" copy reads, to that player,
 * like their payment may not have gone through -- needless "did it work?"
 * anxiety for someone the league's own roster data already confirms is
 * signed up. A player with no current team gets the original, unambiguous
 * copy: for them "no registration found" really is the honest summary, and
 * softening it would risk masking a genuine gap.
 *
 * @param bool $has_current_team Whether the linked player currently has a team.
 * @return string
 */
function blueline_registration_empty_message( bool $has_current_team ): string {
	if ( $has_current_team ) {
		return __( 'No online order found for the current season. If you registered a different way, you’re all set — contact us if anything looks wrong.', 'blueline' );
	}

	return __( 'No registration found for the current season yet.', 'blueline' );
}

/**
 * The My Registration module: season, paid/unpaid, and a receipt link to
 * that one order's own view-order page (not the full order-history list --
 * that already lives at the demoted "My Registrations" billing endpoint
 * below).
 *
 * @param int      $user_id   WordPress user ID.
 * @param int|null $player_id The user's linked sp_player ID, if any -- used
 *                             only to soften the empty-state copy (see
 *                             blueline_registration_empty_message()) when a
 *                             rostered player simply has no ONLINE order on
 *                             record.
 */
function blueline_account_render_registration( int $user_id, ?int $player_id = null ) {
	if ( ! blueline_section_enabled( 'account_registration' ) ) {
		return;
	}

	$status = blueline_get_user_registration_status( $user_id );

	blueline_account_module_start( 'registration', __( 'My registration', 'blueline' ) );

	if ( ! $status ) {
		$has_current_team = $player_id && blueline_player_current_team_id( $player_id ) > 0;

		blueline_account_module_empty_state( blueline_registration_empty_message( $has_current_team ) );
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
 * Group wc_get_account_menu_items()'s already league-then-billing-ordered
 * list (the plugin's blueline_account_menu_items() filter) into the shape the
 * Blue Line nav rail renders: each item tagged with its
 * blueline_account_endpoints() group, or null for the two WooCommerce-owned
 * items that map to no ARL slug (dashboard, customer-logout). Both helpers
 * live in the Blueline Core plugin; without it every group is null.
 *
 * @param array<string,string> $menu_items wc_get_account_menu_items()'s ordered endpoint => label list.
 * @return array<int, array{endpoint:string, label:string, group:?string}>
 */
function blueline_account_nav_items( array $menu_items ): array {
	$query_to_group = array();

	if ( function_exists( 'blueline_account_endpoints' ) && function_exists( 'blueline_account_slug_query_var' ) ) {
		foreach ( blueline_account_endpoints() as $slug => $config ) {
			$query_to_group[ blueline_account_slug_query_var( $slug ) ] = $config['group'];
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
		blueline_account_render_claim_card( $user_id, 'my-team' );
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
		blueline_account_render_claim_card( $user_id, 'my-schedule' );
		return;
	}

	blueline_account_render_next_game( $player_id );
}
