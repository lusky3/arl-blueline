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
	 * Normalized URL path of the Register CTA (e.g. "/register"), used to
	 * hide an identical depth-0, childless "Register to Play" item inside
	 * the primary menu -- otherwise it renders twice, once here and once
	 * as the CTA button in .bl-header__actions.
	 *
	 * This is empty whenever the header's CTA is anything other than
	 * Register (see blueline_site_header()): the primary menu's other
	 * permanent items (e.g. "Schedule") are real, distinct navigation and
	 * must never be hidden just because a differently-labelled CTA button
	 * happens to share their URL. Do not repurpose this property to
	 * de-duplicate against whatever CTA is currently showing -- that
	 * generalisation is exactly the bug this comment exists to prevent.
	 *
	 * @var string
	 */
	protected $register_duplicate_path = '';

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
	 * @param string $register_cta_url Absolute URL of the header's Register CTA,
	 *                                 or '' when that CTA is not currently showing
	 *                                 "Register to Play" (see blueline_site_header()).
	 *                                 Pass '' rather than some other CTA's URL --
	 *                                 this parameter exists solely to de-duplicate
	 *                                 the Register item, never any other one.
	 *                                 Compared by URL *path*, not the raw string or
	 *                                 get_permalink(): this site runs the Page Links
	 *                                 To plugin, which filters get_permalink()
	 *                                 per-post, so a menu item's resolved $item->url
	 *                                 and a freshly computed get_permalink() for the
	 *                                 "same" post can legitimately disagree.
	 *                                 Comparing the already-resolved path is the
	 *                                 reliable check.
	 */
	public function __construct( $register_cta_url = '' ) {
		$this->register_duplicate_path = $register_cta_url ? self::normalize_path( $register_cta_url ) : '';
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
		// renders twice (once here, once as the CTA button). $register_duplicate_path
		// is only ever non-empty while the CTA is genuinely showing Register
		// (see blueline_site_header()), so this never hides an unrelated
		// permanent item (e.g. "Schedule") just because some other CTA
		// happens to share its URL. Scoped to childless items only: a
		// parent sharing this destination still needs its submenu, which
		// this simple hide can't preserve.
		if ( 0 === $depth && ! $has_children && $this->register_duplicate_path && ! empty( $item->url )
			&& self::normalize_path( $item->url ) === $this->register_duplicate_path ) {
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
 * Header CTA content, driven by Season State (Task 6/7): "Register to Play"
 * with the ice fill only while a real registration is open AND its product
 * is purchasable right now, re-checked live rather than trusting a possibly
 * up-to-15-minutes-stale cached state alone -- a Register button must never
 * point at a product that has since sold out or been unpublished. Every
 * other state shows "Schedule" with the ice fill dropped. Mirrors the same
 * live re-check inc/homepage-modules.php uses for the homepage hero's own
 * Register CTA, for the same reason.
 *
 * The 'is_register' flag exists specifically so blueline_site_header() can
 * tell Blueline_Nav_Walker to de-duplicate the primary menu's own "Register
 * to Play" item ONLY when this CTA is genuinely Register -- never pass a
 * Schedule (or any other) CTA's URL to that walker's de-dup parameter, or
 * the primary menu's real, permanent "Schedule" item silently disappears in
 * every state but registration_open. That was a shipped regression; see the
 * walker's own $register_duplicate_path docblock for the full explanation.
 *
 * @return array{label: string, url: string, class: string, is_register: bool}
 */
function blueline_header_cta(): array {
	$state      = function_exists( 'blueline_season_state' ) ? blueline_season_state() : 'offseason';
	$state_data = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();

	$show_register = 'registration_open' === $state
		&& function_exists( 'blueline_homepage_registration_offer' )
		&& null !== blueline_homepage_registration_offer( $state_data );

	if ( $show_register ) {
		return array(
			'label'       => __( 'Register to Play', 'blueline' ),
			'url'         => blueline_resolve_link( 'page_register' ),
			'class'       => 'bl-btn--primary',
			'is_register' => true,
		);
	}

	return array(
		'label'       => __( 'Schedule', 'blueline' ),
		'url'         => blueline_resolve_link( 'page_schedule' ),
		'class'       => 'bl-btn--secondary',
		'is_register' => false,
	);
}

/**
 * Resolves the destination a logged-out visitor's "Log In" link should use:
 * WooCommerce's myaccount page when available (it renders a login form
 * there for a logged-out visitor and the account dashboard once logged in
 * -- verified live, and it's the exact URL this site's own "My ARL
 * Account" menu item already points at), falling back to wp_login_url()
 * when WooCommerce isn't active.
 *
 * @return string
 */
function blueline_utility_login_url() {
	$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
	return $url ? $url : wp_login_url();
}

/**
 * Reduces a URL to a comparable path -- lower-cased, no trailing slash,
 * scheme/host/query/fragment stripped. Mirrors
 * Blueline_Nav_Walker::normalize_path()'s identical logic (kept separate
 * rather than shared: that method is protected and scoped to the
 * Register-CTA de-dup, a different feature that happens to need the same
 * comparison).
 *
 * @param string $url URL to normalize.
 * @return string
 */
function blueline_utility_normalize_path( $url ) {
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	return rtrim( strtolower( $path ), '/' );
}

add_filter( 'wp_nav_menu_objects', 'blueline_utility_nav_auth_state', 10, 2 );
/**
 * Makes the header's utility nav (account links) state-aware.
 *
 * The 'utility' theme location is a static, admin-managed wp_nav_menu: on
 * this site it holds "My ARL Account" (-> /account) and "Log Out" (a
 * custom link with a baked-in _wpnonce), and both rendered unconditionally
 * -- a logged-OUT visitor was shown a live "Log Out" link sitewide,
 * including on /account itself while that page renders its own login
 * form, with nothing to actually get logged in with. Verified live: the
 * menu carries no separate "Log In" item at all.
 *
 * This filters the resolved items for the 'utility' location only, and
 * only for a logged-out visitor: any item whose URL is a logout action
 * (`action=logout`, matching both wp_logout_url() and the site's own
 * wp-login.php?action=logout link) is dropped -- there is nothing to log
 * out of. An item that already points at the resolved login destination
 * (on this site, "My ARL Account" -- both it and the login link resolve
 * to the same /account page) is RELABELLED to "Log In" rather than left
 * alone: an early version of this fix appended a separate "Log In" item
 * whenever the menu had no item whose TITLE already said "log in", which
 * left "My ARL Account" and "Log In" rendering side by side, both
 * pointing at the identical URL -- confirmed live. Only when no item
 * points at the login URL at all is a new one appended. A logged-in
 * visitor's menu is returned completely untouched.
 *
 * @param WP_Post[]|object[] $items Nav menu items resolved for this call.
 * @param stdClass           $args  wp_nav_menu() args object.
 * @return WP_Post[]|object[]
 */
function blueline_utility_nav_auth_state( $items, $args ) {
	if ( empty( $args->theme_location ) || 'utility' !== $args->theme_location ) {
		return $items;
	}

	if ( is_user_logged_in() ) {
		return $items;
	}

	$login_url  = blueline_utility_login_url();
	$login_path = blueline_utility_normalize_path( $login_url );

	$has_login_link = false;
	$filtered       = array();

	foreach ( $items as $item ) {
		$url = isset( $item->url ) ? (string) $item->url : '';

		// Nothing to log out of when logged out -- and a stale baked-in
		// _wpnonce on a static menu item would fail anyway.
		if ( false !== strpos( $url, 'action=logout' ) ) {
			continue;
		}

		if ( '' !== $login_path && blueline_utility_normalize_path( $url ) === $login_path ) {
			// Same destination as the login link this filter would
			// otherwise append -- relabel in place instead of duplicating.
			$item->title    = __( 'Log In', 'blueline' );
			$has_login_link = true;
		} else {
			$title = isset( $item->title ) ? wp_strip_all_tags( (string) $item->title ) : '';
			if ( false !== stripos( $title, 'log in' ) || false !== stripos( $title, 'sign in' ) ) {
				$has_login_link = true;
			}
		}

		$filtered[] = $item;
	}

	if ( ! $has_login_link ) {
		$filtered[] = blueline_utility_login_menu_item( $login_url );
	}

	return $filtered;
}

/**
 * Builds a fake-but-complete nav menu item object for a "Log In" link, in
 * the same shape Walker_Nav_Menu::start_el() (and any 'nav_menu_css_class'/
 * 'nav_menu_item_title' filter a plugin has attached) expects a real one to
 * have -- every property a normal wp_setup_nav_menu_item() result carries,
 * not just the handful this theme's own walker happens to read, so a
 * plugin filter touching an unrelated property (e.g. ->object_id) never
 * hits an undefined-property notice.
 *
 * @param string $url Resolved login destination (blueline_utility_login_url()).
 * @return object
 */
function blueline_utility_login_menu_item( $url ) {
	return (object) array(
		'ID'                    => 0,
		'db_id'                 => 0,
		'title'                 => __( 'Log In', 'blueline' ),
		'url'                   => $url,
		'menu_item_parent'      => 0,
		'object_id'             => 0,
		'object'                => 'custom',
		'type'                  => 'custom',
		'type_label'            => __( 'Custom Link', 'blueline' ),
		'target'                => '',
		'attr_title'            => '',
		'description'           => '',
		'classes'               => array( 'bl-utility-nav__login' ),
		'xfn'                   => '',
		'current'               => false,
		'current_item_ancestor' => false,
		'current_item_parent'   => false,
		'post_type'             => 'nav_menu_item',
		'post_status'           => 'publish',
		'menu_order'            => 999,
	);
}

/**
 * Output the site header: skip link, navy bar (logo, primary nav, sponsors
 * placeholder, season-aware CTA, mobile toggle), then the paired blue-line
 * bands that separate the header from the paper-white content body.
 */
function blueline_site_header() {
	$cta = blueline_header_cta();

	// Only ever de-duplicate the primary menu's "Register to Play" item --
	// and only while this CTA genuinely is Register. Passing any other
	// CTA's URL here would hide whichever unrelated permanent nav item
	// (e.g. "Schedule") happens to share it.
	$register_duplicate_url = $cta['is_register'] ? $cta['url'] : '';
	?>
	<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to main content', 'blueline' ); ?></a>

	<header class="bl-header">
		<div class="bl-header__bar">
			<div class="bl-container bl-header__inner">
				<div class="bl-header__brand">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<?php
						/*
						 * Fallback lockup when no Customizer logo is set (staging
						 * today -- its custom_logo attachment 404s, a known clone
						 * artifact; production has a real logo and takes the
						 * has_custom_logo() branch above). DESIGN.md: "the brand
						 * mark is the design system", so the fallback is the mark
						 * itself (blueline_leaf_mark(), device #4) plus the
						 * wordmark -- never bare text alone, and never hard-
						 * truncated (see .bl-header__site-title in header.css).
						 */
						?>
						<a class="bl-header__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
							<?php blueline_leaf_mark( 'bl-header__mark' ); ?>
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
							'walker'         => new Blueline_Nav_Walker( $register_duplicate_url ),
							'fallback_cb'    => false,
						)
					);
					?>
				</nav>

				<div class="bl-header__actions">
					<?php if ( has_nav_menu( 'utility' ) && blueline_section_enabled( 'chrome_utility_nav' ) ) : ?>
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

					<a class="bl-btn <?php echo esc_attr( $cta['class'] ); ?>" href="<?php echo esc_url( $cta['url'] ); ?>">
						<span class="bl-skew"><span><?php echo esc_html( $cta['label'] ); ?></span></span>
					</a>

					<button type="button" class="bl-nav__toggle" aria-expanded="false" aria-controls="bl-primary-menu">
						<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'blueline' ); ?></span>
						<span class="bl-nav__toggle-bars" aria-hidden="true"></span>
					</button>
				</div>
			</div>
		</div>

		<?php if ( blueline_section_enabled( 'chrome_sponsors' ) ) : ?>
			<?php
			/*
			 * Its own strip under the bar, NOT a fourth item in the bar row.
			 * Measured: the primary menu is a flex:1 sibling that expands to
			 * consume whatever the row has left, and .bl-container caps that row at
			 * 1200px, so the row has roughly 10px spare at every viewport from 1100
			 * to 1680 -- a wider screen does not help, because the container stops
			 * growing. Moving this box into the row wrapped the menu onto two lines
			 * at all of those widths. It is bigger and right-aligned here instead
			 * (header.css); putting it beside the menu needs the header container
			 * widened past the content width, which is a design decision, not a
			 * styling one.
			 */
			?>
			<div class="bl-header__sponsors"></div>
		<?php endif; ?>
		<?php
		/*
		 * When chrome_sponsors is off, blueline_sp_header_sponsors_limit()
		 * (inc/sportspress.php) has already forced SportsPress to print
		 * nothing at all, so this slot div is skipped outright rather than
		 * printed and left to a CSS/JS collapse (assets/src/js/sponsors.js,
		 * header.css's own comment on `.bl-header__sponsors`) that was
		 * designed for a DIFFERENT case -- the sponsor slot being off
		 * site-wide via SportsPress's own settings, not an admin flipping
		 * this control panel's toggle. That collapse is a real, measured,
		 * one-time reservation-then-shift (header.css's own comment gives
		 * the numbers); skipping the div here removes the reservation
		 * before the browser's first layout pass ever sees it, rather than
		 * reserving 64px and clawing it back a moment later.
		 */
		?>

		<div class="bl-band" aria-hidden="true"></div>
		<div class="bl-band--ink" aria-hidden="true"></div>
	</header>
	<?php
	/*
	 * The header is position:fixed (header.css), so it reserves no space of its
	 * own and this spacer stands in for it. Its height is pinned to the header's
	 * RESTING height and never changes -- deliberately not to the current
	 * height. A shrinking header that still occupied flow (position:sticky)
	 * would pull every following element up by the 40px it gave back at the
	 * moment it shrank, which reads as the page jumping under the reader's eyes
	 * mid-scroll. Holding the reservation at the tall value costs 40px of navy
	 * behind the shrunk bar and buys zero content shift, ever.
	 */
	?>
	<div class="bl-header__spacer" aria-hidden="true"></div>
	<?php
}

