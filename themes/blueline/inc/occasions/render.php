<?php
/**
 * Occasions: front-end rendering. The motif SVGs, the announcement-severity
 * clamp, the per-request accent style, the header motif and line, the
 * corner scenes and the ambient effects.
 *
 * Loaded by inc/occasions.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The maple leaf motif (Canada Day). Purely decorative: aria-hidden, no
 * text alternative needed, matching the existing house style
 * (inc/template-tags.php's blueline_leaf_mark(), inc/homepage-modules.php's
 * blueline_render_faceoff_rings()): `stroke`/`fill="currentColor"` so CSS
 * (specifically --bl-occasion-accent, once a template applies it as the
 * motif's colour) controls the rendered colour, not this markup.
 *
 * Redesigned (first-round feedback, indirectly): the original path was a
 * symmetric 9-point starburst with no stem -- fine as a tiny header icon
 * or falling confetti speck, where nobody looks closely, but it reads as
 * a firework, not a leaf, at the 64px size occasions.css's new
 * .bl-occasion-effects__accent-leaf renders it at. This one has three
 * asymmetric spike tiers per side (growing toward the base, the real
 * maple leaf's own silhouette) plus a short stem, so it still reads as
 * "leaf" blown up large, not just at icon size.
 *
 * @return void
 */
function blueline_render_occasion_motif_maple_leaf(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--maple-leaf" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 3 28 10 36 8 30 16 42 18 33 24 40 30 26 32 25 40 23 40 22 32 8 30 15 24 6 18 18 16 12 8 20 10Z"/><path fill="currentColor" d="M23 40 25 40 25.6 47 22.4 47Z"/></svg>
	<?php
}

/**
 * The poppy motif (Remembrance Day). NOT aria-hidden: design spec
 * §5/§7.7 requires it carry a real accessible name, so it gets a
 * `<title>` (announced by assistive technology as the element's
 * accessible name for a `role="img"` SVG) instead of the `aria-hidden`
 * every other motif here uses.
 *
 * Five identical petals, one `<path>` rotated 72° around the centre five
 * times, is what actually reads as a poppy at a glance -- the original
 * two-path version (first-round feedback: "the poppies do not look like
 * poppies") had no petal shape at all, just two ambiguous blobs.
 *
 * @return void
 */
function blueline_render_occasion_motif_poppy(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--poppy" viewBox="0 0 48 48" role="img" focusable="false"><title><?php esc_html_e( 'Remembrance poppy', 'blueline' ); ?></title><path fill="currentColor" d="M24 24C14 20 14 6 24 4C34 6 34 20 24 24Z"/><path fill="currentColor" d="M24 24C14 20 14 6 24 4C34 6 34 20 24 24Z" transform="rotate(72 24 24)"/><path fill="currentColor" d="M24 24C14 20 14 6 24 4C34 6 34 20 24 24Z" transform="rotate(144 24 24)"/><path fill="currentColor" d="M24 24C14 20 14 6 24 4C34 6 34 20 24 24Z" transform="rotate(216 24 24)"/><path fill="currentColor" d="M24 24C14 20 14 6 24 4C34 6 34 20 24 24Z" transform="rotate(288 24 24)"/><circle cx="24" cy="24" r="5" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/></svg>
	<?php
}

/**
 * The snowflake motif (Christmas). Purely decorative: aria-hidden, no
 * text alternative needed.
 *
 * @return void
 */
function blueline_render_occasion_motif_snowflake(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--snowflake" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="24" y1="4" x2="24" y2="44"></line><line x1="4" y1="24" x2="44" y2="24"></line><line x1="10" y1="10" x2="38" y2="38"></line><line x1="38" y1="10" x2="10" y2="38"></line></g></svg>
	<?php
}

/**
 * The sparkle motif (New Year). Purely decorative: aria-hidden, no text
 * alternative needed.
 *
 * @return void
 */
function blueline_render_occasion_motif_sparkle(): void {
	?>
	<svg class="bl-occasion-motif bl-occasion-motif--sparkle" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 2c1 8 4 15 9 20 5 5 12 8 20 9-8 1-15 4-20 9-5 5-8 12-9 20-1-8-4-15-9-20-5-5-12-8-20-9 8-1 15-4 20-9 5-5 8-12 9-20Z"/></svg>
	<?php
}

/**
 * Render the named motif, or nothing for 'none' or an unrecognised name
; never fatal, matching every other read path in this file.
 *
 * @param string $motif One of blueline_occasion_motifs().
 * @return void
 */
