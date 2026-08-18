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
	 *
	 * Run against source whose PHP blocks have already been blanked by
	 * blank_php_blocks_preserving_lines(). That step is not cosmetic and this
	 * guard was blind without it: `[^>]*` stops at the FIRST `>`, and in
	 * `<div class="notice notice-<?php echo $type; ?>">` the first `>` is the
	 * one closing `<?php`. The captured attribute text ended mid-string with
	 * an unterminated quote, CLASS_ATTR_PATTERN never matched, and the tag was
	 * skipped in silence. Three of this theme's six notice elements were
	 * unprotected that way -- including the "Settings saved." notice this test
	 * was originally written to stop regressing.
	 */
	private const DIV_TAG_PATTERN = '/<div\b([^>]*)>/is';

	/**
	 * A PHP block appearing inside an attribute value.
	 *
	 * Matched non-greedily and including short echo tags, so
	 * `class="a-<?= $x ?> b"` is handled like `class="a-<?php echo $x; ?> b"`.
	 */
	private const PHP_BLOCK_PATTERN = '/<\?(?:php|=)?.*?(?:\?>|$)/s';

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
	 * No `<div>` may build its class out of PHP unless it is listed below.
	 *
	 * The literal scan above can only see literal text. A class like
	 * `bl-x bl-x--<?php echo $severity; ?>` contains nothing forbidden in the
	 * source and renders as `bl-x--info`, which the plugin strips -- so a
	 * source scan can never clear it, and pretending otherwise is how the
	 * original gap survived. This test therefore fails CLOSED: every `<div>`
	 * whose class contains PHP has to be named here with a reason, so adding
	 * one is a decision somebody made rather than a thing that slipped past.
	 *
	 * The listed entries are layout wrappers whose expressions produce a
	 * closed, known set of tokens, none matching a forbidden substring. They
	 * are not exempt because they are old; they are exempt because their
	 * output was read.
	 *
	 * @return void
	 */
	public function test_no_div_hides_a_forbidden_class_behind_php(): void {
		$allowed = array(
			// Renders '' or ' bl-content-layout--has-sidebar'.
			'bl-content-layout',
			// WooCommerce's own template: renders '' or 'calculated_shipping'.
			'cart_totals',
			// Renders 'content-area-left-sidebar' / '-right-' / '-no-'.
			'woocommerce-shop-content',
		);

		$root       = dirname( __DIR__ );
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

			$source   = $this->strip_comments_preserving_lines( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test, not an HTTP fetch.
			$relative = ltrim( str_replace( $root, '', $path ), '/' );

			if ( ! preg_match_all( '/<div\b[^>]*?class\s*=\s*"([^"]*?<\?[^"]*?)"/is', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			foreach ( $matches[1] as $class_match ) {
				list( $class, $offset ) = $class_match;

				foreach ( $allowed as $known ) {
					if ( str_contains( $class, $known ) ) {
						continue 2;
					}
				}

				$violations[] = sprintf(
					'%s:%d: class="%s"',
					$relative,
					substr_count( $source, "\n", 0, $offset ) + 1,
					preg_replace( '/\s+/', ' ', $class )
				);
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"these <div> tags build a class from PHP, so no source scan can prove the rendered class is safe. Read what the expression can produce; if none of notice/error/warning/info/updated can appear, add its block name to this test's allow-list with that reasoning. If one can, use a <section>:\n" . implode( "\n", $violations )
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
		$live = $this->blank_php_blocks_preserving_lines(
			$this->strip_comments_preserving_lines( $source )
		);

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
	 * Replace every PHP block with spaces, keeping newlines, so an attribute
	 * value containing PHP still reads as one quoted string to the scanner.
	 *
	 * Only the STATIC text of a class attribute survives, which is the honest
	 * limit of a source scan: `class="notice notice-<?php echo $type; ?>"`
	 * becomes `class="notice notice-"` and is correctly caught on the literal
	 * "notice", while `class="bl-x bl-x--<?php echo $severity; ?>"` becomes
	 * `class="bl-x bl-x--"` and is not, because nothing forbidden appears in
	 * the source at all. That residual gap is real -- a dynamic modifier can
	 * render to "info" -- and is why blank_php_blocks_preserving_lines() is
	 * paired with the interpolated-class check in
	 * test_no_div_hides_a_forbidden_class_behind_php(): this method makes the
	 * literal half visible, and that test refuses to let the dynamic half go
	 * unexamined.
	 *
	 * @param string $source Source with comments already stripped.
	 * @return string Same byte length and line count, PHP blocks blanked.
	 */
	private function blank_php_blocks_preserving_lines( string $source ): string {
		return (string) preg_replace_callback(
			self::PHP_BLOCK_PATTERN,
			static function ( array $m ): string {
				return preg_replace( '/[^\n]/', ' ', $m[0] );
			},
			$source
		);
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
