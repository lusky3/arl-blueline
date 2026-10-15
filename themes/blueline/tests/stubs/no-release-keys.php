<?php
/**
 * Test stub: run with no release signing keys.
 *
 * The updater rules file defines BLUELINE_RELEASE_PUBLIC_KEYS (the shipped key list) only when it is
 * not already defined. Most updater tests describe the unsigned baseline, so they require this file
 * first. UpdaterSignatureTest::test_the_shipped_key_list_is_valid reads the shipped list from the
 * source instead, and UpdaterSignedClientTest defines its own keys in a separate process.
 *
 * @package blueline
 */

if ( ! defined( 'BLUELINE_RELEASE_PUBLIC_KEYS' ) ) {
	define( 'BLUELINE_RELEASE_PUBLIC_KEYS', array() );
}