/**
 * The league's Contact Us page URL.
 *
 * This exists because the two places that linked to "contact us" disagreed:
 * the footer used /arl-league-info/contact-us (the real page, id 6379) while
 * the offseason hero's "Join the mailing list" CTA used /contact-us -- and
 * NO page exists at that slug, verified against all 100 published pages on
 * the live site. That CTA was a 404 waiting to ship.
 *
 * Both callers now read this one function, so they cannot drift apart again.
 * When the control panel's Links tab lands, this is the single place that
 * needs to consult the configured page ID; until then it stays a literal.
 *
 * @return string Absolute URL to the Contact Us page.
 */
function blueline_contact_url(): string {
	return blueline_resolve_link( 'page_contact' );
}

/**
 * Output the site footer: a permanent "The League" trust column (contact,
 * location, FAQs, legal -- there was previously none of this anywhere in
 * the footer), any populated widget columns, then a bottom bar with the
 * leaf mark and copyright line.
 *
 * Widget columns that have nothing assigned are skipped entirely rather
 * than rendered as an empty `.bl-footer__column` -- previously all four
 * always rendered, so on this site's real configuration (only footer-2
 * has widgets) three of the four columns were empty containers, against
 * DESIGN.md's own "never an empty container" rule. footer.css's grid
 * collapses to however many columns actually render.
 */
