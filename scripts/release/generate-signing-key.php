<?php
/**
 * Generate the Blueline release signing keypair. The OWNER runs this, once,
 * on their own machine; CI and Claude never do.
 *
 *   php scripts/release/generate-signing-key.php [private-key-file]
 *
 * stdout: ONLY the public key (base64), in a block to paste into
 * BLUELINE_RELEASE_PUBLIC_KEYS in themes/blueline/inc/updater-rules.php.
 *
 * The private key (libsodium's 64-byte ed25519 secret key, base64) is written
 * once: to private-key-file when given (created with mode 0600; an existing
 * file is never overwritten), otherwise to stderr. Store it in Bitwarden or
 * Doppler, set it as the RELEASE_SIGNING_KEY Actions secret, then delete any
 * local copy. Full ceremony: docs/RELEASING.md, "Release signing".
 *
 * Exit 0 = generated, 1 = could not write the private key (nothing printed), 2 = usage.
 *
 * @package blueline
 */

if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	if ( count( $argv ) > 2 ) {
		fwrite( STDERR, "usage: php generate-signing-key.php [private-key-file]\n" );
		exit( 2 );
	}
	if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
		fwrite( STDERR, "generate-signing-key: PHP's sodium extension is required.\n" );
		exit( 2 );
	}

	$pair    = sodium_crypto_sign_keypair();
	$public  = base64_encode( sodium_crypto_sign_publickey( $pair ) );
	$private = base64_encode( sodium_crypto_sign_secretkey( $pair ) );
	sodium_memzero( $pair );

	$out  = $argv[1] ?? '';
	$next = "Next:\n"
		. "  1. Save the private key in Bitwarden or Doppler (a secure note named\n"
		. "     \"Blueline release signing key\", with today's date and the public key).\n"
		. "  2. Give it to GitHub Actions as the repository secret RELEASE_SIGNING_KEY:\n"
		. "       gh secret set RELEASE_SIGNING_KEY --repo lusky3/arl-blueline < <private-key-file>\n"
		. "     or paste it at github.com/lusky3/arl-blueline > Settings > Secrets and\n"
		. "     variables > Actions > New repository secret.\n"
		. "  3. Delete every local copy (the file, this terminal's scrollback).\n"
		. "  4. Open a PR adding the public key printed on stdout to\n"
		. "     BLUELINE_RELEASE_PUBLIC_KEYS in themes/blueline/inc/updater-rules.php.\n"
		. "Never commit, paste into a chat, or email the private key.\n";

	if ( '' !== $out ) {
		$old_umask = umask( 0077 );
		$handle    = @fopen( $out, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is reported below; 'x' refuses an existing file.
		umask( $old_umask );

		if ( false === $handle ) {
			sodium_memzero( $private );
			fwrite( STDERR, "generate-signing-key: could not create $out (it may already exist; this script never overwrites a key file). No key was printed; run it again with a new path.\n" );
			exit( 1 );
		}
		$written = chmod( $out, 0600 ) && false !== fwrite( $handle, $private . "\n" );
		if ( ! fclose( $handle ) || ! $written ) {
			unlink( $out );
			sodium_memzero( $private );
			fwrite( STDERR, "generate-signing-key: could not write $out; it was removed. No key was printed.\n" );
			exit( 1 );
		}

		fwrite( STDERR, "\n==== PRIVATE KEY written to $out (mode 0600) ====\n" . $next . "\n" );
	} else {
		fwrite(
			STDERR,
			"\n==== PRIVATE KEY: shown ONCE, never stored by this script ====\n"
			. $private . "\n"
			. "==== END PRIVATE KEY ====\n"
			. $next . "\n"
		);
	}
	sodium_memzero( $private );

	echo "==== PUBLIC KEY: put this in themes/blueline/inc/updater-rules.php ====\n";
	echo "Add it as one entry of BLUELINE_RELEASE_PUBLIC_KEYS (base64, safe to publish):\n\n";
	echo "\t\t'" . $public . "',\n\n";
	echo "==== END PUBLIC KEY ====\n";
	exit( 0 );
}
