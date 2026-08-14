<?php
/**
 * Mechanical, regex-based extractor for the "hook contract" of WordPress
 * core's update_option(), add_option(), and sanitize_option() -- the three
 * functions that make up the option write pipeline this theme's control
 * panel depends on.
 *
 * This file exists so that regenerating
 * tests/fixtures/wp-core-option-contract.json's `source_truth` section from
 * a real WordPress core checkout is a mechanical act (run a tool, diff the
 * output), not a manual re-read of option.php by whoever is doing the
 * upgrade. It is deliberately NOT a general PHP parser: it knows the exact
 * five/three/one hook dispatches each function is expected to contain (as
 * of WordPress 6.8.7/6.9.4, verified byte-identical for these functions
 * across both), and it fails loudly -- throwing, never returning a partial
 * or empty result -- the moment source no longer matches that expectation.
 * That is the intended failure mode: a WordPress upgrade that adds,
 * removes, or reorders a hook in these functions should break this
 * extractor's assumptions, not sail through as a silently-stale contract.
 *
 * Used by:
 * - tests/tools/generate-wp-core-option-contract.php (the CLI wrapper a
 *   human runs to regenerate the fixture's `source_truth` section).
 * - tests/WpCoreContractTest.php's oracle-dependent job, which re-extracts
 *   from a live core checkout (when one is configured) and asserts the
 *   committed fixture has not gone stale.
 *
 * Scope: only the hook dispatches lexically inside the bodies of
 * update_option()/add_option() (wp-includes/option.php) and
 * sanitize_option() (wp-includes/formatting.php). The `default_option_
 * {$option}` filter that both option.php functions also call is
 * deliberately excluded from the extracted hook list -- it is an
 * existence/default-value check used to decide which code path to take,
 * not a change-notification hook a callback would hook into the way it
 * would pre_update_option_{$option} or added_option -- but its presence
 * (exactly once, in its known position) is still asserted per function, so
 * that its removal or duplication -- a sign this scope boundary itself may
 * need revisiting -- is not silently invisible to the loud-failure count
 * check either.
 *
 * @package blueline
 */

/**
 * Extracts the option-lifecycle hook contract from a WordPress core
 * checkout's wp-includes directory.
 *
 * @param string $wp_includes_dir Path to a wp-includes directory (e.g.
 *                                "/path/to/wordpress/wp-includes").
 * @return array{
 *     sanitize_option: list<array{hook:string,kind:string,dynamic:bool,args:list<string>}>,
 *     update_option: list<array{hook:string,kind:string,dynamic:bool,args:list<string>}>,
 *     add_option: list<array{hook:string,kind:string,dynamic:bool,args:list<string>}>
 * }
 * @throws RuntimeException If a source file is missing, a function body
 *                           cannot be located, an expected hook dispatch is
 *                           missing or out of order, or the raw dispatch
 *                           count in a function body does not match what
 *                           this extractor was written to expect -- any of
 *                           which mean core has changed in a way this tool
 *                           was not taught to understand, and must not be
 *                           papered over with a partial result.
 */
