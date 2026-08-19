<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Minimal WP-CLI stand-ins for SettingsCliCommandTest.php -- kept out of
 * tests/bootstrap.php (the global bootstrap every other test file shares)
 * because nothing else in this suite needs `WP_CLI`/`WP_CLI_Command` to
 * exist.
 *
 * Deliberately NOT a faithful reimplementation of WP-CLI, and deliberately
 * NOT namespaced under the real `WP_CLI\...` WP-CLI itself uses:
 * inc/cli/settings-command.php never references a namespaced WP-CLI symbol
 * (it reads `$assoc_args` directly rather than calling
 * `WP_CLI\Utils\get_flag_value()`, and never catches a
 * `WP_CLI\ExitException` by name), so this stub environment only needs
 * something a test CAN catch by name -- a plain, unnamespaced
 * `Blueline_Test_Cli_Exit_Exception` serves that with none of the
 * restrictions this codebase's WordPress Coding Standards ruleset places on
 * bracketed namespace declarations.
 *
 * `WP_CLI::error()` and `WP_CLI::confirm()` (when not confirmed) throw that
 * exception rather than actually terminating the PHP process -- real
 * WP-CLI does the latter (via `WP_CLI::halt()`), which would kill the
 * PHPUnit run; throwing lets a test assert "this subcommand refused and
 * stopped" the same way it would assert any other rejection, via
 * `$this->expectException()`.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- same trade-off as tests/bootstrap.php's own identical disable: this is a single, small, self-contained stub environment, and splitting the exception class from the functions/classes that throw or use it would scatter one cohesive stand-in across multiple files for no reader's benefit.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as tests/bootstrap.php's own identical disable, applied to this file's three one-liner/small stub classes (Blueline_Test_Cli_Exit_Exception, WP_CLI_Command, WP_CLI).

if ( ! class_exists( 'Blueline_Test_Cli_Exit_Exception' ) ) {
	/**
	 * Thrown by this stub's WP_CLI::error()/WP_CLI::confirm() instead of
	 * actually terminating the process -- see this file's own docblock.
	 */
	class Blueline_Test_Cli_Exit_Exception extends RuntimeException {}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_json_encode() -- this stub
	 * environment has no notion of the mb_convert_encoding() UTF-8 fallback
	 * core's real version adds, so it's a direct pass-through to
	 * json_encode(); good enough for a unit test asserting on the decoded
	 * shape, which is all this suite ever does.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options json_encode() flags.
	 * @param int   $depth   Maximum nesting depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- this IS the stand-in for wp_json_encode() in a non-WP test environment.
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_delete_file() -- no
	 * `wp_delete_file` filter to honour in this stub environment, so it's a
	 * direct pass-through to unlink().
	 *
	 * @param string $file Path to delete.
	 * @return void
	 */
	function wp_delete_file( $file ) {
		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- this IS the stand-in for wp_delete_file() in a non-WP test environment.
		}
	}
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal stand-in for WP-CLI's WP_CLI_Command base class -- real
	 * WP-CLI subcommands extend this; this stub adds no behaviour of its
	 * own, since inc/cli/settings-command.php's Blueline_Settings_Command
	 * never calls a parent method.
	 */
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stand-in for WP-CLI's WP_CLI class: every call this theme's
	 * CLI command actually makes (add_command(), success(), warning(),
	 * log(), error(), confirm()) is recorded in
	 * $GLOBALS['bl_test_cli_log'] so a test can assert on it, and
	 * error()/an unconfirmed confirm() throw
	 * Blueline_Test_Cli_Exit_Exception rather than exiting the process.
	 */
	class WP_CLI {

		/**
		 * Record a registered command name/callable pair -- lets a test
		 * assert `wp blueline settings` was actually registered, without
		 * needing a real WP-CLI dispatcher.
		 *
		 * @param string               $name          Command name, e.g. "blueline settings".
		 * @param mixed                $command_class Class name or callable implementing it.
		 * @param array<string, mixed> $args          Unused; signature parity only.
		 * @return void
		 */
		public static function add_command( $name, $command_class, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP-CLI's real WP_CLI::add_command(); this stub has no dispatcher that needs $args.
			$GLOBALS['bl_test_cli_commands'][ $name ] = $command_class;
		}

		/**
		 * Record a plain log message.
		 *
		 * @param string $message Message to log.
		 * @return void
		 */
		public static function log( $message ) {
			$GLOBALS['bl_test_cli_log'][] = array(
				'type'    => 'log',
				'message' => (string) $message,
			);
		}

		/**
		 * Record a success message.
		 *
		 * @param string $message Success message.
		 * @return void
		 */
		public static function success( $message ) {
			$GLOBALS['bl_test_cli_log'][] = array(
				'type'    => 'success',
				'message' => (string) $message,
			);
		}

		/**
		 * Record a warning message.
		 *
		 * @param string $message Warning message.
		 * @return void
		 */
		public static function warning( $message ) {
			$GLOBALS['bl_test_cli_log'][] = array(
				'type'    => 'warning',
				'message' => (string) $message,
			);
		}

		/**
		 * Records the error, then throws -- real WP-CLI halts the process
		 * here; this stub throws so a test can assert the command actually
		 * stopped instead of proceeding to write anything.
		 *
		 * @param string $message Error message.
		 * @return void
		 * @throws Blueline_Test_Cli_Exit_Exception Always.
		 */
		public static function error( $message ) {
			$GLOBALS['bl_test_cli_log'][] = array(
				'type'    => 'error',
				'message' => (string) $message,
			);
			throw new Blueline_Test_Cli_Exit_Exception( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- an exception message thrown in a test-only stub, never echoed anywhere; nothing here reaches a browser.
		}

		/**
		 * Real WP-CLI prompts interactively unless `--yes` is present; this
		 * stub has no terminal to prompt, so it treats "no --yes" as "not
		 * confirmed" and throws, matching what happens when a real
		 * non-interactive WP-CLI invocation hits an unconfirmed confirm().
		 *
		 * @param string               $question   Unused; kept for signature parity.
		 * @param array<string, mixed> $assoc_args Associative arguments, checked for `yes`.
		 * @return void
		 * @throws Blueline_Test_Cli_Exit_Exception When `--yes` was not passed.
		 */
		public static function confirm( $question, $assoc_args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP-CLI's real WP_CLI::confirm(); this stub has no terminal to show the question in.
			if ( empty( $assoc_args['yes'] ) ) {
				throw new Blueline_Test_Cli_Exit_Exception( 'Not confirmed.' );
			}
		}
	}
}
