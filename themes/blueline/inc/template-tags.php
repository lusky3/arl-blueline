<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; the sniff's error is anchored to the T_OPEN_TAG token on line 1, and `phpcs:disable` only takes effect from the line it appears on, so placed on any later line (even line 2) this specific error still slips through — confirmed empirically while fixing this finding.
/**
 * Template tags: site header, site footer, and the primary nav walker.
 *
 * Blueline_Nav_Walker is deliberately kept in this file alongside
 * blueline_site_header()/blueline_site_footer(), per the Task 4 interface
 * contract, rather than split into its own class-blueline-nav-walker.php —
 * both file-organisation sniffs above/below are disabled (not ignored) for
 * exactly that reason, each named, each with no matching `enable`, so the
 * rest of this 265-line file — all of this task's escaping logic — is
 * still linted (unlike the previous blanket `phpcs:ignoreFile <sniff>`,
 * which is invalid syntax that silently disables the entire file).
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

defined( 'ABSPATH' ) || exit;

/**
 * Custom nav walker for the primary menu.
 *
 * Renders each item's label inside a counter-skewed `.bl-skew` wrapper
 * (top level only) so the focusable <a> itself stays un-skewed — the
 * focus ring drawn on it is always a true rectangle, never slanted.
 *
 * Items with children get a sibling <button> (aria-expanded/aria-controls)
 * so submenus can be opened by click/tap in addition to the CSS-only
 * hover/:focus-within mechanism, which matters for touch devices that
 * have no hover state at all.
 */
class Blueline_Nav_Walker extends Walker_Nav_Menu {

	/**
	 * ID of the submenu the next start_lvl() call should open.
	 *
	 * The start_el() method knows which item owns an upcoming submenu;
	 * start_lvl() only receives depth. Bridging the two via this property
	 * is how the <button aria-controls> and the <ul id> end up matching.
	 *
	 * @var string
	 */
	protected $pending_submenu_id = '';

	/**
	 * Normalized URL path (e.g. "/register") to hide at depth 0 when a
	 * childless item's own destination matches it. Used to de-duplicate
	 * the Register CTA -- rendered separately in .bl-header__actions --
	 * against an identical "Register to Play" entry inside the primary
	 * menu itself.
	 *
	 * @var string
	 */
	protected $duplicate_path = '';

	/**
	 * Set by start_el() when it hides the current item, so end_el() also
	 * skips emitting its closing tag instead of leaving an orphan </li>.
	 *
	 * @var bool
	 */
	protected $skip_current_item = false;

	/**
	 * Constructor.
	 *
	 * @param string $duplicate_url Absolute URL to de-duplicate against (e.g. the
	 *                              header's own Register CTA). Compared by URL
	 *                              *path*, not the raw string or get_permalink():
	 *                              this site runs the Page Links To plugin, which
	 *                              filters get_permalink() per-post, so a menu
	 *                              item's resolved $item->url and a freshly
	 *                              computed get_permalink() for the "same" post
	 *                              can legitimately disagree. Comparing the
	 *                              already-resolved path is the reliable check.
	 */
	public function __construct( $duplicate_url = '' ) {
		$this->duplicate_path = $duplicate_url ? self::normalize_path( $duplicate_url ) : '';
	}