function blueline_render_occasion_motif( string $motif ): void {
	switch ( $motif ) {
		case 'maple-leaf':
			blueline_render_occasion_motif_maple_leaf();
			break;
		case 'poppy':
			blueline_render_occasion_motif_poppy();
			break;
		case 'snowflake':
			blueline_render_occasion_motif_snowflake();
			break;
		case 'sparkle':
			blueline_render_occasion_motif_sparkle();
			break;
		case 'none':
		default:
			break;
	}
}

/**
 * Clamp the announcement's severity from 'urgent' down to 'info' while a
 * commemorative occasion is the currently resolved-active one, per design
 * spec §5/§7.6. Hooked onto the `blueline_announcement_severity` filter
 * inc/announcement.php's blueline_announcement_severity() applies its
 * return value through.
 *
 * @param string $severity One of BLUELINE_ANNOUNCEMENT_SEVERITIES.
 * @return string
 */
function blueline_occasion_suppress_urgent_announcement( string $severity ): string {
	if ( 'urgent' !== $severity ) {
		return $severity;
	}

	$active = blueline_resolve_active_occasion();

	if ( null === $active || 'commemorative' !== ( $active['type'] ?? '' ) ) {
		return $severity;
	}

	return 'info';
}
add_filter( 'blueline_announcement_severity', 'blueline_occasion_suppress_urgent_announcement' );

/**
 * Emit `--bl-occasion-accent`'s real, per-request resolved value as an
 * inline override on the `blueline-tokens` handle (inc/enqueue.php) -- the
 * same handle style.css's own static `--bl-occasion-accent: var(--bl-ice);`
 * default lives on. This is the piece that was missing entirely: design
 * spec §7.2 names this token's consumers ("the CTA ribbon fill, the
 * signature band, the motif" -- header.css's `.bl-btn--primary .bl-skew`,
 * woocommerce.css's `.single_add_to_cart_button::before`, base.css's
 * `.bl-band`, footer.css's `.bl-footer::before`, homepage.css's
 * `.bl-module + .bl-module::before`), but nothing ever varied the token's
 * VALUE per request before this.
 *
 * Emits nothing when no occasion is active: the static default already
 * covers that case, so there is nothing to override.
 *
 * Priority 20 on the same `wp_enqueue_scripts` hook
 * blueline_enqueue_assets() (inc/enqueue.php, default priority 10) uses,
 * so the `blueline-tokens` handle it enqueues already exists by the time
 * this runs -- wp_add_inline_style() on an unregistered handle is silently
 * a no-op.
 *
 * @return void
 */
function blueline_occasion_front_end_styles(): void {
	$occasion = blueline_resolve_active_occasion();

	if ( null === $occasion ) {
		return;
	}

	$accent = $occasion['resolved_accent'] ?? '';

	// Defensive, matching this file's own reasoning elsewhere (e.g. the
	// serializer note in the original design decisions): resolved_accent
	// is already blueline_sanitize_hex_color()'d by the resolver, but this
	// value is about to reach raw, unescaped CSS text with no output
	// escaping mechanism suited to that context -- assert its shape
	// directly rather than trust it.
	if ( ! preg_match( '/^#[0-9a-fA-F]{6}$/', $accent ) ) {
		return;
	}

	wp_add_inline_style( 'blueline-tokens', ':root{--bl-occasion-accent:' . $accent . ';}' );
}
add_action( 'wp_enqueue_scripts', 'blueline_occasion_front_end_styles', 20 );

/**
 * Render the active occasion's motif, wrapped for placement next to the
 * header's primary CTA (inc/template-tags.php's blueline_site_header()) --
 * design spec §7.2's third named `--bl-occasion-accent` consumer. Renders
 * nothing when no occasion is active or its motif is 'none': the wrapper
 * itself is only ever printed when there is something to put in it, so an
 * empty `.bl-header__occasion-motif` never sits in the markup unused.
 *
 * @return void
 */
function blueline_render_header_occasion_motif(): void {
	$occasion = blueline_resolve_active_occasion();

	if ( null === $occasion || 'none' === ( $occasion['motif'] ?? 'none' ) ) {
		return;
	}
	?>
	<span class="bl-header__occasion-motif">
		<?php blueline_render_occasion_motif( $occasion['motif'] ); ?>
	</span>
	<?php
}

/**
 * The active occasion's `line`, or '' if none is active or it has none set.
 * Reads straight off blueline_resolve_active_occasion() -- no separate
 * validation, since blueline_sanitize_occasions() already rejects a `line`
 * containing a placeholder token on write (design spec §5/§7.1: "no
 * placeholders permitted").
 *
 * @return string
 */
