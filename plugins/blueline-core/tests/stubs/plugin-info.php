<?php
/**
 * Stubs for the plugin-info module's tests.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'get_file_data' ) ) {
	/**
	 * Stand-in for get_file_data(): reads "Header: value" lines from the file's first 8 KB.
	 *
	 * @param string               $file        File to read.
	 * @param array<string,string> $all_headers Key => header name.
	 * @param string               $context     Unused.
	 * @return array<string,string>
	 */
	function get_file_data( $file, $all_headers, $context = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core.
		$data = (string) file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test stub reading a local file.
		$out  = array();

		foreach ( $all_headers as $key => $header ) {
			$out[ $key ] = 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $data, $m ) ? trim( $m[1] ) : '';
		}

		return $out;
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Stand-in for plugin_basename(): `<folder>/<file>`.
	 *
	 * @param string $file Absolute plugin file.
	 * @return string
	 */
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	/**
	 * Stand-in for plugins_url().
	 *
	 * @param string $path   Path inside the plugin.
	 * @param string $plugin A file inside the plugin.
	 * @return string
	 */
	function plugins_url( $path = '', $plugin = '' ) {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $plugin ) ) . '/' . ltrim( $path, '/' );
	}
}