	/**
	 * Reduces a URL to a comparable path: lower-cased, no trailing slash,
	 * scheme/host/query/fragment stripped.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	protected static function normalize_path( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return rtrim( strtolower( $path ), '/' );
	}

	/**
	 * Opens a submenu <ul>, tagging it with the id start_el() queued up.
	 *
	 * @param string   $output Passed by reference.
	 * @param int      $depth  Depth of the submenu, 0-indexed from its parent.
	 * @param stdClass $args   wp_nav_menu() args object.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$indent  = str_repeat( "\t", $depth + 1 );
		$id_attr = '';
		if ( $this->pending_submenu_id ) {
			$id_attr                  = ' id="' . esc_attr( $this->pending_submenu_id ) . '"';
			$this->pending_submenu_id = '';
		}
		$output .= "\n{$indent}<ul{$id_attr} class=\"sub-menu bl-nav__submenu\">\n";
	}

	/**
	 * Closes a submenu <ul>.
	 *
	 * @param string   $output Passed by reference.
	 * @param int      $depth  Depth of the submenu.
	 * @param stdClass $args   wp_nav_menu() args object.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$indent  = str_repeat( "\t", $depth + 1 );
		$output .= "{$indent}</ul>\n";
	}

	/**
	 * Renders one <li>: the link (skewed label at top level, plain
	 * otherwise) and, for parents, the submenu toggle button.
	 *
	 * @param string   $output Passed by reference.
	 * @param WP_Post  $item   Menu item data object.
	 * @param int      $depth  Depth of the menu item.
	 * @param stdClass $args   wp_nav_menu() args object.
	 * @param int      $id     Current item ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes   = empty( $item->classes ) ? array() : (array) $item->classes;
		$classes[] = 'bl-nav__item';

		// Walker::display_element() sets $args->has_children right before
		// calling start_el(); the menu-item-has-children class (added by
		// wp_nav_menu() itself) is kept as a defensive fallback.
		$has_children = ! empty( $args->has_children ) || in_array( 'menu-item-has-children', $classes, true );

		$this->skip_current_item = false;

		// Hide a top-level, childless item whose own destination matches
		// the header's own Register CTA -- otherwise "Register to Play"
		// renders twice (once here, once as the CTA button). Scoped to
		// childless items only: a parent sharing this destination still
		// needs its submenu, which this simple hide can't preserve.
		if ( 0 === $depth && ! $has_children && $this->duplicate_path && ! empty( $item->url )
			&& self::normalize_path( $item->url ) === $this->duplicate_path ) {
			$this->skip_current_item = true;
			return;
		}

		$is_current = in_array( 'current-menu-item', $classes, true )
			|| in_array( 'current_page_item', $classes, true );

		$submenu_id = '';
		if ( $has_children ) {
			$submenu_id               = 'bl-submenu-' . absint( $item->ID );
			$this->pending_submenu_id = $submenu_id;
			$classes[]                = 'bl-nav__item--parent';
		}

		$class_names = implode( ' ', array_filter( array_map( 'sanitize_html_class', $classes ) ) );

		$output .= '<li class="' . esc_attr( $class_names ) . '">';

		$link_attrs = array(
			'class' => 'bl-nav__link',
			'href'  => ! empty( $item->url ) ? $item->url : '#',
		);
		if ( ! empty( $item->attr_title ) ) {
			$link_attrs['title'] = $item->attr_title;
		}
		if ( ! empty( $item->target ) ) {
			$link_attrs['target'] = $item->target;
		}
		if ( ! empty( $item->xfn ) ) {
			$link_attrs['rel'] = $item->xfn;
		}
		if ( $is_current ) {
			$link_attrs['aria-current'] = 'page';
		}

		$attributes = '';
		foreach ( $link_attrs as $attr => $value ) {
			// Mirrors Walker_Nav_Menu::start_el() in WordPress core: href is a
			// URL and must be validated/escaped with esc_url(), not merely
			// HTML-entity-encoded with esc_attr() (which would let a
			// javascript: URL through unchanged).
			$escaped     = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
			$attributes .= ' ' . $attr . '="' . $escaped . '"';
		}

		$title = apply_filters( 'the_title', $item->title, $item->ID );
		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$title = apply_filters( 'nav_menu_item_title', $title, $item, $args, $depth );

		$label = 0 === $depth
			? '<span class="bl-skew"><span>' . $title . '</span></span>'
			: '<span>' . $title . '</span>';

		$output .= '<a' . $attributes . '>' . $label . '</a>';

		if ( $has_children ) {
			$toggle_label = sprintf(
				/* translators: %s: menu item label. */
				__( 'Show submenu for %s', 'blueline' ),
				wp_strip_all_tags( $title )
			);

			$output .= '<button type="button" class="bl-nav__toggle-sub" aria-expanded="false" aria-controls="' . esc_attr( $submenu_id ) . '">'
				. '<span class="screen-reader-text">' . esc_html( $toggle_label ) . '</span>'
				. '<svg class="bl-nav__chevron" viewBox="0 0 12 8" aria-hidden="true" focusable="false"><path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
				. '</button>';
		}
	}

	/**
	 * Closes the <li> opened by start_el().
	 *
	 * @param string   $output Passed by reference.
	 * @param WP_Post  $item   Menu item data object.
	 * @param int      $depth  Depth of the menu item.
	 * @param stdClass $args   wp_nav_menu() args object.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		if ( $this->skip_current_item ) {
			return;
		}
		$output .= "</li>\n";
	}
}

/**
 * Render a small decorative leaf mark (device #4 — the blue leaf).
 * Purely ornamental: aria-hidden, no text alternative needed.
 *
 * @param string $extra_class Extra class(es) for sizing/placement.
 */
