<?php
/**
 * SportsPress integration: the `the_content` pass that keeps every
 * SportsPress <table> inside a horizontal scroll container.
 *
 * Loaded by inc/sportspress.php; every SportsPress touchpoint is guarded
 * so the theme never fatals with SportsPress deactivated.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_content', 'blueline_sp_wrap_tables_for_scroll', 20 );
/**
 * Guarantee every SportsPress <table> in rendered content either sits
 * inside a scroll container or gets one of its own, because "the page body must
 * never scroll horizontally" is a site-wide hard invariant, not a
 * today's-markup-shaped one, so this must not depend on SportsPress's exact
 * current class names.
 *
 * Re-scoped after review: this must touch SportsPress's own output only,
 * never an ordinary block-editor table in a blog post or page (those
 * already have a working .wp-block-table wrapper of their own, and forcing
 * display:block onto them via bl-table-self-scroll would be an untested,
 * out-of-scope behaviour change). The scoping problem is that SP tables can
 * legitimately appear on an ordinary Page too: /standings embeds
 * [team_standings] shortcodes directly in page content, so a page-type
 * check (is_singular(sp_post_types())/is_tax(sp_taxonomies())) would
 * incorrectly skip it. What every SportsPress table genuinely has in
 * common, regardless of post type or template, is SportsPress's own `sp-`
 * class-name convention: every table this plugin renders carries at least
 * one class starting with "sp-" directly on the <table> itself (confirmed
 * by reading every table-producing template in the installed plugin), and
 * an ordinary wp-block-table never does. Detecting that convention, not one
 * specific class, is what keeps this both scoped to SportsPress AND still
 * resilient to a future SportsPress markup change (see
 * blueline_dom_table_is_sportspress()).
 *
 * Three layers, in order:
 * 1. SportsPress's own templates (league-table.php, event-blocks.php,
 *    player-statistics-league.php, event-details.php, event-list.php,
 *    player-list.php, event-officials-table.php, event-logos-block.php) all
 *    wrap their <table> in an identical, class-only
 *    `<div class="sp-table-wrapper">`, confirmed by reading every
 *    occurrence in the installed plugin. A cheap string check/replace
 *    handles this, the overwhelming common case, without any parsing.
 * 2. A cheap `strpos( $content, 'sp-' )` pre-check, then
 *    blueline_sp_ensure_tables_scroll() walks every remaining <table> with
 *    core's HTML5 parser (WP_HTML_Processor; libxml's DOMDocument only for
 *    markup that parser refuses) and gives any that (a) is SportsPress's own (carries an
 *    sp- prefixed class) and (b) still has no scroll-capable ancestor a
 *    self-contained scroll class directly. This is what actually closes
 *    the gap: event-venue.php is the one SP template today that renders
 *    its <table> with no wrapper at all (its embedded Leaflet map
 *    overflowed the page body at mobile widths until this was added (see
 *    the Task 8 fix report), and a future SportsPress update changing
 *    any table's class list, or adding a new unwrapped one, is still
 *    caught by this pass as long as it keeps SP's own sp- prefix
 *    convention, which every SP class already does today. Runs after
 *    shortcodes have already expanded (priority 20, after SP's own
 *    the_content hooks and do_shortcode's default priority 11).
 *
 * base.css's `html { overflow-x: clip; }` is the third, independent layer:
 * even if a table somehow reaches the page without either mechanism above
 * catching it, the page body still cannot scroll horizontally.
 *
 * @param string $content Post content, already shortcode-expanded.
 * @return string
 */
function blueline_sp_wrap_tables_for_scroll( $content ) {
	if ( false === strpos( $content, '<table' ) ) {
		return $content;
	}

	if ( false !== strpos( $content, 'class="sp-table-wrapper"' ) ) {
		$content = str_replace( 'class="sp-table-wrapper"', 'class="sp-table-wrapper bl-table-scroll"', $content );
	}

	// Cheap pre-check before the HTML parse below: nothing
	// SportsPress renders is ever without an sp- prefixed class somewhere,
	// so content with no "sp-" substring at all cannot contain a
	// SportsPress table and the expensive parse is skipped entirely. An
	// ordinary blog post with a block-editor table (<table
	// class="wp-block-table">, no "sp-" anywhere) never reaches
	// the parser at all.
	if ( false === strpos( $content, 'sp-' ) ) {
		return $content;
	}

	return blueline_sp_ensure_tables_scroll( $content );
}