function blueline_site_footer() {
	?>
	<footer class="bl-footer">
		<div class="bl-container bl-footer__columns">
			<?php if ( blueline_section_enabled( 'chrome_footer_trust' ) ) : ?>
				<div class="bl-footer__column bl-footer__column--trust">
					<h2 class="widget-title"><?php echo esc_html( blueline_settings( 'footer_heading' ) ); ?></h2>
					<p class="bl-footer__location"><?php echo esc_html( blueline_settings( 'footer_location' ) ); ?></p>
					<ul class="bl-footer__trust-links">
						<li><a href="<?php echo esc_url( blueline_contact_url() ); ?>"><?php esc_html_e( 'Contact Us', 'blueline' ); ?></a></li>
						<li><a href="<?php echo esc_url( 'mailto:' . blueline_settings( 'contact_email' ) ); ?>"><?php echo esc_html( blueline_settings( 'contact_email' ) ); ?></a></li>
						<li><a href="<?php echo esc_url( blueline_resolve_link( 'page_faqs' ) ); ?>"><?php esc_html_e( 'FAQs', 'blueline' ); ?></a></li>
						<li><a href="<?php echo esc_url( blueline_resolve_link( 'page_legal' ) ); ?>"><?php esc_html_e( 'Privacy Policy & Legal', 'blueline' ); ?></a></li>
					</ul>
				</div>
			<?php endif; ?>

			<?php for ( $i = 1; $i <= 4; $i++ ) : ?>
				<?php if ( is_active_sidebar( 'footer-' . $i ) ) : ?>
					<div class="bl-footer__column">
						<?php dynamic_sidebar( 'footer-' . $i ); ?>
					</div>
				<?php endif; ?>
			<?php endfor; ?>
		</div>

		<?php blueline_footer_team_directory(); ?>

		<div class="bl-footer__bottom">
			<div class="bl-container bl-footer__bottom-inner">
				<?php blueline_leaf_mark( 'bl-footer__mark' ); ?>
				<p class="bl-footer__copyright">
					&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'blueline' ); ?>
					<?php
					/*
					 * Photography credit, in text, because the treatment removes
					 * the alternative. The league photographs used as band
					 * texture carry the photographer's own watermark, and
					 * desaturating them to ~16% makes it illegible -- so relying
					 * on it would mean taking the credit off his work by way of
					 * a design decision. Rendered whether or not a photograph
					 * happens to be on screen: the credit is for the body of
					 * work the site draws on, not for one band.
					 */
					?>
					<span class="bl-footer__credit">
						<?php esc_html_e( 'Photography by Michael Durrant.', 'blueline' ); ?>
					</span>
				</p>
			</div>
		</div>
	</footer>
	<?php
}

