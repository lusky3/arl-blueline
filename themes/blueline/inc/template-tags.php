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
	 * happens to share their URL.
	 *
	 * $schedule_duplicate_path below exists for exactly that "Schedule"
	 * case -- as its OWN, separately-gated property, not by repurposing
	 * this one to de-duplicate against "whatever CTA is currently
	 * showing." That generalisation was tried once and is the shipped
	 * regression blueline_header_cta()'s own docblock describes: pass the
	 * CURRENT CTA's URL into a single "the CTA" slot regardless of which
	 * CTA it is, and the primary menu's real, permanent "Schedule" item
	 * disappears in every state whose CTA happens to point at /schedule --
	 * which, before this pair of properties existed, was every state but
	 * registration_open. Each property here is instead only ever non-empty
	 * while ITS OWN named CTA (Register, Schedule) is the one genuinely
	 * showing, so a third, future CTA that happens to share a URL with
	 * either one can never trip either check.
	 *
	 * @var string
	 */
	protected $register_duplicate_path = '';

	/**
	 * Normalized URL path of the Schedule CTA (e.g. "/schedule"), used to
	 * hide an identical depth-0, childless "Schedule" item inside the
	 * primary menu -- the Schedule counterpart to $register_duplicate_path
	 * above; see that property's docblock for why this is its own separate
	 * property rather than a generalisation of it.
	 *
	 * Empty whenever the header's CTA is not currently showing "Schedule"
	 * (see blueline_site_header()) -- most importantly while it IS showing
	 * Register, in which case the primary menu's real, permanent
	 * "Schedule" item must render normally.
	 *
	 * @var string
	 */
	protected $schedule_duplicate_path = '';

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
	 * @param string $schedule_cta_url Absolute URL of the header's Schedule CTA,
	 *                                 or '' when that CTA is not currently showing
	 *                                 "Schedule". Same rules as $register_cta_url,
	 *                                 for the same reasons, scoped to the
	 *                                 "Schedule" item only.
	 */
	public function __construct( $register_cta_url = '', $schedule_cta_url = '' ) {
		$this->register_duplicate_path = $register_cta_url ? self::normalize_path( $register_cta_url ) : '';
		$this->schedule_duplicate_path = $schedule_cta_url ? self::normalize_path( $schedule_cta_url ) : '';
	}

	/**
	 * Reduces a URL to a comparable path: lower-cased, no trailing slash,
	 * scheme/host/query/fragment stripped. Delegates to the free function
	 * blueline_utility_normalize_path() (below, in this same file), which
	 * carries the one real implementation of this logic; kept as its own
	 * method rather than inlined at each call site because it is protected
	 * and scoped to this class's Register-CTA de-dup use.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	protected static function normalize_path( $url ) {
		return blueline_utility_normalize_path( $url );
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
		// the header's own Register OR Schedule CTA -- otherwise that item
		// renders twice (once here, once as the CTA button). Each path is
		// only ever non-empty while ITS OWN named CTA is genuinely showing
		// (see blueline_site_header()/blueline_header_cta()), so this never
		// hides an unrelated permanent item just because some other CTA
		// happens to share its URL -- see $register_duplicate_path's own
		// docblock for the regression this guards against. Scoped to
		// childless items only: a parent sharing this destination still
		// needs its submenu, which this simple hide can't preserve.
		if ( 0 === $depth && ! $has_children && ! empty( $item->url ) ) {
			$item_path = self::normalize_path( $item->url );

			if ( ( $this->register_duplicate_path && $item_path === $this->register_duplicate_path )
				|| ( $this->schedule_duplicate_path && $item_path === $this->schedule_duplicate_path ) ) {
				$this->skip_current_item = true;
				return;
			}
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
 * Open the shared root-template chrome: `<main id="main" class="bl-main...">`,
 * `.bl-container`, and -- for a template that has a sidebar concept at all --
 * the `.bl-content-layout`/`.bl-content-layout__primary` wrapper around the
 * primary column. Pair with blueline_page_wrapper_end(). This is the
 * identical open-half markup that archive.php, search.php, single.php,
 * page.php, and the root sportspress.php each used to repeat verbatim --
 * this theme's existing convention for exactly this shape (see
 * blueline_wc_wrapper_start()/_end() in inc/woocommerce.php, and
 * blueline_account_module_start()/_end() in inc/account/dashboard.php).
 *
 * $has_sidebar is nullable, not a plain bool: pass a real bool for a
 * template that reserves a sidebar column at all, to get the
 * `.bl-content-layout` wrapper (with or without its `--has-sidebar`
 * modifier, matching that bool); pass null for a template with no sidebar
 * concept whatsoever -- index.php is the one root template that never grew
 * this wrapper at all -- to render just `<main><div class="bl-container">`,
 * with no content-layout wrapper, exactly as it already did.
 *
 * @param bool|null $has_sidebar      Whether this request has an active sidebar
 *                                     to reserve a column for, or null for a
 *                                     template with no sidebar concept at all.
 * @param string    $extra_main_class Extra class(es) appended to `bl-main`
 *                                     (e.g. 'bl-main--sp' for the root
 *                                     sportspress.php). Empty for the plain
 *                                     content templates.
 * @return void
 */
