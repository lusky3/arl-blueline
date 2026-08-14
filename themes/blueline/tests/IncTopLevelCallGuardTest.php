<?php
/**
 * Guards against the class of bug Task 10's Part B fix round found and
 * corrected: inc/account/endpoints.php called
 * blueline_register_account_endpoint_title_filters() as a BARE, unconditional
 * function call at file scope -- executing the instant functions.php's
 * require_once chain reached that file, before WordPress had fired
 * `after_setup_theme` at all. That call's own __( ..., 'blueline' ) calls
 * tripped WP 6.7+'s "Translation loading ... was triggered too early"
 * _load_textdomain_just_in_time() guard on every single request, logging a
 * notice on every wp-cli invocation including a plain `wp user create`.
 *
 * The investigation that found it (a live doing_it_wrong_run backtrace
 * captured against staging, not a hunch -- see the Task 10 report) was a
 * one-off: nothing stopped a future contributor reintroducing the identical
 * pattern in some OTHER inc/ file, where it would resurface only as the same
 * staging log noise, not a test failure.
 *
 * Modelled directly on tests/IncRequireCoverageTest.php's own precedent --
 * "guard the class of bug, not the one instance": read inc/ off disk at
 * runtime (not a hardcoded "endpoints.php must not do X" assertion, which
 * would carry the identical blind spot for the next file) and scan every
 * .php file for a bare, top-level call into real work.
 *
 * ## What counts as "top-level" here
 *
 * Not brace depth -- an `if`/`foreach`/`try` block at the top of a file
 * still executes the instant the file is require'd, conditionally or not
 * (inc/settings/cache.php's own
 * `if ( ! defined( 'BLUELINE_SRCACHE_PURGE' ) ) { define( ... ); }` is
 * exactly this shape, and is legitimate). What actually makes code DORMANT
 * -- not executed at require time, only when later called/instantiated -- is
 * being inside a function, method, or closure BODY. So this scanner tracks
 * one thing: for every `{`, is it opening a function/method/closure body (or
 * nested inside one already)? If so, everything inside it is exempt,
 * regardless of how many more control-flow braces it contains. If not,
 * every brace it contains is still "top scope" and subject to this guard.
 *
 * ## What's allowed at top scope
 *
 * A short, explicit allow-list of calls that REGISTER or DECLARE rather
 * than DO WORK -- exactly the shape every legitimate file-scope call in this
 * theme already takes: `add_action()`/`add_filter()`/`remove_action()`/
 * `remove_filter()` (hook registration), `defined()`/`define()` (the
 * `defined( 'ABSPATH' ) || exit;` guard every inc/ file opens with, and the
 * occasional conditional `define()`), `class_exists()`/`function_exists()`/
 * `interface_exists()`/`trait_exists()`/`method_exists()` (guard conditions
 * gating a require/return, never a call that does work itself), and
 * `WP_CLI::add_command()` (inc/cli/settings-command.php's own registration,
 * the WP-CLI equivalent of add_action()). Anything else -- a bare call to a
 * theme-defined (or any other) function, at top scope -- fails this test by
 * name.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the bare-top-level-call guard -- see this file's own docblock
 * above for the full rationale.
 */
final class IncTopLevelCallGuardTest extends TestCase {

	/**
	 * Plain (unqualified) function names safe to call at top scope --
	 * see this file's own docblock for why each one is safe.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PLAIN_CALLS = array(
		'defined',
		'define',
		'class_exists',
		'function_exists',
		'interface_exists',
		'trait_exists',
		'method_exists',
		'add_action',
		'add_filter',
		'remove_action',
		'remove_filter',
	);

	/**
	 * Qualified ("Class::method") calls safe to call at top scope, checked
	 * as an exact, case-sensitive string -- narrower than blanket-allowing
	 * every static call, since this theme has exactly one such call today
	 * (inc/cli/settings-command.php's own command registration) and a new
	 * one should be a deliberate addition to this list, not a silent pass.
	 *
	 * @var string[]
	 */
	private const ALLOWED_QUALIFIED_CALLS = array(
		'WP_CLI::add_command',
	);

	/**
	 * Scan every .php file under inc/ for a bare, top-level call this
	 * allow-list does not recognise. Read off disk at runtime -- see this
	 * file's own docblock for why a hardcoded file list would carry the
	 * exact blind spot this test exists to close.
	 */
	public function test_no_inc_file_calls_into_real_work_at_top_level(): void {
		$root = dirname( __DIR__ ) . '/inc';
		$this->assertDirectoryExists( $root, 'expected theme to have an inc/ directory to scan' );

		$violations = array();

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = 'inc' . substr( $file->getPathname(), strlen( $root ) );
			$source   = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

			foreach ( $this->find_bare_top_level_calls( $source ) as $hit ) {
				$violations[] = sprintf( '%s:%d calls %s() at file scope', $relative, $hit['line'], $hit['call'] );
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"these inc/ files call into real work at file scope -- executed the instant functions.php's require_once chain reaches them, before WordPress has fired any lifecycle action at all. Either wrap the registration in add_action()/add_filter() (see inc/account/endpoints.php's own fix for this exact bug), or add the call to this test's allow-list if it is genuinely a safe, non-working registration:\n" . implode( "\n", $violations )
		);
	}

