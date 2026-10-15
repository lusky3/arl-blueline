<?php
/**
 * Homepage: the "Never played? Perfect." new_here module. Its three Q&A
 * widget areas, their default copy and the closing button.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The three "Never played? Perfect." Q&A slots, keyed by widget-area
 * suffix (`bl-homepage-new-here-q{n}`) -- the single source of truth for
 * blueline_homepage_new_here_widgets_init() (registration) and
 * blueline_homepage_module_new_here() (render loop), so the two can never
 * drift out of step on which slots exist.
 *
 * @return array<int,string> n => a short label naming the slot's current
 *                           default question, for the widget-area admin
 *                           screen only.
 */
function blueline_homepage_new_here_qa_slots(): array {
	return array(
		1 => __( 'Question 1 — currently "Will I be the worst one out there?"', 'blueline' ),
		2 => __( 'Question 2 — currently "What gear do I actually need?"', 'blueline' ),
		3 => __( 'Question 3 — currently "What if I can\'t really skate yet?"', 'blueline' ),
	);
}

add_action( 'widgets_init', 'blueline_homepage_new_here_widgets_init' );
/**
 * Register one widget area per new_here Q&A slot (see
 * blueline_homepage_new_here_qa_slots()), replacing the module's original
 * single combined area.
 *
 * P1 finding 5: "Never played? Perfect." is PRODUCT.md's tonal north star,
 * because the site's whole reason to exist is convincing the nervous first-timer
 * persona to register, and this content was three short bullets, hardcoded
 * as English strings in PHP, with no way for a league volunteer (who runs
 * this site day to day, and is not a developer) to update it without a code
 * deploy. A single combined widget area first solved that, but reported
 * live as an all-or-nothing trap: adding any widget to it suppressed the
 * theme's ENTIRE default Q&A block, not just the one question the
 * volunteer meant to change ("I lose all the content of that section").
 * One widget area per question fixes that -- each falls back to its own
 * specific default independently, so overriding one leaves the other two
 * exactly as they were.
 */
function blueline_homepage_new_here_widgets_init() {
	foreach ( blueline_homepage_new_here_qa_slots() as $n => $label ) {
		register_sidebar(
			array(
				'name'          => sprintf(
					/* translators: %s: e.g. 'Question 1 — currently "Will I be the worst one out there?"'. */
					__( 'Homepage — Never played? Perfect. — %s', 'blueline' ),
					$label
				),
				'id'            => "bl-homepage-new-here-q{$n}",
				'description'   => __( 'Leave this widget area empty to use the theme\'s own default question and answer.', 'blueline' ),
				'before_widget' => '<div class="bl-new-here__qa-item">',
				'after_widget'  => '</div>',
				'before_title'  => '<h3 class="bl-new-here__qa-item-title">',
				'after_title'   => '</h3>',
			)
		);
	}
}

/**
 * The intro line above the three Q&A slots. Always the theme's own copy --
 * out of scope for the per-question widget areas above, which cover only
 * the three questions themselves.
 */
function blueline_homepage_new_here_intro() {
	?>
	<p class="bl-new-here__intro">
		<?php esc_html_e( 'This league runs the full range, from players who\'ve never laced up before this season to players who\'ve been doing this for years. Wherever you fall, you\'re not the only one.', 'blueline' ); ?>
	</p>
	<?php
}

/**
 * Q&A slot 1's own default, used only when 'bl-homepage-new-here-q1' has no
 * widgets. Answers the first of the three questions PRODUCT.md names as
 * what the nervous-beginner persona actually asks: "will I be the worst
 * one there".
 */
function blueline_homepage_new_here_q1_default() {
	?>
	<div class="bl-new-here__qa-item">
		<dt><?php esc_html_e( 'Will I be the worst one out there?', 'blueline' ); ?></dt>
		<dd><?php esc_html_e( 'Almost certainly not, and it wouldn\'t matter if you were. Teams are built to be even across every skill level in the room, not stacked. No tryouts, no cuts.', 'blueline' ); ?></dd>
	</div>
	<?php
}

/**
 * Q&A slot 2's own default, used only when 'bl-homepage-new-here-q2' has no
 * widgets. Answers "what gear do I need".
 */
function blueline_homepage_new_here_q2_default() {
	?>
	<div class="bl-new-here__qa-item">
		<dt><?php esc_html_e( 'What gear do I actually need?', 'blueline' ); ?></dt>
		<dd>
			<?php
			printf(
				wp_kses(
					/* translators: 1: opening <a> tag to the equipment guide, 2: closing </a> tag. */
					__( 'Less than you\'d think, and you can rent most of it nearby before buying a single thing. %1$sSee the gear guide%2$s.', 'blueline' ),
					array( 'a' => array( 'href' => array() ) )
				),
				'<a href="' . esc_url( blueline_resolve_link( 'page_equipment' ) ) . '">',
				'</a>'
			);
			?>
		</dd>
	</div>
	<?php
}

