<?php
/**
 * Guards against the class of bug behind three separate fix rounds in a
 * row: a third-party plugin active on the production/staging install
 * (Capabilities Pro's own admin-notices "declutter" module) removes, on
 * every wp-admin screen, any `<div>` whose `class` attribute contains
 * "notice", "error", "warning", "info" or "updated" as a SUBSTRING
 * anywhere -- sweeping it into its own "Notice Center" panel instead of
 * leaving it in the DOM. inc/settings/page.php's error summary AND its
 * "Settings saved." success notice, and inc/settings/cache.php's manual
 * page-cache-purge notice (the entire shipped behaviour of that
 * requirement, since the guarded automatic purge ships disabled), were
 * each hit by this independently before this test existed -- each fixed
 * one at a time, each confirmed only by an actual browser click-through
 * against a real WordPress request, because a PHPUnit test rendering
 * markup in isolation cannot see a third-party plugin's own JS at all.
 *
 * A prior version of this guard existed as
 * SettingsPageTest::test_error_summary_is_never_a_div() -- one test
 * pinning one element's id. That guarded the INSTANCE, not the class of
 * bug, which is exactly why the success and cache-purge notices slipped
 * through it. This file replaces that with a source scan across every
 * PHP file this theme ships, catching the pattern itself rather than one
 * known occurrence of it -- so the next admin-facing notice this theme
 * ever adds is covered from the moment it's written, with no test to
 * remember to add.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Scans every theme-authored .php file for a `<div>` tag whose `class`
 * attribute contains one of the five substrings WordPress' own `.notice`
 * convention (and the third-party plugin that preys on it) both key off
 * of, failing with the exact file and line of each offender.
 *
 * Deliberately blunt and un-scoped by directory: it would have been
 * tempting to only scan `inc/settings/` (where every offender so far has
 * lived) or to skip front-end-only files (which the specific
 * wp-admin-only plugin behind this bug can never reach), but a
 * conditional exemption like that is precisely the kind of judgment call
 * that let three separate instances of this same bug ship one at a time.
 * The rule is unconditional instead: no theme-emitted `<div>` may ever
 * carry one of these five substrings in its class attribute, anywhere,
 * full stop -- any admin (or, in principle, front-end) notice this theme
 * renders uses a `<section>` (or another non-`div` element) with the same
 * classes for styling, which every affected stylesheet rule already
 * targets by class alone, with no tag qualifier (verified for each
 * offender fixed alongside this test).
 */
final class NoticeDivGuardTest extends TestCase {

	/**
	 * Substrings that must never appear in a theme-emitted `<div>`'s class
	 * attribute -- the exact set Capabilities Pro's admin-notices module
	 * (confirmed by reading its own JS source directly off the production
	 * install) checks a `<div>`'s class against before removing it.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_SUBSTRINGS = array( 'notice', 'error', 'warning', 'info', 'updated' );

	/**
	 * Theme-relative directories (and, for node_modules, an anywhere-match)
	 * excluded from the scan: vendor and node_modules ship third-party code
	 * this theme did not author; assets/dist is webpack's compiled output,
	 * not authored source; tests/ is never rendered to a real page, so a
	 * fixture string or a docblock explaining this very bug (like the one
	 * at the top of this file) must not be able to trip the guard it is
	 * describing.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_PATH_FRAGMENTS = array( '/vendor/', '/node_modules/', '/assets/dist/', '/tests/' );

	/**
	 * Every `<div ...>` opening tag, spanning multiple lines if needed, with
	 * its attribute text captured for a `class="..."` search.
	 */
	private const DIV_TAG_PATTERN = '/<div\b([^>]*)>/is';

	/**
	 * A `class="..."` attribute's value, from the attribute text captured
	 * above.
	 */
	private const CLASS_ATTR_PATTERN = '/class\s*=\s*"([^"]*)"/i';

	/**
	 * Scans every theme PHP file (see blueline_notice_div_guard_theme_root())
	 * and asserts none of them emit a `<div>` matching the forbidden class
	 * pattern. Comments (PHP `//`, `#`, `/* *\/` and docblocks) are
	 * stripped before scanning -- via PHP's own tokenizer, the same
	 * technique IncRequireCoverageTest uses and for the same reason: a
	 * comment merely MENTIONING `<div class="notice">` as prose (exactly
	 * what several docblocks alongside this fix now do) must not itself
	 * satisfy or fail this guard.
	 */
	public function test_no_theme_php_file_emits_a_forbidden_notice_div(): void {
		$root = dirname( __DIR__ );

		$violations = array();

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = $file->getPathname();

			foreach ( self::EXCLUDED_PATH_FRAGMENTS as $fragment ) {
				if ( str_contains( $path, $fragment ) ) {
					continue 2;
				}
			}

			$relative = ltrim( str_replace( $root, '', $path ), '/' );

			foreach ( $this->find_forbidden_notice_divs( (string) file_get_contents( $path ) ) as $found ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test, not an HTTP fetch.
				$violations[] = sprintf(
					'%s:%d: <div class="%s">',
					$relative,
					$found['line'],
					$found['class']
				);
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"the following <div> tags carry a class matching notice/error/warning/info/updated -- a third-party wp-admin plugin on the production install removes exactly this pattern from the DOM; use a <section> (or another non-div element) with the same classes instead:\n" . implode( "\n", $violations )
		);
	}

	/**
	 * Finds every forbidden-class `<div>` in one file's source, returning
	 * each with its 1-based line number.
	 *
	 * @param string $source Raw file contents.
	 * @return array<int, array{line:int, class:string}>
	 */
	private function find_forbidden_notice_divs( string $source ): array {
		$live = $this->strip_comments_preserving_lines( $source );

		if ( ! preg_match_all( self::DIV_TAG_PATTERN, $live, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$found = array();
		foreach ( $matches[1] as $attr_match ) {
			list( $attrs, $offset ) = $attr_match;

			if ( ! preg_match( self::CLASS_ATTR_PATTERN, $attrs, $class_match ) ) {
				continue;
			}

			$class = $class_match[1];
			foreach ( self::FORBIDDEN_SUBSTRINGS as $needle ) {
				if ( false !== stripos( $class, $needle ) ) {
					$found[] = array(
						'line'  => substr_count( $live, "\n", 0, $offset ) + 1,
						'class' => $class,
					);
					break; // One report per offending <div>, even if it matches more than one substring.
				}
			}
		}

		return $found;
	}

	/**
	 * Strips every T_COMMENT/T_DOC_COMMENT token from PHP source, exactly
	 * as IncRequireCoverageTest does, but replaces each with a matching
	 * count of newline characters (rather than removing it outright) so
	 * every remaining line's number stays accurate -- required here,
	 * unlike that test, because this one reports a line number for each
	 * finding.
	 *
	 * @param string $source Raw file contents.
	 * @return string Source with comment text blanked but line count intact.
	 */
	private function strip_comments_preserving_lines( string $source ): string {
		$live = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					$live .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
					continue;
				}
				$live .= $token[1];
			} else {
				$live .= $token;
			}
		}
		return $live;
	}
}