	// -----------------------------------------------------------------------
	// Direct tests of the analyzer itself, against small literal PHP
	// fixtures -- proving what it allows and what it flags, rather than
	// trusting the real inc/ tree alone to demonstrate both directions.
	// -----------------------------------------------------------------------

	/**
	 * The `defined( 'ABSPATH' ) || exit;` guard every inc/ file opens with.
	 */
	public function test_allows_the_defined_abspath_or_exit_guard(): void {
		$this->assertSame( array(), $this->find_bare_top_level_calls( "<?php\ndefined( 'ABSPATH' ) || exit;\n" ) );
	}

	/**
	 * Hook registrations at top scope are exactly the legitimate pattern
	 * every real inc/ file already uses.
	 */
	public function test_allows_add_action_and_add_filter_registrations(): void {
		$source = "<?php\ndefined( 'ABSPATH' ) || exit;\nadd_action( 'init', 'blueline_foo' );\nadd_filter( 'the_content', 'blueline_bar' );\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * The exact shape of inc/settings/cache.php's own BLUELINE_SRCACHE_PURGE
	 * guard -- a top-level `if` block (still "top scope", not a function
	 * body) whose only call is an allowed one.
	 */
	public function test_allows_a_conditional_define_inside_a_top_level_if_block(): void {
		$source = "<?php\nif ( ! defined( 'FOO' ) ) {\n\tdefine( 'FOO', false );\n}\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * The real registration inc/cli/settings-command.php uses -- a
	 * qualified static call, not a plain function call.
	 */
	public function test_allows_the_qualified_wp_cli_add_command_registration(): void {
		$source = "<?php\nWP_CLI::add_command( 'blueline settings', 'Blueline_Settings_Command' );\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * Both calls in the fixture below are only reachable by CALLING the
	 * function that contains them -- dormant until then, never executed at
	 * require time -- so neither should be flagged, even the one inside a
	 * nested `if`.
	 */
	public function test_allows_calls_inside_a_function_body(): void {
		$source = "<?php\nfunction blueline_foo() {\n\tblueline_bar();\n\tif ( true ) {\n\t\tblueline_baz();\n\t}\n}\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * A call inside a class method's body is exactly as dormant as one
	 * inside a plain function's body.
	 */
	public function test_allows_calls_inside_a_class_methods_body(): void {
		$source = "<?php\nclass Foo {\n\tpublic function bar() {\n\t\tblueline_do_work();\n\t}\n}\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * The exact shape of inc/account/player-link.php's own
	 * blueline_linked_player_cache(): `function &name() {}` -- the `&`
	 * between `function` and the name is what makes this worth its own
	 * test; it broke a naive "T_STRING immediately preceded by T_FUNCTION"
	 * check during this test's own development.
	 */
	public function test_does_not_mistake_a_by_reference_function_declaration_for_a_call(): void {
		$source = "<?php\nfunction &blueline_linked_player_cache(): array {\n\tstatic \$cache = array();\n\treturn \$cache;\n}\n";
		$this->assertSame( array(), $this->find_bare_top_level_calls( $source ) );
	}

	/**
	 * The load-bearing negative case: the exact bug this test exists to
	 * catch. Fails by name -- naming both the line and the call -- not just
	 * "something is wrong".
	 */
	public function test_flags_a_bare_top_level_call_to_a_theme_function(): void {
		$source = "<?php\ndefined( 'ABSPATH' ) || exit;\n\nblueline_register_account_endpoint_title_filters();\n\nfunction blueline_register_account_endpoint_title_filters() {}\n";

		$hits = $this->find_bare_top_level_calls( $source );

		$this->assertCount( 1, $hits );
		$this->assertSame( 4, $hits[0]['line'] );
		$this->assertSame( 'blueline_register_account_endpoint_title_filters', $hits[0]['call'] );
	}

	/**
	 * A bare call still executes at require time even wrapped in a
	 * top-level `if` -- conditional execution is still execution, not the
	 * dormancy a function/class body provides.
	 */
	public function test_flags_a_bare_call_inside_a_top_level_if_block(): void {
		$source = "<?php\nif ( class_exists( 'WooCommerce' ) ) {\n\tblueline_do_the_thing();\n}\n";

		$hits = $this->find_bare_top_level_calls( $source );

		$this->assertCount( 1, $hits );
		$this->assertSame( 'blueline_do_the_thing', $hits[0]['call'] );
	}

	/**
	 * An unlisted qualified (Class::method) call is flagged too -- the
	 * qualified allow-list is narrow on purpose (see its own docblock), so
	 * a new static call needs a deliberate addition, not a silent pass.
	 */
	public function test_flags_an_unlisted_qualified_call(): void {
		$source = "<?php\nSome_Other_Class::do_a_thing();\n";

		$hits = $this->find_bare_top_level_calls( $source );

		$this->assertCount( 1, $hits );
		$this->assertSame( 'Some_Other_Class::do_a_thing', $hits[0]['call'] );
	}

	// -----------------------------------------------------------------------
	// The analyzer itself.
	// -----------------------------------------------------------------------

	/**
	 * Find every top-scope call this test's allow-list does not recognise
	 * in $source. See this file's own docblock ("What counts as
	 * top-level here") for the exact rule this applies.
	 *
	 * Comments are discarded first (token_get_all(), not a regex-based
	 * stripper -- the same choice tests/IncRequireCoverageTest.php makes,
	 * for the same reason: a `//` or brace-shaped character inside a string
	 * literal must never be mistaken for real source).
	 *
	 * @param string $source Complete PHP source, including the opening `<?php`.
	 * @return array<int, array{line: int, call: string}> One entry per
	 *                                                     violation, in the
	 *                                                     order found.
	 */
	private function find_bare_top_level_calls( string $source ): array {
		$tokens = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
				continue;
			}
			$tokens[] = $token;
		}

		$n            = count( $tokens );
		$brace_stack  = array(); // Each entry: true = dormant body, false = top scope.
		$pending_body = false;   // Set on T_FUNCTION/T_CLASS/T_TRAIT/T_INTERFACE, consumed by the next `{`.
		$hits         = array();

		for ( $i = 0; $i < $n; $i++ ) {
			$token = $tokens[ $i ];
			$id    = is_array( $token ) ? $token[0] : null;
			$text  = is_array( $token ) ? $token[1] : $token;
			$line  = is_array( $token ) ? $token[2] : null;

			if ( in_array( $id, array( T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE ), true ) ) {
				$pending_body = true;
			}

			if ( '{' === $text ) {
				$is_body       = $pending_body || ( ! empty( $brace_stack ) && end( $brace_stack ) );
				$brace_stack[] = $is_body;
				$pending_body  = false;
				continue;
			}

			if ( '}' === $text ) {
				array_pop( $brace_stack );
				continue;
			}

			$in_body = ! empty( $brace_stack ) && end( $brace_stack );
			if ( $in_body || T_STRING !== $id ) {
				continue;
			}

			$prev    = $i > 0 ? $tokens[ $i - 1 ] : null;
			$prev_id = is_array( $prev ) ? $prev[0] : null;
			if ( T_FUNCTION === $prev_id ) {
				continue; // A `function name(...)` declaration, not a call.
			}
			// `function &name(...)` -- a by-reference return declaration:
			// the name is preceded by a bare `&`, itself preceded by
			// T_FUNCTION. See test_does_not_mistake_a_by_reference_function_declaration_for_a_call().
			if ( '&' === ( is_array( $prev ) ? $prev[1] : $prev ) ) {
				$before_amp    = $i > 1 ? $tokens[ $i - 2 ] : null;
				$before_amp_id = is_array( $before_amp ) ? $before_amp[0] : null;
				if ( T_FUNCTION === $before_amp_id ) {
					continue;
				}
			}

			$next      = $i + 1 < $n ? $tokens[ $i + 1 ] : null;
			$next_text = is_array( $next ) ? $next[1] : $next;
			if ( '(' !== $next_text ) {
				continue; // Not a call at all -- e.g. a bare constant reference.
			}

			$qualifier = '';
			if ( in_array( $prev_id, array( T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) ) {
				$before      = $i > 1 ? $tokens[ $i - 2 ] : null;
				$before_text = is_array( $before ) ? $before[1] : $before;
				$qualifier   = $before_text . ( is_array( $prev ) ? $prev[1] : $prev );
			}

			$name = $qualifier . $text;

			if ( '' !== $qualifier ) {
				if ( in_array( $name, self::ALLOWED_QUALIFIED_CALLS, true ) ) {
					continue;
				}
			} elseif ( in_array( $name, self::ALLOWED_PLAIN_CALLS, true ) ) {
					continue;
			}

			$hits[] = array(
				'line' => (int) $line,
				'call' => $name,
			);
		}

		return $hits;
	}
}
