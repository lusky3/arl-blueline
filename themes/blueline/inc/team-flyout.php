<?php
/**
 * The team flyout: a sitewide, left-edge <details> disclosure of team
 * crests, gently bobbing, one of two positions the team directory can
 * render in (the other being blueline_footer_team_directory(),
 * inc/template-tags.php) -- see docs/superpowers/specs/2026-09-03-blueline-
 * team-flyout-design.md.
 *
 * Deliberately its own file, same rationale inc/floating-next-game.php's
 * own docblock already gives for that file: sitewide chrome consumed from
 * footer.php on every template, not a My Account dashboard module, even
 * though it touches team data blueline_league_menu_team_ids() (inc/
 * sportspress.php) also backs.
 *
 * Needs no JavaScript for open/close: <details>/<summary> is the trigger,
 * the same zero-JS pattern woocommerce/myaccount/navigation.php's own
 * Billing dropdown already establishes in this codebase. assets/src/css/
 * team-flyout.css drives the hover-reveal on top of the native click/tap
 * toggle.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Print the team flyout, or nothing at all.
 *
 * Renders only when the team directory is switched on
 * (`chrome_footer_teams`), its position is set to `flyout`
 * (`chrome_team_directory_position`), and at least one team is configured
 * -- the same "never an empty container" contract every other sitewide
 * chrome renderer in this theme already follows.
 *
 * @return void
 */
function blueline_render_team_flyout(): void {
	if ( ! blueline_section_enabled( 'chrome_footer_teams' ) ) {
		return;
	}

	if ( 'flyout' !== blueline_team_directory_position() ) {
		return;
	}

	$team_ids = blueline_league_menu_team_ids();

	if ( ! $team_ids ) {
		return;
	}

	// Resolved up front, not inside the print loop below: if every
	// configured team turns out to have no permalink, this bails before
	// printing anything at all, rather than leaving a permanent, sitewide
	// "Teams" tab that opens onto an empty panel -- the same "never an
	// empty container" contract the team-id check above already follows,
	// just one link further down the chain.
	$teams = array();

	foreach ( $team_ids as $team_id ) {
		$link = get_permalink( $team_id );

		if ( ! $link ) {
			continue;
		}

		$teams[] = array(
			'id'   => $team_id,
			'name' => blueline_sp_title( $team_id ),
			'link' => $link,
		);
	}

	if ( ! $teams ) {
		return;
	}
	?>
	<details class="bl-team-flyout">
		<summary>
			<span class="bl-team-flyout__label"><?php esc_html_e( 'Teams', 'blueline' ); ?></span>
		</summary>
		<nav class="bl-team-flyout__panel" aria-label="<?php esc_attr_e( 'Teams', 'blueline' ); ?>">
			<ul class="bl-team-flyout__list">
				<?php foreach ( $teams as $team ) : ?>
					<li class="bl-team-flyout__item">
						<a class="bl-team-flyout__link" href="<?php echo esc_url( $team['link'] ); ?>" title="<?php echo esc_attr( $team['name'] ); ?>">
							<span class="bl-team-flyout__crest">
								<?php if ( has_post_thumbnail( $team['id'] ) ) : ?>
									<?php
									/*
									 * Decorative here, same reasoning as
									 * blueline_footer_team_directory()'s own
									 * crest: the team name is the link's real
									 * accessible name via the screen-reader-
									 * text span below, so alt text here would
									 * make it announce twice.
									 */
									echo get_the_post_thumbnail(
										$team['id'],
										'thumbnail',
										array(
											'alt'         => '',
											'loading'     => 'lazy',
											'aria-hidden' => 'true',
										)
									);
									?>
								<?php else : ?>
									<?php blueline_leaf_mark(); ?>
								<?php endif; ?>
							</span>
							<span class="screen-reader-text"><?php echo esc_html( $team['name'] ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</details>
	<?php
}
