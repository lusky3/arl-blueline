<?php
/**
 * Fake $wpdb the player-link and player-photo tests install per test.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'Blueline_Core_Test_Wpdb' ) ) {
	/**
	 * Fake $wpdb: canned get_results() rows, a callable answering get_var(),
	 * and a prepare() that records its arguments.
	 */
	class Blueline_Core_Test_Wpdb {

		/**
		 * Table name.
		 *
		 * @var string
		 */
		public $posts = 'wp_posts';

		/**
		 * Table name.
		 *
		 * @var string
		 */
		public $postmeta = 'wp_postmeta';

		/**
		 * Error of the last query ('' for none).
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Rows get_results() returns.
		 *
		 * @var object[]
		 */
		public $results = array();

		/**
		 * Answers get_var(); receives the prepare() arguments of the last call.
		 *
		 * @var callable|null
		 */
		public $var_callback = null;

		/**
		 * Arguments of the last prepare() call.
		 *
		 * @var array
		 */
		public $prepared_args = array();

		/**
		 * Record the arguments; return the raw query.
		 *
		 * @param string $query   SQL with placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared_args = $args;
			return (string) $query;
		}

		/**
		 * Canned rows.
		 *
		 * @param string $query Unused.
		 * @return object[]
		 */
		public function get_results( $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with wpdb.
			return $this->results;
		}

		/**
		 * The callback's answer for the last prepare() arguments.
		 *
		 * @param string $query Unused.
		 * @return mixed
		 */
		public function get_var( $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with wpdb.
			return $this->var_callback ? call_user_func( $this->var_callback, $this->prepared_args ) : null;
		}
	}
}
