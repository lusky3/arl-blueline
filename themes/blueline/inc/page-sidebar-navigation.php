<?php
/**
 * Content-driven "on this page" jump navigation.
 *
 * Pages with several distinct sections (Arena Maps, the full Standings
 * page, FAQs) get a sticky jump-nav rail instead of the plain widget
 * rail. Which pages qualify is never a hardcoded slug/ID list -- it is
 * decided from the page's own rendered heading structure, so it can
 * never drift out of sync with which pages actually have something to
 * jump between.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores/retrieves the current request's own on-page heading anchors.
 * blueline_inject_page_heading_anchors() populates this the moment
 * the_content() runs; sidebar.php reads it back afterward. the_content()
 * always runs before get_sidebar() in every template that calls both
 * (page.php via content-page.php), so this ordering is safe without
 * passing an object between the two.
 *
 * @param array<int, array{id:string, label:string}>|null $set When given,
 *        replaces the stored list. Only blueline_inject_page_heading_anchors()
 *        should pass this.
 * @return array<int, array{id:string, label:string}>
 */
function blueline_page_heading_anchors( $set = null ) {
	static $anchors = array();

	if ( null !== $set ) {
		$anchors = $set;
	}

	return $anchors;
}

/**
 * Pure: finds every top-level <h2>/<h3> in a block of rendered content
 * HTML, assigns each one a stable, unique id (an existing id is left
 * untouched), and returns both the rewritten HTML and the anchor list.
 *
 * A plain regex, not DOMDocument: this only ever rewrites the opening tag
 * of a small, well-formed set of heading elements a human authored
 * through the editor, never arbitrary or malformed markup, so parsing a
 * full HTML5 document (with DOMDocument's encoding and implied
 * <html>/<body> quirks) would solve a harder problem than the one that
 * actually exists here.
 *
 * Each anchor also carries the heading's own level (2 or 3) so a long,
 * two-tier page (FAQs: <h2> topics grouping dozens of <h3> questions,
 * confirmed live -- 36 anchors on one page) can render its <h2> entries
 * as section headers rather than as 36 visually identical list rows.
 *
 * @param string $html Rendered content HTML.
 * @return array{html:string, anchors:array<int, array{id:string, label:string, level:int}>}
 */
function blueline_extract_and_anchor_headings( $html ) {
	$anchors  = array();
	$seen_ids = array();

	$html = (string) preg_replace_callback(
		'/<h([23])\b([^>]*)>(.*?)<\/h\1>/is',
		static function ( $m ) use ( &$anchors, &$seen_ids ) {
			$level = $m[1];
			$attrs = $m[2];
			$inner = $m[3];

			/*
			 * A heading is sometimes two related questions joined by a
			 * <br><em>or</em><br> (confirmed live on /faqs, e.g. "...current
			 * installments status?" / "...make an installment payment?") --
			 * wp_strip_all_tags() alone would glue them into one run-on
			 * string with no space where the tag was. Normalising break-like
			 * tags to a literal space first, then collapsing repeats, keeps
			 * the jump-nav label readable.
			 */
			$label = preg_replace( '#<(br|/p|/div|/li)\b[^>]*>#i', ' ', $inner );
			$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $label ) ) );

			if ( '' === $label ) {
				return $m[0]; // Nothing to link to or label a jump-nav entry with.
			}

			if ( preg_match( '/\bid\s*=\s*"([^"]*)"/i', $attrs, $id_match ) && '' !== $id_match[1] ) {
				$id = $id_match[1]; // Respect an id the content already carries.
			} else {
				$base_id = sanitize_title( $label );
				$id      = '' !== $base_id ? $base_id : 'section';
				$suffix  = 2;
				while ( isset( $seen_ids[ $id ] ) ) {
					$id = ( '' !== $base_id ? $base_id : 'section' ) . '-' . $suffix;
					++$suffix;
				}
				$attrs = ' id="' . esc_attr( $id ) . '"' . $attrs;
			}

			$seen_ids[ $id ] = true;
			$anchors[]       = array(
				'id'    => $id,
				'label' => $label,
				'level' => (int) $level,
			);

			return '<h' . $level . $attrs . '>' . $inner . '</h' . $level . '>';
		},
		$html
	);

	return array(
		'html'    => $html,
		'anchors' => $anchors,
	);
}

/**
 * Rewrites the current Page's own <h2>/<h3> headings with stable ids and
 * records the resulting anchor list for sidebar.php to read back --
 * registered on `the_content`. Scoped to a real, singular Page in the main
 * loop only, never an excerpt, a widget-rendered teaser, or another post
 * type's content running through this same core filter.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function blueline_inject_page_heading_anchors( $content ) {
	if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$result = blueline_extract_and_anchor_headings( $content );

	blueline_page_heading_anchors( $result['anchors'] );

	return $result['html'];
}
add_filter( 'the_content', 'blueline_inject_page_heading_anchors', 20 );

/**
 * Whether the current page's own content had enough distinct sections to
 * justify an "on this page" navigation rail instead of the plain widget
 * rail -- a single heading (or none) has nothing worth jumping between.
 *
 * @return bool
 */
function blueline_page_needs_jump_nav() {
	return count( blueline_page_heading_anchors() ) >= 2;
}

/**
 * Renders the "on this page" jump-nav list -- sidebar.php's alternate
 * rail for pages blueline_page_needs_jump_nav() singles out. Reuses the
 * same skew-label device (.bl-skew) as the rest of the theme's eyebrows
 * and active nav labels, so the two sidebar rails read as one system.
 *
 * @return void
 */
function blueline_render_page_jump_nav() {
	$anchors = blueline_page_heading_anchors();

	if ( count( $anchors ) < 2 ) {
		return;
	}
	?>
	<nav class="bl-jump-nav" aria-label="<?php esc_attr_e( 'On this page', 'blueline' ); ?>">
		<span class="bl-jump-nav__label bl-skew"><span><?php esc_html_e( 'On this page', 'blueline' ); ?></span></span>
		<ul class="bl-jump-nav__list">
			<?php foreach ( $anchors as $anchor ) : ?>
				<li<?php echo 3 === $anchor['level'] ? ' class="bl-jump-nav__item--sub"' : ''; ?>><a href="#<?php echo esc_attr( $anchor['id'] ); ?>"><?php echo esc_html( $anchor['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php
}
