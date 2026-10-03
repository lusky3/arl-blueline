<?php
/**
 * The primary nav walker, loaded by inc/template-tags.php.
 *
 * @package blueline
 */

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
	 * blueline_utility_normalize_path() (inc/template-tags.php), which
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
	 * Applies core's nav_menu_css_class, nav_menu_link_attributes and
	 * walker_nav_menu_start_el filters (the last one around the <a> only),
	 * re-adding bl-nav__item / bl-nav__link afterwards so no callback can
	 * drop them. nav_menu_item_id is not applied: this walker emits no <li>
	 * id, and core's default id would change every item's markup.
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

		$required_classes = array( 'bl-nav__item' );
		$submenu_id       = '';
		if ( $has_children ) {
			$submenu_id               = 'bl-submenu-' . absint( $item->ID );
			$this->pending_submenu_id = $submenu_id;
			$classes[]                = 'bl-nav__item--parent';
			$required_classes[]       = 'bl-nav__item--parent';
		}

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$classes     = apply_filters( 'nav_menu_css_class', array_filter( $classes ), $item, $args, $depth );
		$classes     = self::with_required_classes( (array) $classes, $required_classes );
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

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$link_attrs          = (array) apply_filters( 'nav_menu_link_attributes', $link_attrs, $item, $args, $depth );
		$link_attrs['class'] = self::with_required_class_token( $link_attrs['class'] ?? '', 'bl-nav__link' );

		$attributes = '';
		foreach ( $link_attrs as $attr => $value ) {
			// Empty, false and non-scalar values are skipped, as in core's build_atts().
			if ( false === $value || '' === $value || ! is_scalar( $value ) ) {
				continue;
			}
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

		/** This filter is documented in wp-includes/class-walker-nav-menu.php */
		$output .= apply_filters( 'walker_nav_menu_start_el', '<a' . $attributes . '>' . $label . '</a>', $item, $depth, $args );

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

	/**
	 * The filtered <li> classes, string-only, with every required class
	 * appended again if a nav_menu_css_class callback dropped it.
	 *
	 * @param array    $classes  Classes after nav_menu_css_class.
	 * @param string[] $required Classes the walker's CSS and JS depend on.
	 * @return string[]
	 */
	protected static function with_required_classes( array $classes, array $required ): array {
		$classes = array_map( 'strval', array_filter( $classes, 'is_scalar' ) );

		foreach ( $required as $class_name ) {
			if ( ! in_array( $class_name, $classes, true ) ) {
				$classes[] = $class_name;
			}
		}

		return $classes;
	}

	/**
	 * A class attribute value that is sure to contain $token.
	 *
	 * @param mixed  $class_attr Class value after nav_menu_link_attributes.
	 * @param string $token      Required class.
	 * @return string
	 */
	protected static function with_required_class_token( $class_attr, string $token ): string {
		$class_attr = is_scalar( $class_attr ) ? trim( (string) $class_attr ) : '';
		$tokens     = preg_split( '/\s+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY );

		return in_array( $token, $tokens, true ) ? $class_attr : ltrim( $class_attr . ' ' . $token );
	}
}
