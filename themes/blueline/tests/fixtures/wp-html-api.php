<?php
/**
 * Loads WordPress core's real HTML API (WP_HTML_Tag_Processor,
 * WP_HTML_Processor) from the pinned johnpbloch/wordpress-core dev
 * dependency (same version as staging), in core's own wp-settings.php
 * order, plus stand-ins for the four core functions it calls.
 *
 * Only theme code that checks class_exists( 'WP_HTML_Processor' ) sees a
 * difference, so loading it into the shared test process is safe.
 *
 * @package blueline
 */

if ( ! class_exists( 'WP_HTML_Processor' ) ) {
	$blueline_wp_includes = dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes';

	require_once $blueline_wp_includes . '/compat-utf8.php';
	require_once $blueline_wp_includes . '/class-wp-token-map.php';

	foreach (
		array(
			'html5-named-character-references',
			'class-wp-html-attribute-token',
			'class-wp-html-span',
			'class-wp-html-doctype-info',
			'class-wp-html-text-replacement',
			'class-wp-html-decoder',
			'class-wp-html-tag-processor',
			'class-wp-html-unsupported-exception',
			'class-wp-html-active-formatting-elements',
			'class-wp-html-open-elements',
			'class-wp-html-token',
			'class-wp-html-stack-event',
			'class-wp-html-processor-state',
			'class-wp-html-processor',
		) as $blueline_html_api_file
	) {
		require_once $blueline_wp_includes . '/html-api/' . $blueline_html_api_file . '.php';
	}
}

$GLOBALS['bl_test_html_api_notices'] = array();

if ( ! function_exists( '_doing_it_wrong' ) ) {
	/**
	 * Records core's _doing_it_wrong() notices so tests can assert none.
	 *
	 * @param string $function_name Function name.
	 * @param string $message       Message.
	 * @param string $version       Version.
	 */
	function _doing_it_wrong( $function_name, $message, $version ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core.
		$GLOBALS['bl_test_html_api_notices'][] = $function_name . ': ' . $message;
	}
}

if ( ! function_exists( 'wp_trigger_error' ) ) {
	/**
	 * Records core's wp_trigger_error() calls so tests can assert none.
	 *
	 * @param string $function_name Function name.
	 * @param string $message       Message.
	 * @param int    $error_level   Error level.
	 */
	function wp_trigger_error( $function_name, $message, $error_level = E_USER_NOTICE ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core.
		$GLOBALS['bl_test_html_api_notices'][] = $function_name . ': ' . $message;
	}
}

if ( ! function_exists( 'wp_has_noncharacters' ) ) {
	/**
	 * Core's utf8.php wrapper, minus its PCRE probe: the pure fallback.
	 *
	 * @param string $text Text to scan.
	 * @return bool
	 */
	function wp_has_noncharacters( string $text ): bool {
		return _wp_has_noncharacters_fallback( $text );
	}
}

if ( ! function_exists( 'wp_kses_uri_attributes' ) ) {
	/**
	 * Core's default URI-valued attribute list (kses.php), without its filter.
	 *
	 * @return string[]
	 */
	function wp_kses_uri_attributes() {
		return array( 'action', 'archive', 'background', 'cite', 'classid', 'codebase', 'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest', 'poster', 'profile', 'src', 'usemap', 'xmlns' );
	}
}
