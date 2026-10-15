<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line (the sniff anchors to the open tag); this file holds two test doubles of core classes next to the access module's function stubs.
/**
 * Stubs for the access module tests: a WP_User that carries roles and caps, a WP_Error that holds
 * several codes, and the request-context functions the wp-admin gate reads.
 *
 * @package blueline-core
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- the test doubles belong with the function stubs that return them.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- two small test doubles of core classes.

// The WP_User stand-in lives in avatars.php, which sorts after this file; load it first.
require_once __DIR__ . '/avatars.php';

if ( ! class_exists( 'Blueline_Core_Test_User' ) ) {
	/**
	 * A WP_User with the two public fields core fills from the role store: roles and allcaps.
	 */
	class Blueline_Core_Test_User extends WP_User {

		/**
		 * Role slugs.
		 *
		 * @var string[]
		 */
		public $roles = array();

		/**
		 * Capability => granted, as core merges it from the roles and the user's own caps.
		 *
		 * @var array<string, bool>
		 */
		public $allcaps = array();

		/**
		 * Build a user.
		 *
		 * @param int                 $id      User ID.
		 * @param string[]            $roles   Role slugs.
		 * @param array<string, bool> $allcaps Capability => granted.
		 */
		public function __construct( $id = 0, array $roles = array(), array $allcaps = array() ) {
			parent::__construct( $id );
			$this->roles   = $roles;
			$this->allcaps = $allcaps;
		}
	}
}

if ( ! class_exists( 'Blueline_Core_Test_Multi_Error' ) ) {
	/**
	 * A WP_Error that, like core's, can hold several codes, each with messages and data. The shared
	 * stand-in only models one code/message pair.
	 */
	class Blueline_Core_Test_Multi_Error extends WP_Error {

		/**
		 * Code => messages.
		 *
		 * @var array<string, string[]>
		 */
		public array $errors = array();

		/**
		 * Code => data.
		 *
		 * @var array<string, mixed>
		 */
		public array $error_data = array();

		/**
		 * Build an error, optionally with a first code.
		 *
		 * @param string $code    Error code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			parent::__construct( $code, $message, $data );
			if ( '' !== (string) $code ) {
				$this->add( $code, $message, $data );
			}
		}

		/**
		 * Add a message (and optional data) under a code.
		 *
		 * @param string $code    Error code.
		 * @param string $message Message.
		 * @param mixed  $data    Data.
		 * @return void
		 */
		public function add( $code, $message, $data = '' ) {
			$this->errors[ (string) $code ][] = (string) $message;
			if ( '' !== $data ) {
				$this->error_data[ (string) $code ] = $data;
			}
		}

		/**
		 * Remove a code with its messages and data.
		 *
		 * @param string $code Error code.
		 * @return void
		 */
		public function remove( $code ) {
			unset( $this->errors[ (string) $code ], $this->error_data[ (string) $code ] );
		}

		/**
		 * Every code, in insertion order.
		 *
		 * @return string[]
		 */
		public function get_error_codes() {
			return array_map( 'strval', array_keys( $this->errors ) );
		}

		/**
		 * The first code, or ''.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->get_error_codes()[0] ?? '';
		}

		/**
		 * The messages for a code (or every message when no code is given).
		 *
		 * @param string $code Error code.
		 * @return string[]
		 */
		public function get_error_messages( $code = '' ) {
			if ( '' === (string) $code ) {
				return array_merge( array(), ...array_values( $this->errors ) );
			}

			return $this->errors[ (string) $code ] ?? array();
		}

		/**
		 * The first message of a code (the first code by default).
		 *
		 * @param string $code Error code.
		 * @return string
		 */
		public function get_error_message( $code = '' ) {
			$code = '' === (string) $code ? $this->get_error_code() : (string) $code;

			return $this->errors[ $code ][0] ?? '';
		}

		/**
		 * The data stored for a code (the first code by default).
		 *
		 * @param string $code Error code.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			$code = '' === (string) $code ? $this->get_error_code() : (string) $code;

			return $this->error_data[ $code ] ?? null;
		}

		/**
		 * Whether any code is present.
		 *
		 * @return bool
		 */
		public function has_errors() {
			return (bool) $this->errors;
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Stand-in for wp_get_current_user(): $GLOBALS['bl_core_test_current_user'], or a user with ID 0.
	 *
	 * @return WP_User
	 */
	function wp_get_current_user() {
		return $GLOBALS['bl_core_test_current_user'] ?? new WP_User( 0 );
	}
}

if ( ! function_exists( 'wp_doing_cron' ) ) {
	/**
	 * Stand-in for wp_doing_cron(): $GLOBALS['bl_core_test_doing_cron'].
	 *
	 * @return bool
	 */
	function wp_doing_cron() {
		return ! empty( $GLOBALS['bl_core_test_doing_cron'] );
	}
}

if ( ! function_exists( 'wp_is_serving_rest_request' ) ) {
	/**
	 * Stand-in for wp_is_serving_rest_request(): $GLOBALS['bl_core_test_rest_request'].
	 *
	 * @return bool
	 */
	function wp_is_serving_rest_request() {
		return ! empty( $GLOBALS['bl_core_test_rest_request'] );
	}
}

if ( ! function_exists( 'wp_hash_password' ) ) {
	/**
	 * Stand-in for wp_hash_password(): counts calls in $GLOBALS['bl_core_test_password_hashes'].
	 *
	 * @param string $password Password.
	 * @return string
	 */
	function wp_hash_password( $password ) {
		$GLOBALS['bl_core_test_password_hashes'] = ( $GLOBALS['bl_core_test_password_hashes'] ?? 0 ) + 1;

		return 'hashed:' . strlen( (string) $password );
	}
}

if ( ! function_exists( 'wp_sanitize_redirect' ) ) {
	/**
	 * Stand-in for wp_sanitize_redirect(): returns the location unchanged.
	 *
	 * @param string $location Location.
	 * @return string
	 */
	function wp_sanitize_redirect( $location ) {
		return (string) $location;
	}
}