function blueline_page_wrapper_start( ?bool $has_sidebar = null, string $extra_main_class = '' ): void {
	$main_class = 'bl-main' . ( '' !== $extra_main_class ? ' ' . $extra_main_class : '' );
	?>
	<main id="main" class="<?php echo esc_attr( $main_class ); ?>" tabindex="-1">
		<div class="bl-container">
			<?php if ( null !== $has_sidebar ) : ?>
			<div class="bl-content-layout<?php echo $has_sidebar ? ' bl-content-layout--has-sidebar' : ''; ?>">
				<div class="bl-content-layout__primary">
			<?php endif; ?>
	<?php
}

/**
 * Close the chrome opened by blueline_page_wrapper_start() -- see that
 * function's own docblock, including why $has_sidebar is nullable.
 *
 * @param bool|null $has_sidebar Must be the exact same value passed to the
 *                                matching blueline_page_wrapper_start() call.
 * @return void
 */
function blueline_page_wrapper_end( ?bool $has_sidebar = null ): void {
	?>
			<?php if ( null !== $has_sidebar ) : ?>
				</div>
				<?php if ( $has_sidebar ) : ?>
					<?php get_sidebar(); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
	</main>
	<?php
}

/**
 * Splits a paginated post's content across page numbers with this theme's
 * `<nav class="bl-page-links">` wrapper -- the identical wp_link_pages()
 * call content-single.php, content-page.php, content-notitle.php, and
 * content-nothumb.php each used to repeat verbatim.
 *
 * @return void
 */
function blueline_page_links(): void {
	wp_link_pages(
		array(
			'before' => '<nav class="bl-page-links" aria-label="' . esc_attr__( 'Page', 'blueline' ) . '">' . esc_html__( 'Pages:', 'blueline' ),
			'after'  => '</nav>',
		)
	);
}

/**
 * Comment pagination nav, rendered identically above and below comments.php's
 * comment list -- the `<nav class="bl-comments__nav">...</nav>` block that
 * file used to repeat byte-for-byte in both spots.
 *
 * @return void
 */
function blueline_comments_nav(): void {
	?>
	<nav class="bl-comments__nav" aria-label="<?php esc_attr_e( 'Comments', 'blueline' ); ?>">
		<div class="bl-comments__nav-previous"><?php previous_comments_link( esc_html__( '&larr; Older comments', 'blueline' ) ); ?></div>
		<div class="bl-comments__nav-next"><?php next_comments_link( esc_html__( 'Newer comments &rarr;', 'blueline' ) ); ?></div>
	</nav>
	<?php
}

/**
 * Header CTA content, driven by Season State (Task 6/7): "Register to Play"
 * with the ice fill only while a real registration is open AND its product
 * is purchasable right now, re-checked live rather than trusting a possibly
 * up-to-15-minutes-stale cached state alone -- a Register button must never
 * point at a product that has since sold out or been unpublished. Every
 * other state shows "Schedule" with the ice fill dropped. Mirrors the same
 * live re-check inc/homepage-modules.php uses for the homepage hero's own
 * Register CTA, for the same reason -- and MUST call the exact same
 * function that hero logic does.
 *
 * Live-review finding: this used to call a function named
 * "blueline_homepage_registration_offer", singular -- which is not, and
 * has never been, defined anywhere in this theme; the real function is
 * blueline_homepage_registration_offers() (plural, inc/homepage-modules.php),
 * returning every live offer rather than one. function_exists() on the
 * misspelled name is always false, so `&&` short-circuited before the call
 * and $show_register was always false -- the header CTA showed "Schedule"
 * in EVERY state, including registration_open, even while the homepage hero
 * (which calls the real, plural function) was showing "REGISTRATION OPEN"
 * with real pricing and a working "Register Now" link. Confirmed live on
 * staging. Fixed by calling the same offers() function the hero itself uses
 * and checking for a non-empty result, which is exactly what
 * blueline_homepage_hero_content() already does to decide the same thing.
 *
 * The 'is_register'/'is_schedule' flags exist specifically so
 * blueline_site_header() can tell Blueline_Nav_Walker to de-duplicate the
 * primary menu's own "Register to Play"/"Schedule" item ONLY when THIS CTA
 * is genuinely that one -- never pass one CTA's URL to the other's de-dup
 * parameter, or an unrelated permanent nav item silently disappears. See the
 * walker's own $register_duplicate_path/$schedule_duplicate_path docblocks
 * for the full explanation.
 *
 * @return array{label: string, url: string, class: string, is_register: bool, is_schedule: bool}
 */
