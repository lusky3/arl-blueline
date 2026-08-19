<?php
/**
 * Guards against the class of bug behind two fix rounds in a row:
 * inc/settings/links.php and inc/settings/sanitize.php both existed, were
 * fully covered by tests, and were never require_once'd from functions.php
 * -- so the whole 243-test suite stayed green while a real page load would
 * fatal on "call to undefined function". The suite structurally cannot see
 * this bug on its own: every test file requires the exact files it needs
 * directly, bypassing functions.php's require chain entirely, so a file
 * functions.php forgets is still loaded by the test that exercises it.
 *
 * This test closes that blind spot the only way that generalizes: it reads
 * inc/ off disk at runtime -- not a hardcoded file list, which would carry
 * the identical blind spot for the next new file -- and asserts every .php
 * file under it is referenced somewhere in functions.php.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Fails, naming the file, if any .php file under inc/ (at any depth --
 * inc/account/, inc/settings/, and any future subdirectory such as Task
 * 10's inc/cli/) is absent from functions.php's require_once chain.
 *
 * Deliberately NOT restricted to inc/settings/: that would fix today's two
 * offenders but leave inc/account/ (already fully covered, so this scan
 * proves it, not just assumes it) and any future subdirectory unguarded --
 * inc/cli/ is coming in Task 10, and this test should already cover it the
 * day it lands.
 *
 * The check is "does functions.php contain this path as a quoted string
 * literal anywhere", not "is there an unconditional require_once at file
 * scope" -- so a file that's deliberately conditionally loaded (Task 10's
 * WP-CLI file, expected to be wrapped in `if ( defined( 'WP_CLI' ) ) { ... }`)
 * still satisfies this test as soon as its path is written down anywhere in
 * functions.php, guarded or not. What this test cannot tolerate is a file
 * that exists on disk and is never mentioned in functions.php at all --
 * which is exactly the bug it exists to catch.
 *
 * Comments do not count as "written down": the source is run through
 * PHP's own tokenizer first and every T_COMMENT/T_DOC_COMMENT token is
 * discarded before the path scan runs, so
 * `// require_once BLUELINE_DIR . '/inc/settings/sanitize.php';` -- a file
 * present on disk, not actually loaded, which is precisely the failure
 * mode this guard exists to catch -- fails this test rather than quietly
 * satisfying it. token_get_all() is used rather than a regex-based
 * comment stripper because it already understands PHP's own lexical
 * grammar (a `//` inside a string literal is not a comment; `token_get_all()`
 * never mistakes the two the way a hand-rolled regex could).
 */
final class IncRequireCoverageTest extends TestCase {

	/**
	 * Reads inc/ off disk and functions.php's own require_once chain (with
	 * comments discarded first), then asserts the two agree -- naming any
	 * file present on disk but absent from functions.php's live code.
	 */
	public function test_every_inc_file_is_required_from_functions_php(): void {
		$root          = dirname( __DIR__ );
		$functions_src = (string) file_get_contents( $root . '/functions.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

		$live_src = '';
		foreach ( token_get_all( $functions_src ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue; // Drop comments -- a commented-out require must not count as "referenced".
				}
				$live_src .= $token[1];
			} else {
				$live_src .= $token;
			}
		}

		// Every '/inc/...php' quoted string literal in functions.php's live
		// (non-comment) code, whatever require_once expression it's embedded
		// in and whatever conditional (if any) wraps that expression.
		preg_match_all( "#['\"](/inc/[^'\"]+\.php)['\"]#", $live_src, $matches );
		$referenced = array_flip( $matches[1] );

		$inc_dir = $root . '/inc';
		$this->assertDirectoryExists( $inc_dir, 'expected theme to have an inc/ directory to scan' );

		$missing  = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $inc_dir ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = '/inc' . substr( $file->getPathname(), strlen( $inc_dir ) );

			if ( ! isset( $referenced[ $relative ] ) ) {
				$missing[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			'these inc/ files exist on disk but functions.php never requires them -- a real page load would fatal ("call to undefined function") the moment anything calls into them, even though the test suite (which requires files directly, not through functions.php) stays green'
		);
	}
}