/**
 * Walk every <table> in $content and make sure every SportsPress one (its
 * own class, or an ancestor's, starts with "sp-") has a scroll-capable
 * ancestor: either it's already inside something carrying bl-table-scroll
 * (added above, or by any future mechanism) or bl-table-self-scroll, or it
 * gets bl-table-self-scroll added directly to itself. A table that is NOT
 * SportsPress's own output (an ordinary block-editor table, for instance)
 * is left completely untouched; see
 * blueline_sp_wrap_tables_for_scroll()'s docblock for why this exists.
 *
 * Uses core's HTML5 parser, which only rewrites the class attribute of the
 * tables it changes, so the rest of the post body (inline SVG, <template>,
 * custom elements, entities, script bodies) comes back byte-for-byte. The
 * old libxml (HTML4) round trip re-serialised the whole body; it now runs
 * only for markup WP_HTML_Processor refuses (e.g. text foster-parented out
 * of a table), so those posts behave exactly as before.
 *
 * @param string $content Post content.
 * @return string
 */
function blueline_sp_ensure_tables_scroll( $content ) {
	$updated = blueline_sp_tables_scroll_html5( (string) $content );

	if ( null !== $updated ) {
		return $updated;
	}

	if ( ! class_exists( 'DOMDocument' ) ) {
		// ext-dom unavailable: the html{overflow-x:clip} CSS backstop still protects the invariant.
		return $content;
	}

	return blueline_sp_tables_scroll_dom( $content );
}

/**
 * The HTML5 pass behind blueline_sp_ensure_tables_scroll(): same decision
 * as the DOM pass, with the ancestor walk done over the parser's own stack
 * of open elements.
 *
 * @param string $content Post content.
 * @return string|null Updated content, or null when the HTML API is missing
 *                     or cannot parse this markup.
 */
function blueline_sp_tables_scroll_html5( string $content ): ?string {
	if ( ! class_exists( 'WP_HTML_Processor' ) ) {
		return null;
	}

	$processor = WP_HTML_Processor::create_fragment( $content );

	if ( null === $processor ) {
		return null;
	}

	$open    = array(); // Depth => class flags of each currently open element.
	$changed = false;

	while ( $processor->next_tag() ) {
		$depth   = $processor->get_current_depth();
		$open    = array_filter(
			$open,
			static function ( $open_depth ) use ( $depth ) {
				return $open_depth < $depth;
			},
			ARRAY_FILTER_USE_KEY
		);
		$element = blueline_sp_html5_class_flags( $processor );

		if ( 'TABLE' === $processor->get_tag() && blueline_sp_table_needs_self_scroll( $element, $open ) ) {
			if ( ! $processor->has_class( 'bl-table-self-scroll' ) ) {
				$processor->add_class( 'bl-table-self-scroll' );
				$changed = true;
			}
			$element['scroll'] = true;
		}

		$open[ $depth ] = $element;
	}

	if ( null !== $processor->get_last_error() ) {
		return null;
	}

	return $changed ? $processor->get_updated_html() : $content;
}

/**
 * Class flags of the element the processor is on: does any class start with
 * "sp-", and is it a scroll container (bl-table-scroll / bl-table-self-scroll)?
 *
 * @param WP_HTML_Processor $processor Processor paused on a tag opener.
 * @return array{sp: bool, scroll: bool}
 */
function blueline_sp_html5_class_flags( WP_HTML_Processor $processor ): array {
	$flags = array(
		'sp'     => false,
		'scroll' => false,
	);

	foreach ( $processor->class_list() ?? array() as $class_name ) {
		$flags['sp']     = $flags['sp'] || 0 === strpos( $class_name, 'sp-' );
		$flags['scroll'] = $flags['scroll'] || in_array( $class_name, array( 'bl-table-scroll', 'bl-table-self-scroll' ), true );
	}

	return $flags;
}

/**
 * Whether a table needs bl-table-self-scroll: it is SportsPress's own (an
 * sp- class on itself or an ancestor) and no ancestor already scrolls.
 *
 * @param array{sp: bool, scroll: bool}   $table     The table's own flags.
 * @param array{sp: bool, scroll: bool}[] $ancestors Flags of its open ancestors.
 * @return bool
 */
function blueline_sp_table_needs_self_scroll( array $table, array $ancestors ): bool {
	$is_sportspress = $table['sp'];

	foreach ( $ancestors as $ancestor ) {
		if ( $ancestor['scroll'] ) {
			return false;
		}
		$is_sportspress = $is_sportspress || $ancestor['sp'];
	}

	return $is_sportspress;
}

/**
 * Whether a <table> is SportsPress's own output: does it (or an ancestor)
 * carry a class starting with "sp-"? SportsPress's class-name convention is
 * universal across every table-producing template in the installed plugin
 * (sp-data-table, sp-league-table, sp-event-blocks, sp-event-calendar,
 * sp-player-list, sp-player-statistics, sp-event-details, sp-event-venue,
 * always directly on the <table> element itself), so this is a durable
 * signal that survives a future SportsPress markup change, unlike matching
 * one specific class. An ordinary WordPress block-editor table
 * (<table class="wp-block-table">) never has one, at any ancestor depth.
 *
 * @param DOMElement $table Table element to check.
 * @return bool
 */