function blueline_active_occasion_line(): string {
	$occasion = blueline_resolve_active_occasion();

	return null === $occasion ? '' : (string) ( $occasion['line'] ?? '' );
}

/**
 * Presents corner scene (Christmas). Three flat, geometric gift boxes at
 * staggered heights, each a fixed brand colour (never `currentColor`,
 * unlike the small motif icons above): the whole point is three visually
 * DISTINCT boxes, which a single inherited colour would collapse into one.
 * Matches blueline_render_occasion_motif_poppy()'s own precedent for a
 * fixed secondary colour within an otherwise `currentColor` icon set
 * (its ink seed dot) -- here every fill is fixed, since nothing in this
 * scene should track the occasion accent or the surrounding text colour.
 *
 * Deliberately plain rectangles and triangles, not detailed line art: the
 * existing motif icons (snowflake, sparkle) are themselves simple
 * geometric shapes, and a corner illustration attempting more "cuteness"
 * than that is far likelier to read as amateurish than charming.
 *
 * @return void
 */
function blueline_render_occasion_scene_presents(): void {
	?>
	<svg class="bl-occasion-scene bl-occasion-scene--presents" viewBox="0 0 140 100" aria-hidden="true" focusable="false">
		<rect x="4" y="46" width="42" height="50" fill="<?php echo esc_attr( BLUELINE_TOKEN_ICE ); ?>"/>
		<rect x="21" y="46" width="8" height="50" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<rect x="4" y="62" width="42" height="7" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<path d="M25 46 18 34h14z" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>

		<rect x="52" y="66" width="34" height="30" fill="<?php echo esc_attr( BLUELINE_TOKEN_ACCENT_TEXT ); ?>"/>
		<rect x="66" y="66" width="6" height="30" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>
		<rect x="52" y="78" width="34" height="6" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>
		<path d="M69 66 63 56h12z" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>

		<rect x="92" y="54" width="44" height="42" fill="<?php echo esc_attr( BLUELINE_TOKEN_DANGER ); ?>"/>
		<rect x="110" y="54" width="8" height="42" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>
		<rect x="92" y="70" width="44" height="7" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>
		<path d="M114 54 106 42h16z" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>"/>
	</svg>
	<?php
}

/**
 * Snowman corner scene (Christmas). Three stacked circles (the universal
 * snowman silhouette), a flat top hat, dot eyes/buttons and a triangular
 * carrot nose -- the same "plain geometric shapes over detailed line art"
 * call as blueline_render_occasion_scene_presents() above, for the same
 * reason.
 *
 * @return void
 */
function blueline_render_occasion_scene_snowman(): void {
	?>
	<svg class="bl-occasion-scene bl-occasion-scene--snowman" viewBox="0 0 100 150" aria-hidden="true" focusable="false">
		<circle cx="50" cy="118" r="30" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>" stroke="<?php echo esc_attr( BLUELINE_TOKEN_INK_MID ); ?>" stroke-width="2"/>
		<circle cx="50" cy="72" r="22" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>" stroke="<?php echo esc_attr( BLUELINE_TOKEN_INK_MID ); ?>" stroke-width="2"/>
		<circle cx="50" cy="36" r="15" fill="<?php echo esc_attr( BLUELINE_TOKEN_PAPER ); ?>" stroke="<?php echo esc_attr( BLUELINE_TOKEN_INK_MID ); ?>" stroke-width="2"/>

		<rect x="33" y="24" width="34" height="5" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<rect x="39" y="8" width="22" height="17" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>

		<circle cx="44" cy="34" r="1.6" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<circle cx="56" cy="34" r="1.6" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<path d="M50 38 62 41 50 43z" fill="<?php echo esc_attr( BLUELINE_TOKEN_WARNING ); ?>"/>

		<circle cx="50" cy="60" r="2" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<circle cx="50" cy="72" r="2" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>
		<circle cx="50" cy="84" r="2" fill="<?php echo esc_attr( BLUELINE_TOKEN_INK ); ?>"/>

		<path d="M28 74 8 64" stroke="<?php echo esc_attr( BLUELINE_TOKEN_INK_MID ); ?>" stroke-width="3" stroke-linecap="round" fill="none"/>
		<path d="M72 74 92 64" stroke="<?php echo esc_attr( BLUELINE_TOKEN_INK_MID ); ?>" stroke-width="3" stroke-linecap="round" fill="none"/>
	</svg>
	<?php
}