/**
 * Q&A slot 3's own default, used only when 'bl-homepage-new-here-q3' has no
 * widgets. Answers "what if I can't skate".
 */
function blueline_homepage_new_here_q3_default() {
	?>
	<div class="bl-new-here__qa-item">
		<dt><?php esc_html_e( 'What if I can\'t really skate yet?', 'blueline' ); ?></dt>
		<dd><?php esc_html_e( 'You don\'t need to have played hockey before, but you\'ll want to be comfortable on skates first. From there, divisions are grouped by comfort level, from rooms still finding their stride to rooms that have been doing this for years, so you\'re never out of your depth.', 'blueline' ); ?></dd>
	</div>
	<?php
}

/**
 * Render one Q&A slot: a league volunteer's own widget content if
 * $sidebar_id has any, else that slot's own default -- never the other
 * two slots' content, and never nothing.
 *
 * @param string   $sidebar_id     A blueline_homepage_new_here_qa_slots()
 *                                 sidebar id.
 * @param callable $default_render Renders this slot's own default markup
 *                                 (one of the blueline_homepage_new_here_q{n}_default()
 *                                 functions above) when $sidebar_id is empty.
 * @return void
 */
function blueline_homepage_new_here_qa_slot( string $sidebar_id, callable $default_render ): void {
	if ( is_active_sidebar( $sidebar_id ) ) {
		dynamic_sidebar( $sidebar_id );
	} else {
		$default_render();
	}
}

/**
 * The new_here module: reassurance copy for a first-time player deciding
 * whether to register. Never empty -- the intro line is always the
 * theme's own copy, and each of the three Q&A slots independently falls
 * back to its own default (blueline_homepage_new_here_qa_slot()) when a
 * league volunteer hasn't configured that slot's widget area.
 */
function blueline_homepage_module_new_here() {
	blueline_homepage_module_start( 'new_here', blueline_settings( 'module_new_here_heading' ), blueline_resolve_link( 'page_faqs' ), blueline_settings( 'module_new_here_cta' ) );

	blueline_leaf_mark( 'bl-new-here__watermark' );

	blueline_homepage_new_here_intro();
	?>
	<dl class="bl-new-here__qa">
		<?php
		blueline_homepage_new_here_qa_slot( 'bl-homepage-new-here-q1', 'blueline_homepage_new_here_q1_default' );
		blueline_homepage_new_here_qa_slot( 'bl-homepage-new-here-q2', 'blueline_homepage_new_here_q2_default' );
		blueline_homepage_new_here_qa_slot( 'bl-homepage-new-here-q3', 'blueline_homepage_new_here_q3_default' );
		?>
	</dl>
	<?php $cta = blueline_homepage_new_here_cta(); ?>
	<p class="bl-new-here__cta">
		<a class="bl-btn bl-btn--<?php echo esc_attr( $cta['variant'] ); ?>" href="<?php echo esc_url( $cta['url'] ); ?>">
			<span class="bl-skew"><span><?php echo esc_html( $cta['label'] ); ?></span></span>
		</a>
	</p>
	<?php
	blueline_homepage_module_end();
}

/**
 * The "Never played? Perfect." module's closing button: the one step a
 * newcomer can take today. That module is where a nervous first-timer decides,
 * and it used to end on a link to more reading. Registration while it is open
 * (the same live check as the header's Register button), the waitlist while
 * only that is on offer, otherwise the off-season hero's mailing-list offer.
 *
 * @param bool|null $register_open Whether a paid registration is on offer;
 *                                 read live when null.
 * @param bool|null $waitlist_open Whether a $0 waitlist is on offer; read
 *                                 from the season state when null.
 * @return array{label: string, url: string, variant: string}
 */
function blueline_homepage_new_here_cta( ?bool $register_open = null, ?bool $waitlist_open = null ): array {
	if ( null === $register_open ) {
		$register_open = blueline_header_cta()['is_register'];
	}

	if ( $register_open ) {
		return array(
			'label'   => __( 'Register now', 'blueline' ),
			'url'     => blueline_resolve_link( 'page_register' ),
			'variant' => 'primary',
		);
	}

	if ( null === $waitlist_open ) {
		$waitlist_open = ! empty( blueline_season_state_data()['is_waitlist_open'] );
	}

	if ( $waitlist_open ) {
		return array(
			'label'   => __( 'Join the waitlist', 'blueline' ),
			'url'     => blueline_resolve_link( 'page_register' ),
			'variant' => 'primary',
		);
	}

	return array(
		'label'   => blueline_settings( 'hero_offseason_cta' ),
		'url'     => blueline_contact_url(),
		'variant' => 'secondary',
	);
}