function blueline_dom_table_is_sportspress( DOMElement $table ) {
	if ( blueline_dom_has_class_prefix( $table, 'sp-' ) ) {
		return true;
	}

	$node = $table->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.

	while ( $node instanceof DOMElement ) {
		if ( blueline_dom_has_class_prefix( $node, 'sp-' ) ) {
			return true;
		}
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
	}

	return false;
}

/**
 * The legacy libxml pass, kept for markup WP_HTML_Processor refuses: walk
 * every <table> in $content and make sure every SportsPress one (see
 * blueline_dom_table_is_sportspress()) has a scroll-capable ancestor:
 * either it's already inside something carrying bl-table-scroll (added
 * above, or by any future mechanism) or bl-table-self-scroll, or it gets
 * bl-table-self-scroll added directly to itself. A table that is NOT
 * SportsPress's own output (an ordinary block-editor table, for instance)
 * is left completely untouched; see
 * blueline_sp_wrap_tables_for_scroll()'s docblock for why this exists.
 *
 * @param string $content Post content.
 * @return string
 */
function blueline_sp_tables_scroll_dom( $content ) {
	$libxml_state = libxml_use_internal_errors( true );

	$dom    = new DOMDocument();
	$loaded = $dom->loadHTML(
		'<?xml encoding="utf-8" ?><div id="blueline-scroll-root">' . $content . '</div>',
		LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);

	libxml_clear_errors();
	libxml_use_internal_errors( $libxml_state );

	if ( ! $loaded ) {
		// Malformed fragment: leave content untouched rather than risk
		// corrupting it; the CSS backstop still applies.
		return $content;
	}

	$xpath  = new DOMXPath( $dom );
	$tables = $xpath->query( '//table' );

	if ( 0 === $tables->length ) {
		return $content;
	}

	$changed = false;

	foreach ( $tables as $table ) {
		if ( ! $table instanceof DOMElement ) {
			continue;
		}

		if ( ! blueline_dom_table_is_sportspress( $table ) ) {
			continue; // Not SportsPress's own markup (e.g. an ordinary block-editor table). Leave it untouched.
		}

		if ( blueline_dom_find_class_ancestor( $table, 'bl-table-scroll' )
			|| blueline_dom_find_class_ancestor( $table, 'bl-table-self-scroll' ) ) {
			continue; // Already inside a scroll container.
		}

		blueline_dom_add_class( $table, 'bl-table-self-scroll' );
		$changed = true;
	}

	if ( ! $changed ) {
		return $content;
	}

	$root = $xpath->query( '//div[@id="blueline-scroll-root"]' )->item( 0 );

	if ( ! $root ) {
		return $content;
	}

	$html = '';
	foreach ( $root->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
		$html .= $dom->saveHTML( $child );
	}

	return $html;
}

/**
 * Find the nearest ancestor element carrying a given class, or null.
 *
 * @param DOMNode $node       Node to search upward from (its own classes are not checked).
 * @param string  $class_name Class name to look for.
 * @return DOMElement|null
 */
function blueline_dom_find_class_ancestor( $node, $class_name ) {
	$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.

	while ( $node instanceof DOMElement ) {
		if ( blueline_dom_has_class( $node, $class_name ) ) {
			return $node;
		}
		$node = $node->parentNode; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOMNode property, cannot be renamed.
	}

	return null;
}

/**
 * Whether a DOM element's class attribute contains a given class token.
 *
 * @param DOMElement $element    Element to check.
 * @param string     $class_name Class name to look for.
 * @return bool
 */
function blueline_dom_has_class( DOMElement $element, $class_name ) {
	$classes = preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

	return in_array( $class_name, $classes, true );
}

/**
 * Whether a DOM element's class attribute contains any class token starting
 * with a given prefix (e.g. "sp-"). Token-exact prefix matching: a class
 * like "responsive-table" does NOT match prefix "sp-" just because that
 * substring appears mid-word ("re-sp-onsive"); only a class that itself
 * starts with "sp-" counts.
 *
 * @param DOMElement $element Element to check.
 * @param string     $prefix  Prefix to look for.
 * @return bool
 */
function blueline_dom_has_class_prefix( DOMElement $element, $prefix ) {
	$classes = preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

	foreach ( $classes as $class_name ) {
		if ( 0 === strpos( $class_name, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Add a class token to a DOM element, without duplicating it if present.
 *
 * @param DOMElement $element    Element to modify.
 * @param string     $class_name Class name to add.
 */
function blueline_dom_add_class( DOMElement $element, $class_name ) {
	if ( blueline_dom_has_class( $element, $class_name ) ) {
		return;
	}

	$existing = trim( (string) $element->getAttribute( 'class' ) );
	$element->setAttribute( 'class', ( '' !== $existing ? $existing . ' ' : '' ) . $class_name );
}
