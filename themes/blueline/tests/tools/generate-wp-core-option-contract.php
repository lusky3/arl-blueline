<?php
/**
 * CLI wrapper that regenerates the `source_truth` section of
 * tests/fixtures/wp-core-option-contract.json from a real WordPress core
 * checkout, using tests/tools/wp-core-option-contract-extractor.php.
 *
 * Usage (from themes/blueline):
 *
 *   php tests/tools/generate-wp-core-option-contract.php <wp-includes-dir> [<wp-version>]
 *
 * Example, against a local checkout:
 *
 *   php tests/tools/generate-wp-core-option-contract.php /home/cody/arl-local/wp-includes 6.8.7
 *
 * Example, against a copy pulled from staging (never write to staging --
 * only read, e.g. via `ssh -p SSH_PORT root@staging-host.example "docker exec -u 33
 * staging-wp sh -lc 'cat /var/www/html/wp-includes/option.php'"` saved to a
 * local temp directory alongside formatting.php fetched the same way):
 *
 *   php tests/tools/generate-wp-core-option-contract.php /tmp/staging-wp-includes 6.9.4
 *
 * This tool prints the freshly-extracted `source_truth` object as JSON to
 * stdout -- it deliberately does NOT overwrite the committed fixture file
 * itself. Regenerating the fixture is a two-step, human-reviewed act:
 *
 *   1. Run this tool, inspect (or diff) its output against the fixture's
 *      current `source_truth` section.
 *   2. If the facts changed, update tests/fixtures/wp-core-option-contract.json
 *      by hand: replace `source_truth`, update `source.wp_version` and
 *      `source.extracted_date`, and -- this is the part a tool cannot do
 *      safely -- re-examine `sequences` and `known_gaps`, since those encode
 *      which of these hooks the theme's tests/bootstrap.php stub currently
 *      fires and with what arguments. A changed `source_truth` may mean an
 *      existing `known_gaps` entry is now moot, or that a previously-solid
 *      `sequences` entry now needs a new gap recorded. That judgement call
 *      is exactly the kind of thing this project asks a human to make
 *      deliberately rather than have a script paper over (see this repo's
 *      root CLAUDE.md-equivalent guidance on reporting discovered
 *      divergences rather than quietly fixing them).
 *
 * tests/WpCoreContractTest.php's oracle-dependent job runs the same
 * extractor directly (not by shelling out to this CLI) and compares its
 * result to the committed fixture's `source_truth`, so a stale fixture is
 * caught automatically whenever an oracle is configured -- this CLI is for
 * the human regenerating the fixture after that test tells them it's stale.
 *
 * @package blueline
 */

/**
 * Entry point: validates arguments, runs the extractor, prints JSON.
 *
 * This is a plain command-line PHP script that never runs inside a
 * WordPress request -- there is no WP_Filesystem, no admin-ajax response to
 * escape, and no remote URL to fetch, so the filesystem/output/escaping
 * sniffs WPCS applies here are inapplicable by construction; each is
 * silenced at its exact line below with the reason, rather than for the
 * whole file.
 *
 * @param array $argv Raw CLI arguments (as PHP's global $argv, a list of strings).
 * @return int Process exit code.
 */
function blueline_wpcc_cli_main( array $argv ): int {
	require __DIR__ . '/wp-core-option-contract-extractor.php';

	if ( count( $argv ) < 2 ) {
		fwrite( STDERR, "usage: php {$argv[0]} <wp-includes-dir> [<wp-version>]\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to its own STDERR, not a WordPress filesystem operation.
		return 2;
	}

	$wp_includes_dir = rtrim( $argv[1], '/' );
	$wp_version      = $argv[2] ?? null;

	try {
		$source_truth = blueline_extract_wp_core_option_contract( $wp_includes_dir );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'extraction failed: ' . $e->getMessage() . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI script writing to its own STDERR, not a WordPress filesystem operation.
		return 1;
	}

	$output = array(
		'source_truth' => $source_truth,
	);

	if ( null !== $wp_version ) {
		$output['wp_version'] = $wp_version;
	}

	echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script with no WordPress runtime loaded (wp_json_encode() is unavailable) writing its own JSON to stdout, not an HTTP response.

	return 0;
}

// This file is a CLI entry point only -- nothing else in the test suite
// requires/includes it (tests/WpCoreContractTest.php calls the extractor in
// wp-core-option-contract-extractor.php directly instead), so running
// main() unconditionally here is safe.
exit( blueline_wpcc_cli_main( $argv ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- exit() is used here purely to set the process exit code (an int, per blueline_wpcc_cli_main()'s return type), never to print a message.