/**
 * Poppy-field corner scene (Remembrance Day) -- first-round feedback:
 * this occasion "needs more decorations on the corners" too, matching
 * Christmas' presents/snowman pair above. Three simplified 3-petal
 * blooms on stems at staggered heights, the same scale of detail as the
 * presents/snowman scenes (never the full 5-petal accessible poppy
 * blueline_render_occasion_motif_poppy() renders elsewhere -- a corner
 * silhouette does not need that level of fidelity).
 *
 * Every colour here is fixed (BLUELINE_TOKEN_DANGER for the blooms, a
 * literal muted green for the stems -- this theme has no green brand
 * token, so this follows blueline_render_occasion_scene_presents()'s own
 * precedent of a fixed literal for a colour nothing else needs), never
 * `currentColor` or the occasion accent: same reasoning as that
 * function's own docblock -- a corner illustration should not shift
 * colour just because an admin (or blueline_occasion_themed_accent_
 * default() above) picked a particular red.
 *
 * Rendered into BOTH corners by blueline_render_occasion_effects() below
 * (unlike Christmas' two different scenes) since one poppy-field
 * silhouette reads correctly mirrored, and this occasion does not need a
 * second, different illustration to feel complete.
 *
 * @return void
 */
function blueline_render_occasion_scene_poppy_field(): void {
	$stem  = '#4a6741';
	$bloom = esc_attr( BLUELINE_TOKEN_DANGER );
	$ink   = esc_attr( BLUELINE_TOKEN_INK );
	?>
	<svg class="bl-occasion-scene bl-occasion-scene--poppy-field" viewBox="0 0 120 110" aria-hidden="true" focusable="false">
		<path d="M20 110 18 64" stroke="<?php echo esc_attr( $stem ); ?>" stroke-width="3" stroke-linecap="round" fill="none"/>
		<path d="M52 110 48 40" stroke="<?php echo esc_attr( $stem ); ?>" stroke-width="3" stroke-linecap="round" fill="none"/>
		<path d="M84 110 88 56" stroke="<?php echo esc_attr( $stem ); ?>" stroke-width="3" stroke-linecap="round" fill="none"/>

		<g transform="translate(18 60)">
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(120)"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(240)"/>
			<circle r="2.5" fill="<?php echo esc_attr( $ink ); ?>"/>
		</g>
		<g transform="translate(48 38) scale(1.3)">
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(120)"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(240)"/>
			<circle r="2.5" fill="<?php echo esc_attr( $ink ); ?>"/>
		</g>
		<g transform="translate(88 54) scale(0.9)">
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(120)"/>
			<path d="M0 0C-6 -3 -7 -11 0 -13C7 -11 6 -3 0 0Z" fill="<?php echo esc_attr( $bloom ); ?>" transform="rotate(240)"/>
			<circle r="2.5" fill="<?php echo esc_attr( $ink ); ?>"/>
		</g>
	</svg>
	<?php
}

/**
 * The year New Year's fireworks "write" across the sky
 * (.bl-occasion-effects__year, occasions.css). During December this is
 * next year (the one about to arrive); on/after January 1st (New Year's
 * own window continues through 01-02) it is the current year, since it
 * has already arrived. Always a 4-digit string.
 *
 * @param int|null $now_override Unix timestamp; defaults to the current
 *                                time. Tests pass this for the same
 *                                reason every other *_override param in
 *                                this file does.
 * @return string A 4-digit year, e.g. '2027'.
 */
function blueline_occasion_new_year_display_year( ?int $now_override = null ): string {
	$now  = $now_override ?? time();
	$date = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );

	return (string) ( '12' === $date->format( 'm' ) ? ( (int) $date->format( 'Y' ) + 1 ) : (int) $date->format( 'Y' ) );
}

/**
 * How many particles/bursts each motif's ambient effect prints, keyed by
 * motif (these counts are this feature's own tuning, arrived at against
 * a guardrail: 15-25
 * animated elements total is the ceiling before density risks jank on
 * low-end mobile, and Remembrance Day deliberately sits far under that
 * ceiling regardless -- restraint reads as respect for a commemorative
 * occasion, density does not.
 *
 * @param string $motif One of blueline_occasion_motifs().
 * @return int
 */
function blueline_occasion_effect_particle_count( string $motif ): int {
	switch ( $motif ) {
		case 'snowflake':
			return 18;
		case 'poppy':
			return 8;
		case 'maple-leaf':
			return 5;
		default:
			return 0;
	}
}

