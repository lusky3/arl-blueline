<?php
/**
 * Default team logo: a team with no featured image gets the theme's placeholder
 * badge everywhere a team logo is drawn (hero, flyout, schedules, standings,
 * countdown, SportsPress's own templates), instead of an empty box.
 *
 * Done once at the source rather than per template: every template asks
 * has_post_thumbnail() / get_the_post_thumbnail() on the sp_team post, so a
 * logo-less team reports a placeholder thumbnail id (BLUELINE_DEFAULT_TEAM_LOGO_ID)
 * and the markup/URL filters answer for it. wp_get_attachment_image() returns ''
 * for that id, which is what lets post_thumbnail_html supply the image. Front end
 * only: the admin, REST and the SEO tags keep seeing the real "no logo" state
 * (an SVG is no use as an og:image).
 *
 * Loaded by inc/sportspress.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

const BLUELINE_DEFAULT_TEAM_LOGO_ID = -1;

/**
 * Whether the default logo may stand in for $post's missing thumbnail.
 *
 * @param WP_Post|int|null $post Post object or ID.
 * @return bool
 */
function blueline_default_team_logo_applies( $post ): bool {
	if ( is_admin() || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
		return false;
	}

	return 'sp_team' === get_post_type( $post );
}

/**
 * URL of the bundled placeholder badge (cache-busted).
 *
 * @return string
 */
function blueline_default_team_logo_url(): string {
	$path = BLUELINE_DIR . '/assets/images/default-team-logo.svg';

	// Versioned by mtime so a redesigned badge is not stuck behind a CDN's cached copy.
	return BLUELINE_URI . '/assets/images/default-team-logo.svg?ver=' . ( file_exists( $path ) ? filemtime( $path ) : BLUELINE_VERSION );
}

add_filter( 'post_thumbnail_id', 'blueline_default_team_logo_id', 10, 2 );
/**
 * Report the placeholder id for a team that has no thumbnail.
 *
 * @param int|false        $thumbnail_id Real thumbnail id (0/false when none).
 * @param WP_Post|int|null $post         Post object or ID.
 * @return int|false
 */
function blueline_default_team_logo_id( $thumbnail_id, $post = null ) {
	if ( $thumbnail_id || ! blueline_default_team_logo_applies( $post ) ) {
		return $thumbnail_id;
	}

	return BLUELINE_DEFAULT_TEAM_LOGO_ID;
}

add_filter( 'post_thumbnail_html', 'blueline_default_team_logo_html', 10, 5 );
/**
 * Print the placeholder <img> wherever a team's logo markup came back empty: no logo at all (the
 * placeholder id), or a logo whose image file is missing (e.g. a database copied without uploads).
 *
 * @param string       $html         Markup so far ('' for the placeholder id).
 * @param int          $post_id      Post ID.
 * @param int          $thumbnail_id Thumbnail id.
 * @param string|int[] $size         Requested size.
 * @param string|array $attr         Attributes the caller asked for.
 * @return string
 */
function blueline_default_team_logo_html( $html, $post_id, $thumbnail_id, $size = 'post-thumbnail', $attr = '' ) {
	if ( '' !== $html || ! blueline_default_team_logo_applies( $post_id ) ) {
		return $html;
	}

	$attr      = wp_parse_args( $attr );
	$size_name = is_array( $size ) ? implode( 'x', $size ) : (string) $size;
	$class     = 'attachment-' . $size_name . ' size-' . $size_name . ' wp-post-image bl-default-team-logo';

	if ( ! empty( $attr['class'] ) ) {
		$class .= ' ' . $attr['class'];
	}

	$attrs = array_merge(
		array(
			'src'      => blueline_default_team_logo_url(),
			'alt'      => '',
			'width'    => 96,
			'height'   => 96,
			'loading'  => 'lazy',
			'decoding' => 'async',
		),
		$attr,
		array( 'class' => $class )
	);

	$out = '';
	foreach ( $attrs as $name => $value ) {
		$out .= ' ' . esc_attr( $name ) . '="' . ( 'src' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
	}

	return '<img' . $out . ' />';
}

add_filter( 'post_thumbnail_url', 'blueline_default_team_logo_url_filter', 10, 2 );
/**
 * Answer get_the_post_thumbnail_url() for a team with no usable logo. WordPress only reaches this
 * filter once the thumbnail id is truthy (the placeholder id, or a real id whose file is missing).
 *
 * @param string|false     $url  URL so far (false when WordPress found none).
 * @param WP_Post|int|null $post Post object or ID.
 * @return string|false
 */
function blueline_default_team_logo_url_filter( $url, $post = null ) {
	if ( $url || ! blueline_default_team_logo_applies( $post ) ) {
		return $url;
	}

	return blueline_default_team_logo_url();
}