function blueline_header_cta(): array {
	$state      = function_exists( 'blueline_season_state' ) ? blueline_season_state() : 'offseason';
	$state_data = function_exists( 'blueline_season_state_data' ) ? blueline_season_state_data() : array();

	$show_register = 'registration_open' === $state
		&& function_exists( 'blueline_homepage_registration_offers' )
		&& ! empty( blueline_homepage_registration_offers( $state_data ) );

	if ( $show_register ) {
		return array(
			'label'       => __( 'Register to Play', 'blueline' ),
			'url'         => blueline_resolve_link( 'page_register' ),
			'class'       => 'bl-btn--primary',
			'is_register' => true,
			'is_schedule' => false,
		);
	}

	return array(
		'label'       => __( 'Schedule', 'blueline' ),
		'url'         => blueline_resolve_link( 'page_schedule' ),
		'class'       => 'bl-btn--secondary',
		'is_register' => false,
		'is_schedule' => true,
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
 * scheme/host/query/fragment stripped. The one real implementation of this
 * logic; Blueline_Nav_Walker::normalize_path() (protected, scoped to that
 * class's Register-CTA de-dup) delegates to this function rather than
 * duplicating it.
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
 * Makes the header's utility nav (account links) -- and, more simply, the
 * primary nav -- state-aware.
 *
 * The 'utility' theme location is a static, admin-managed wp_nav_menu: on
 * this site it holds "My ARL Account" (-> /account) and "Log Out" (a
 * custom link with a baked-in _wpnonce), and both rendered unconditionally
 * -- a logged-OUT visitor was shown a live "Log Out" link sitewide,
 * including on /account itself while that page renders its own login
 * form, with nothing to actually get logged in with. Verified live: the
 * menu carries no separate "Log In" item at all.
 *
 * Found live 2026-08-25, same bug, second location: the 'primary' menu
 * (general site navigation -- Home, Standings, Register, etc., NOT an
 * account-links menu the way 'utility' is) ALSO had an admin-added "Log
 * Out" item shown unconditionally. 'primary' gets the simpler half of this
 * fix only: the dead logout link is dropped, but nothing is appended in
 * its place -- injecting a "Log In" item into general site navigation
 * doesn't fit its purpose the way it fits 'utility', which is already the
 * one dedicated home for account-state-aware links. A logged-out visitor
 * who wants to log in still has 'utility' for that.
 *
 * This filters the resolved items for the 'utility' and 'primary'
 * locations only, and only for a logged-out visitor: any item whose URL is
 * a logout action (`action=logout`, matching both wp_logout_url() and the
 * site's own wp-login.php?action=logout link) is dropped -- there is
 * nothing to log out of. For 'utility' only, an item that already points
 * at the resolved login destination (on this site, "My ARL Account" --
 * both it and the login link resolve to the same /account page) is
 * RELABELLED to "Log In" rather than left alone: an early version of this
 * fix appended a separate "Log In" item whenever the menu had no item
 * whose TITLE already said "log in", which left "My ARL Account" and "Log
 * In" rendering side by side, both pointing at the identical URL --
 * confirmed live. Only when no item points at the login URL at all is a
 * new one appended, and only for 'utility'. A logged-in visitor's menu, on
 * either location, is returned completely untouched.
 *
 * @param WP_Post[]|object[] $items Nav menu items resolved for this call.
 * @param stdClass           $args  wp_nav_menu() args object.
 * @return WP_Post[]|object[]
 */
function blueline_utility_nav_auth_state( $items, $args ) {
	$location = ! empty( $args->theme_location ) ? $args->theme_location : '';

	if ( 'utility' !== $location && 'primary' !== $location ) {
		return $items;
	}

	if ( is_user_logged_in() ) {
		return $items;
	}

	$filtered = array();

	foreach ( $items as $item ) {
		$url = isset( $item->url ) ? (string) $item->url : '';

		// Nothing to log out of when logged out -- and a stale baked-in
		// _wpnonce on a static menu item would fail anyway.
		if ( false !== strpos( $url, 'action=logout' ) ) {
			continue;
		}

		$filtered[] = $item;
	}

	if ( 'primary' === $location ) {
		// General site navigation, not an account-links menu -- drop the
		// dead logout link and stop there; see this function's own
		// docblock for why nothing is appended here the way 'utility' does.
		return $filtered;
	}

	$login_url  = blueline_utility_login_url();
	$login_path = blueline_utility_normalize_path( $login_url );

	$has_login_link = false;

	foreach ( $filtered as $item ) {
		$url = isset( $item->url ) ? (string) $item->url : '';

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

	// De-duplicate the primary menu's "Register to Play" item only while
	// this CTA genuinely is Register, and its "Schedule" item only while
	// this CTA genuinely is Schedule -- never cross the two, or hide either
	// one because some OTHER CTA happens to share its URL.
	$register_duplicate_url = $cta['is_register'] ? $cta['url'] : '';
	$schedule_duplicate_url = $cta['is_schedule'] ? $cta['url'] : '';
	?>
	<a class="skip-link screen-reader-text" href="#main"><?php esc_html_e( 'Skip to main content', 'blueline' ); ?></a>

	<header class="bl-header">
		<div class="bl-header__bar">
			<div class="bl-container bl-header__inner">
				<?php
				/*
				 * A11y finding, investigated and deliberately left as-is: the
				 * logo (below, either the_custom_logo() or the fallback link)
				 * and a "Home" item in the primary menu (rendered by
				 * wp_nav_menu() further down) would be adjacent, redundant tab
				 * stops if both point at "/" -- a real defect when it happens.
				 *
				 * It cannot be fixed here by simply un-focusing the logo (e.g.
				 * tabindex="-1"), because that redundancy is not guaranteed to
				 * exist. wp_nav_menu() is called below with 'fallback_cb' =>
				 * false, so the 'primary' theme location renders EXACTLY what
				 * an admin assigned to it -- no menu, or a menu with no "Home"
				 * item at all, are both real, reachable configurations this
				 * codebase's own terms allow (a league running the site could
				 * reassign the primary menu at any time; nothing enforces a
				 * "Home" item's presence). Removing the logo's own
				 * focusability unconditionally would make it keyboard-
				 * unreachable on exactly that configuration, which is a worse
				 * defect than the one this would fix. blueline_leaf_mark()'s
				 * icon inside the logo is already aria-hidden (device #4,
				 * "logo is decorative, the text version already forms the
				 * accessible name") -- that half of the redundancy problem is
				 * already handled; the link-vs-menu-item duplication is a
				 * content/menu-configuration concern this template cannot
				 * safely resolve on its own.
				 */
				?>
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
							'walker'         => new Blueline_Nav_Walker( $register_duplicate_url, $schedule_duplicate_url ),
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

					<?php
					// The header CTA is where the occasion accent's ribbon-fill
					// consumer lives (header.css's .bl-btn--primary .bl-skew --
					// inc/occasions.php's blueline_occasion_front_end_styles()
					// docblock), so the motif (design spec §7.2's third named
					// consumer) rides along here too, and only while this CTA
					// is genuinely the primary Register button -- a motif next
					// to "Schedule" in every other season state would pair a
					// festive icon with a button that never gets the accent
					// colour at all.
					$occasion_line = $cta['is_register'] ? blueline_active_occasion_line() : '';
					?>
					<a
						class="bl-btn <?php echo esc_attr( $cta['class'] ); ?>"
						href="<?php echo esc_url( $cta['url'] ); ?>"
						<?php echo '' !== $occasion_line ? 'title="' . esc_attr( $occasion_line ) . '"' : ''; ?>
					>
						<span class="bl-skew"><span><?php echo esc_html( $cta['label'] ); ?></span></span>
						<?php if ( $cta['is_register'] && function_exists( 'blueline_render_header_occasion_motif' ) ) : ?>
							<?php blueline_render_header_occasion_motif(); ?>
						<?php endif; ?>
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
			 *
			 * Reported live: with nothing labelling it, the strip read as a
			 * random ad floating on bare navy rather than an intentional
			 * section -- there was plenty of unused row width to its left (this
			 * row carries only this one flex item, unlike the bar row above)
			 * and no visual tie to the bar it sits under. The static label span
			 * below is real markup, not the SportsPress "Sponsors" title
			 * (.sp-sponsors-title, hidden in this slot -- header.css), so its
			 * copy and styling are this theme's own; it always prints
			 * regardless of what SportsPress's own async prepend does or does
			 * not fill in beside it, so it can never itself be the reason this
			 * strip looks empty. header.css uses flex `order` (not DOM order)
			 * to keep it visually first even though the plugin's own script
			 * prepends the logo INTO this same container, ahead of this label
			 * in the DOM.
			 */
			?>
			<div class="bl-header__sponsors">
				<span class="bl-header__sponsors-label"><?php esc_html_e( 'Thanks to our sponsors', 'blueline' ); ?></span>
			</div>
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
 *
 * Each of the four widget columns is additionally gated by its own
 * `chrome_footer_widgets_N` section toggle (inc/settings/sections.php),
 * ANDed with the pre-existing `is_active_sidebar()` check -- a real toggle,
 * unlike `chrome_footer_trust` below, which gates only the hardcoded trust
 * column and nothing else. Written out as four literal blocks rather than
 * a loop over `$i` so each `blueline_section_enabled()` call names its own
 * key literally -- SchemaFieldCoverageTest's consumer scan looks for
 * exactly that, not a key built at request time the way
 * blueline_homepage_module_order() builds `'module_' . $module`. There is
 * no floor here (unlike the homepage modules): an admin switching off all
 * four is a legitimate choice, not a state this function needs to refuse.
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
						<?php
						/*
						 * Rollout-period escape hatch: rookie-child (the theme
						 * blueline replaced) has its own matching banner, offering
						 * the reverse switch back to blueline -- see that theme's
						 * own functions.php (not in this repo; it predates version
						 * control on this site). Gated on the Theme Switcha plugin
						 * itself being enabled, not a settings toggle of this
						 * theme's own: once that plugin is deactivated at the end
						 * of the rollout, this link self-removes rather than
						 * lingering as a dead ?theme-switch= link with nothing left
						 * to handle it.
						 */
						if ( function_exists( 'theme_switcha_check_enabled' ) && theme_switcha_check_enabled() ) :
							?>
							<li><a href="<?php echo esc_url( add_query_arg( 'theme-switch', 'rookie-child' ) ); ?>"><?php esc_html_e( 'Switch to Classic Site', 'blueline' ); ?></a></li>
						<?php endif; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( blueline_section_enabled( 'chrome_footer_widgets_1' ) && is_active_sidebar( 'footer-1' ) ) : ?>
				<div class="bl-footer__column">
					<?php dynamic_sidebar( 'footer-1' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( blueline_section_enabled( 'chrome_footer_widgets_2' ) && is_active_sidebar( 'footer-2' ) ) : ?>
				<div class="bl-footer__column">
					<?php dynamic_sidebar( 'footer-2' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( blueline_section_enabled( 'chrome_footer_widgets_3' ) && is_active_sidebar( 'footer-3' ) ) : ?>
				<div class="bl-footer__column">
					<?php dynamic_sidebar( 'footer-3' ); ?>
				</div>
			<?php endif; ?>

			<?php if ( blueline_section_enabled( 'chrome_footer_widgets_4' ) && is_active_sidebar( 'footer-4' ) ) : ?>
				<div class="bl-footer__column">
					<?php dynamic_sidebar( 'footer-4' ); ?>
				</div>
			<?php endif; ?>
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
				<?php
				/*
				 * Sitewide light/dark/system toggle, visible to every visitor
				 * (design spec docs/superpowers/specs/2026-08-26-blueline-
				 * footer-theme-toggle-design.md) -- the footer bottom bar for
				 * the same "one universally-rendered chrome element" reason
				 * the occasion `line` below already lives here. See
				 * inc/account/theme-preference.php's blueline_render_theme_toggle()
				 * for why its own markup never varies by anonymous visitor.
				 */
				blueline_render_theme_toggle();
				?>
				<?php
				/*
				 * The occasion `line`'s real, visible placement (design spec
				 * §7.1's third field, alongside accent and motif) -- the
				 * footer bottom bar rather than the header nav bar, because
				 * this is the one universally-rendered chrome element with
				 * room to grow by a line without disturbing the header's own
				 * fixed-height layout on every page (see the header CTA's own
				 * `title` attribute, inc/template-tags.php's
				 * blueline_site_header(), for the header's own zero-layout-
				 * impact echo of this same text). Prints nothing at all, not
				 * even an empty element, when no occasion is active or its
				 * `line` is unset -- exactly like blueline_leaf_mark() above
				 * it, this is additive chrome, never a layout reservation.
				 */
				$occasion_line = function_exists( 'blueline_active_occasion_line' ) ? blueline_active_occasion_line() : '';
				if ( '' !== $occasion_line ) :
					?>
					<p class="bl-footer__occasion-line"><?php echo esc_html( $occasion_line ); ?></p>
				<?php endif; ?>
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

	if ( 'footer' !== blueline_team_directory_position() ) {
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