/**
 * Output the league team directory: one crest link per team the League Menu is
 * configured with.
 *
 * This is the theme's replacement for SportsPress Pro's own League Menu, which
 * inc/sportspress.php disables on the front end because the plugin prepends it
 * to <body> ahead of the skip link and, at 360px, on top of the open mobile
 * drawer. Rendering it here instead keeps the same admin-managed team list and
 * the same links, in a place where the crest count does not compete with the
 * page's first screen -- the roster runs to 22 teams in summer and as many as
 * 34 in winter, which is a wall of logos above the fold and an ordinary,
 * wrapping directory at the foot of the page.
 *
 * Renders nothing at all when the league menu is unconfigured, per DESIGN.md's
 * "never an empty container" rule.
 */
function blueline_footer_team_directory() {
	if ( ! blueline_section_enabled( 'chrome_footer_teams' ) ) {
		return;
	}

	if ( ! function_exists( 'blueline_league_menu_team_ids' ) ) {
		return;
	}

	$team_ids = blueline_league_menu_team_ids();

	if ( ! $team_ids ) {
		return;
	}
	?>
	<nav class="bl-footer__teams" aria-label="<?php esc_attr_e( 'Teams', 'blueline' ); ?>">
		<div class="bl-container">
			<h2 class="bl-footer__teams-title"><?php esc_html_e( 'Teams', 'blueline' ); ?></h2>
			<ul class="bl-footer__teams-list">
				<?php foreach ( $team_ids as $team_id ) : ?>
					<?php
					$name = function_exists( 'blueline_sp_title' ) ? blueline_sp_title( $team_id ) : get_the_title( $team_id );
					$link = get_permalink( $team_id );

					if ( ! $link ) {
						continue;
					}
					?>
					<li class="bl-footer__teams-item">
						<a class="bl-footer__teams-link" href="<?php echo esc_url( $link ); ?>">
							<?php if ( has_post_thumbnail( $team_id ) ) : ?>
								<?php
								/*
								 * The crest is decorative here: the team name
								 * sits beside it in the same link, so alt text
								 * would make a screen reader announce the name
								 * twice.
								 */
								echo get_the_post_thumbnail(
									$team_id,
									'thumbnail',
									array(
										'class'       => 'bl-footer__teams-crest',
										'alt'         => '',
										'loading'     => 'lazy',
										'aria-hidden' => 'true',
									)
								);
								?>
							<?php endif; ?>
							<span class="bl-footer__teams-name"><?php echo esc_html( $name ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</nav>
	<?php
}

/**
 * Output a post's byline: category eyebrow, author and date.
 *
 * A no-op for anything other than the 'post' post type -- static Pages
 * and future SportsPress entity types (Task 8) don't get a byline.
 */
function blueline_entry_meta() {
	if ( 'post' !== get_post_type() ) {
		return;
	}

	$categories_list = get_the_category_list( ', ' );
	?>
	<div class="bl-entry-meta">
		<?php if ( $categories_list ) : ?>
			<span class="bl-entry-meta__eyebrow">
				<?php
				// get_the_category_list() escapes each term name/link itself.
				echo wp_kses_post( $categories_list );
				?>
			</span>
		<?php endif; ?>

		<span class="bl-entry-meta__byline">
			<?php
			printf(
				/* translators: 1: post author name, 2: post date. */
				esc_html__( 'By %1$s on %2$s', 'blueline' ),
				'<span class="bl-entry-meta__author">' . esc_html( get_the_author() ) . '</span>',
				'<time class="bl-entry-meta__date" datetime="' . esc_attr( get_the_date( DATE_W3C ) ) . '">' . esc_html( get_the_date() ) . '</time>'
			);
			?>
		</span>
	</div>
	<?php
}

/**
 * Output numbered pagination for an archive/search/blog-index query.
 * Real <a> links (not JS-only) inside a labelled <nav>, per WCAG 2.2 AA.
 */
function blueline_pagination() {
	$links = paginate_links(
		array(
			'prev_text' => '<span aria-hidden="true">&larr;</span> ' . __( 'Newer', 'blueline' ),
			'next_text' => __( 'Older', 'blueline' ) . ' <span aria-hidden="true">&rarr;</span>',
			'type'      => 'list',
		)
	);

	if ( ! $links ) {
		return;
	}
	?>
	<nav class="bl-pagination" aria-label="<?php esc_attr_e( 'Posts navigation', 'blueline' ); ?>">
		<?php echo wp_kses_post( $links ); ?>
	</nav>
	<?php
}
