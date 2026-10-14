<?php
/**
 * Loads WordPress core's real HTML API (WP_HTML_Tag_Processor,
 * WP_HTML_Processor) from the pinned johnpbloch/wordpress-core dev
 * dependency, in core's own wp-settings.php
 * order, plus stand-ins for the core functions it calls that live outside
 * the HTML API (and that a full WordPress bootstrap would otherwise supply).
 *
 * Only theme code that checks class_exists( 'WP_HTML_Processor' ) sees a
 * difference, so loading it into the shared test process is safe.
 *
 * @package blueline
 */

if ( ! class_exists( 'WP_HTML_Processor' ) ) {
	$blueline_wp_includes = dirname( __DIR__, 2 ) . '/vendor/johnpbloch/wordpress-core/wp-includes';

	require_once $blueline_wp_includes . '/compat-utf8.php';
	// Core's real wp_has_noncharacters(). Since 7.1 the compat-utf8.php
	// fallback just calls it (and is deprecated), so a stand-in that routed
	// back to the fallback would recurse forever.
	require_once $blueline_wp_includes . '/utf8.php';
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

if ( ! function_exists( '_deprecated_function' ) ) {
	/**
	 * Records core's _deprecated_function() calls so tests can assert none.
	 *
	 * Core 7.1's HTML API calls this from its deprecated utf8 fallback and
	 * from WP_HTML_Open_Elements, so it must exist before any of them run.
	 *
	 * @param string $function_name Function name.
	 * @param string $version       Version deprecated in.
	 * @param string $replacement   Replacement function.
	 */
	function _deprecated_function( $function_name, $version, $replacement = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core.
		$GLOBALS['bl_test_html_api_notices'][] = $function_name . ' is deprecated since ' . $version;
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
