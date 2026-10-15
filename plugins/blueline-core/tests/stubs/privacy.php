<?php
/**
 * Stubs for the privacy module's version masking.
 *
 * @package blueline-core
 */

if ( ! function_exists( 'wp_hash' ) ) {
	/**
	 * Stand-in for wp_hash(): an HMAC of the data under a fixed test salt, hex like core's.
	 *
	 * @param string $data   Data to hash.
	 * @param string $scheme Salt scheme (unused; one fixed salt here).
	 * @return string
	 */
	function wp_hash( $data, $scheme = 'auth' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core.
		return hash_hmac( 'md5', (string) $data, 'blueline-core-test-salt' );
	}
}