/**
 * Render the active occasion's ambient, sitewide visual effect -- falling
 * snow plus a presents/snowman corner scene and a frost/snowdrift edge
 * (pure CSS, occasions.css) for Christmas; falling poppies plus a
 * mirrored poppy-field corner scene for Remembrance Day; a large "hero"
 * firework plus a rolling show of smaller white/gold bursts and the
 * coming year written across the sky for New Year; the same hero+small
 * bursts (red/white/gold) plus falling maple-leaf confetti and two
 * larger, mostly-still accent leaves for Canada Day. Printed from
 * footer.php alongside this theme's other persistent overlay chrome (the
 * team flyout, the floating next-game widget) -- same "prints nothing
 * unless there is something real to show" contract every renderer in that
 * group already follows.
 *
 * A SEPARATE layer from blueline_render_header_occasion_motif() above: that
 * one places a single small icon next to the header CTA; this is ambient
 * scene-setting across the whole viewport, in its own fixed, aria-hidden,
 * pointer-events:none wrapper (occasions.css) so it can never sit above or
 * block interaction with real content regardless of where on the page it
 * is fixed.
 *
 * @return void
 */
function blueline_render_occasion_effects(): void {
	$occasion = blueline_resolve_active_occasion();

	if ( null === $occasion ) {
		return;
	}

	$motif = $occasion['motif'] ?? 'none';

	// 'none' and any motif this function does not yet have an effect for
	// (there are none today -- every real motif in blueline_occasion_motifs()
	// has a case below) both fall through to printing nothing, matching
	// blueline_render_occasion_motif()'s own "no fatal, just no output" rule.
	if ( ! in_array( $motif, array( 'snowflake', 'poppy', 'sparkle', 'maple-leaf' ), true ) ) {
		return;
	}

	$particle_count = blueline_occasion_effect_particle_count( $motif );
	// Six scattered ambient bursts (occasions.css's own nth-child(6n+N)
	// lanes) for both firework occasions -- a "large one, then smaller
	// ones" show needs more than the original four to read as a rolling
	// display rather than four fixed points.
	$burst_count = 6;
	?>
	<div class="bl-occasion-effects bl-occasion-effects--<?php echo esc_attr( $motif ); ?>" aria-hidden="true">
		<?php if ( 'snowflake' === $motif ) : ?>
			<?php for ( $i = 0; $i < $particle_count; $i++ ) : ?>
				<span class="bl-occasion-effects__particle"></span>
			<?php endfor; ?>
			<div class="bl-occasion-effects__corner bl-occasion-effects__corner--left">
				<?php blueline_render_occasion_scene_presents(); ?>
			</div>
			<div class="bl-occasion-effects__corner bl-occasion-effects__corner--right">
				<?php blueline_render_occasion_scene_snowman(); ?>
			</div>
		<?php elseif ( 'poppy' === $motif ) : ?>
			<?php for ( $i = 0; $i < $particle_count; $i++ ) : ?>
				<span class="bl-occasion-effects__particle"><?php blueline_render_occasion_motif_poppy(); ?></span>
			<?php endfor; ?>
			<div class="bl-occasion-effects__corner bl-occasion-effects__corner--left">
				<?php blueline_render_occasion_scene_poppy_field(); ?>
			</div>
			<div class="bl-occasion-effects__corner bl-occasion-effects__corner--right">
				<?php blueline_render_occasion_scene_poppy_field(); ?>
			</div>
		<?php elseif ( 'sparkle' === $motif ) : ?>
			<span class="bl-occasion-effects__hero-burst"></span>
			<?php for ( $i = 0; $i < $burst_count; $i++ ) : ?>
				<span class="bl-occasion-effects__burst"></span>
			<?php endfor; ?>
			<div class="bl-occasion-effects__year">
				<?php foreach ( str_split( blueline_occasion_new_year_display_year() ) as $digit ) : ?>
					<span class="bl-occasion-effects__year-digit"><?php echo esc_html( $digit ); ?></span>
				<?php endforeach; ?>
			</div>
		<?php elseif ( 'maple-leaf' === $motif ) : ?>
			<span class="bl-occasion-effects__hero-burst"></span>
			<?php for ( $i = 0; $i < $burst_count; $i++ ) : ?>
				<span class="bl-occasion-effects__burst"></span>
			<?php endfor; ?>
			<?php for ( $i = 0; $i < $particle_count; $i++ ) : ?>
				<span class="bl-occasion-effects__particle"><?php blueline_render_occasion_motif_maple_leaf(); ?></span>
			<?php endfor; ?>
			<span class="bl-occasion-effects__accent-leaf bl-occasion-effects__accent-leaf--a"><?php blueline_render_occasion_motif_maple_leaf(); ?></span>
			<span class="bl-occasion-effects__accent-leaf bl-occasion-effects__accent-leaf--b"><?php blueline_render_occasion_motif_maple_leaf(); ?></span>
		<?php endif; ?>
	</div>
	<?php
}