function blueline_extract_wp_core_option_contract( string $wp_includes_dir ): array {
	if ( ! is_dir( $wp_includes_dir ) ) {
		throw new RuntimeException( "wp-includes directory not found: {$wp_includes_dir}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI/test-only tool; this message is never rendered as HTML, only caught and printed to a terminal or a PHPUnit failure message.
	}

	$option_php_path     = $wp_includes_dir . '/option.php';
	$formatting_php_path = $wp_includes_dir . '/formatting.php';

	foreach ( array( $option_php_path, $formatting_php_path ) as $required_file ) {
		if ( ! is_readable( $required_file ) ) {
			throw new RuntimeException( "required core source file not found or unreadable: {$required_file}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see the exception message above.
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local WordPress core checkout's PHP source files, never a remote URL; wp_remote_get() does not apply and there is no WordPress runtime loaded to call it from.
	$option_php     = (string) file_get_contents( $option_php_path );
	$formatting_php = (string) file_get_contents( $formatting_php_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- see the line above.

	$sanitize_option_body = blueline_wpcc_extract_function_body( $formatting_php, 'sanitize_option', $formatting_php_path );
	$update_option_body   = blueline_wpcc_extract_function_body( $option_php, 'update_option', $option_php_path );
	$add_option_body      = blueline_wpcc_extract_function_body( $option_php, 'add_option', $option_php_path );

	blueline_wpcc_assert_dispatch_count( $sanitize_option_body, 1, 'sanitize_option', $formatting_php_path );
	blueline_wpcc_assert_dispatch_count( $update_option_body, 6, 'update_option', $option_php_path );
	blueline_wpcc_assert_dispatch_count( $add_option_body, 4, 'add_option', $option_php_path );

	$cursor          = 0;
	$sanitize_option = array(
		blueline_wpcc_expect(
			$sanitize_option_body,
			$cursor,
			'/apply_filters\(\s*"sanitize_option_\{\$option\}"\s*,\s*([^)]+?)\s*\)/',
			'sanitize_option_{$option}',
			'filter',
			true
		),
	);

	$cursor        = 0;
	$update_option = array(
		blueline_wpcc_expect(
			$update_option_body,
			$cursor,
			'/apply_filters\(\s*"pre_update_option_\{\$option\}"\s*,\s*([^)]+?)\s*\)/',
			'pre_update_option_{$option}',
			'filter',
			true
		),
		blueline_wpcc_expect(
			$update_option_body,
			$cursor,
			'/apply_filters\(\s*\'pre_update_option\'\s*,\s*([^)]+?)\s*\)/',
			'pre_update_option',
			'filter',
			false
		),
		// The default_option_{$option} existence check sits here, between
		// pre_update_option and the generic update_option action -- see
		// this file's docblock for why it is deliberately not extracted as
		// a contract hook, only counted.
		blueline_wpcc_expect(
			$update_option_body,
			$cursor,
			'/do_action\(\s*\'update_option\'\s*,\s*([^)]+?)\s*\)/',
			'update_option',
			'action',
			false
		),
		blueline_wpcc_expect(
			$update_option_body,
			$cursor,
			'/do_action\(\s*"update_option_\{\$option\}"\s*,\s*([^)]+?)\s*\)/',
			'update_option_{$option}',
			'action',
			true
		),
		blueline_wpcc_expect(
			$update_option_body,
			$cursor,
			'/do_action\(\s*\'updated_option\'\s*,\s*([^)]+?)\s*\)/',
			'updated_option',
			'action',
			false
		),
	);

	$cursor     = 0;
	$add_option = array(
		blueline_wpcc_expect(
			$add_option_body,
			$cursor,
			'/do_action\(\s*\'add_option\'\s*,\s*([^)]+?)\s*\)/',
			'add_option',
			'action',
			false
		),
		blueline_wpcc_expect(
			$add_option_body,
			$cursor,
			'/do_action\(\s*"add_option_\{\$option\}"\s*,\s*([^)]+?)\s*\)/',
			'add_option_{$option}',
			'action',
			true
		),
		blueline_wpcc_expect(
			$add_option_body,
			$cursor,
			'/do_action\(\s*\'added_option\'\s*,\s*([^)]+?)\s*\)/',
			'added_option',
			'action',
			false
		),
	);

	return array(
		'sanitize_option' => $sanitize_option,
		'update_option'   => $update_option,
		'add_option'      => $add_option,
	);
}

/**
 * Locates a top-level function's body by finding its declaration and the
 * next top-level `function` declaration after it.
 *
 * @param string $source        Full file contents to search.
 * @param string $function_name Function name to locate (no parens).
 * @param string $file_label    File path/name, used only in error messages.
 * @return string The function body text, from its declaration line through
 *                (but not including) the next top-level function's
 *                declaration line.
 * @throws RuntimeException If the function or a following top-level
 *                           function cannot be found.
 */
function blueline_wpcc_extract_function_body( string $source, string $function_name, string $file_label ): string {
	$start_pattern = '/^function\s+' . preg_quote( $function_name, '/' ) . '\s*\(/m';

	if ( ! preg_match( $start_pattern, $source, $start_match, PREG_OFFSET_CAPTURE ) ) {
		throw new RuntimeException( "could not locate function {$function_name}() in {$file_label} -- has core renamed or removed it?" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI/test-only tool; never rendered as HTML.
	}

	$start_offset = $start_match[0][1];

	if ( ! preg_match( '/^function\s+\w+\s*\(/m', $source, $next_match, PREG_OFFSET_CAPTURE, $start_offset + 1 ) ) {
		throw new RuntimeException( "could not find a top-level function declaration after {$function_name}() in {$file_label} to bound its body -- is it the last function in the file?" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- see above.
	}

	return substr( $source, $start_offset, $next_match[0][1] - $start_offset );
}

/**
 * Asserts the total number of do_action()/apply_filters() dispatches
 * lexically present in a function body matches what this extractor was
 * written to expect (the hooks it extracts, plus any explicitly-excluded
 * ones such as default_option_{$option} -- see this file's docblock).
 *
 * @param string $body          Function body text.
 * @param int    $expected      Expected total dispatch count.
 * @param string $function_name Function name, used only in error messages.
 * @param string $file_label    File path/name, used only in error messages.
 * @return void
 * @throws RuntimeException If the actual count differs from $expected.
 */
function blueline_wpcc_assert_dispatch_count( string $body, int $expected, string $function_name, string $file_label ): void {
	$actual = preg_match_all( '/\b(?:do_action|apply_filters)\(/', $body );

	if ( $actual !== $expected ) {
		$message = "expected exactly {$expected} do_action()/apply_filters() dispatches in {$function_name}() " .
			"({$file_label}), found {$actual} -- WordPress core has likely added, removed, or restructured a " .
			'hook in this function; update this extractor\'s expected-hook list (and the committed fixture\'s ' .
			'known_gaps/sequences) deliberately before regenerating, rather than trusting a stale extraction.';

		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI/test-only tool; never rendered as HTML.
	}
}

/**
 * Finds one expected hook dispatch within a function body, starting the
 * search at (and advancing) a shared cursor -- so hooks are verified to
 * appear not merely present, but in the stated relative order.
 *
 * @param string $body      Function body text to search within.
 * @param int    $cursor    Byte offset to search from; advanced past the
 *                          matched dispatch on success (passed by
 *                          reference).
 * @param string $pattern   PCRE pattern with one capture group for the
 *                          raw, comma-separated argument list.
 * @param string $hook_name Hook name, used in the returned entry and in
 *                          error messages.
 * @param string $kind      Either 'filter' or 'action'.
 * @param bool   $dynamic   Whether the hook name contains the dynamic
 *                          `{$option}` portion.
 * @return array{hook:string,kind:string,dynamic:bool,args:list<string>}
 * @throws RuntimeException If the pattern is not found at or after the
 *                           cursor.
 */
function blueline_wpcc_expect( string $body, int &$cursor, string $pattern, string $hook_name, string $kind, bool $dynamic ): array {
	$remaining = substr( $body, $cursor );

	if ( ! preg_match( $pattern, $remaining, $match, PREG_OFFSET_CAPTURE ) ) {
		$message = "expected to find {$hook_name} (a {$kind}) at or after byte offset {$cursor} but did not -- " .
			'either core has changed this call\'s exact wording/quoting, or it no longer appears in the ' .
			'expected relative order.';

		throw new RuntimeException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI/test-only tool; never rendered as HTML.
	}

	$args_raw  = $match[1][0];
	$args      = array_map(
		static function ( string $arg ): string {
			return ltrim( trim( $arg ), '$' );
		},
		explode( ',', $args_raw )
	);
	$match_end = $match[0][1] + strlen( $match[0][0] );
	$cursor   += $match_end;

	return array(
		'hook'    => $hook_name,
		'kind'    => $kind,
		'dynamic' => $dynamic,
		'args'    => $args,
	);
}