function blueline_leaf_mark( $extra_class = '' ) {
	printf(
		'<svg class="bl-leaf-mark %s" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 2c8 6 16 12 16 22 0 9-7 16-16 16S8 33 8 24c0-10 8-16 16-22Zm0 6c-1.2 1.9-2 4-2 6.5 0 3 1.5 5.5 3.6 7.1-2.7.4-4.9 1.9-6.3 4-1-2.9-3.4-5.1-6.3-6C15.8 16 19.3 12.6 24 8Zm0 32V22" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>',
		esc_attr( $extra_class )
	);
}

/**
 * Output the site header: skip link, navy bar (logo, primary nav, sponsors
 * placeholder, Register CTA, mobile toggle), then the paired blue-line
 * bands that separate the header from the paper-white content body.
 */
function blueline_site_header() {
	$register_url = home_url( '/register' );
	?>
	<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to main content', 'blueline' ); ?></a>

	<header class="bl-header">
		<div class="bl-header__bar">
			<div class="bl-container bl-header__inner">
				<div class="bl-header__brand">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<a class="bl-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
							<span class="bl-header__site-title"><?php bloginfo( 'name' ); ?></span>
						</a>
					<?php endif; ?>
				</div>

				<nav class="bl-nav" aria-label="<?php esc_attr_e( 'Primary', 'blueline' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'primary',
							'container'      => false,
							'menu_id'        => 'bl-primary-menu',
							'menu_class'     => 'bl-nav__menu',
							'walker'         => new Blueline_Nav_Walker( $register_url ),
							'fallback_cb'    => false,
						)
					);
					?>
				</nav>

				<div class="bl-header__actions">
					<?php if ( has_nav_menu( 'utility' ) ) : ?>
						<nav class="bl-utility-nav" aria-label="<?php esc_attr_e( 'Account', 'blueline' ); ?>">
							<?php
							wp_nav_menu(
								array(
									'theme_location' => 'utility',
									'container'      => false,
									'menu_id'        => 'bl-utility-menu',
									'menu_class'     => 'bl-utility-nav__menu',
									'fallback_cb'    => false,
									'depth'          => 1,
								)
							);
							?>
						</nav>
					<?php endif; ?>

					<a class="bl-btn bl-btn--primary" href="<?php echo esc_url( $register_url ); ?>">
						<span class="bl-skew"><span><?php esc_html_e( 'Register to Play', 'blueline' ); ?></span></span>
					</a>

					<button type="button" class="bl-nav__toggle" aria-expanded="false" aria-controls="bl-primary-menu">
						<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'blueline' ); ?></span>
						<span class="bl-nav__toggle-bars" aria-hidden="true"></span>
					</button>
				</div>
			</div>
		</div>

		<div class="bl-header__sponsors"></div>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
}

/**
 * Output the site footer: four widget columns on the deep-navy ground,
 * then a bottom bar with the leaf mark and copyright line.
 */
function blueline_site_footer() {
	?>
	<footer class="bl-footer">
		<div class="bl-container bl-footer__columns">
			<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
				<div class="bl-footer__column">
					<?php if ( is_active_sidebar( 'footer-' . $i ) ) : ?>
						<?php dynamic_sidebar( 'footer-' . $i ); ?>
					<?php endif; ?>
				</div>
			<?php endfor; ?>
		</div>

		<div class="bl-footer__bottom">
			<div class="bl-container bl-footer__bottom-inner">
				<?php blueline_leaf_mark( 'bl-footer__mark' ); ?>
				<p class="bl-footer__copyright">
					&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'blueline' ); ?>
				</p>
			</div>
		</div>
	</footer>
	<?php
}
