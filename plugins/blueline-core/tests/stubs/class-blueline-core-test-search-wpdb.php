<?php
/**
 * $wpdb stand-in for the search module's tests: the real esc_like() and a prepare() that really
 * substitutes placeholders.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'Blueline_Core_Test_Search_Wpdb' ) ) {
	/**
	 * $wpdb stand-in with wpdb's esc_like() and a prepare() that substitutes each %s with a
	 * quoted, slash-escaped value (as wpdb does through esc_sql), recording the raw arguments.
	 */
	class Blueline_Core_Test_Search_Wpdb {

		/**
		 * Table name.
		 *
		 * @var string
		 */
		public $posts = 'wp_posts';

		/**
		 * Raw arguments of the last prepare() call.
		 *
		 * @var array
		 */
		public $prepared_args = array();

		/**
		 * Escape LIKE wildcards, exactly as wpdb::esc_like() does.
		 *
		 * @param string $text Text to escape.
		 * @return string
		 */
		public function esc_like( $text ) {
			return addcslashes( $text, '_%\\' );
		}

		/**
		 * Substitute %s placeholders with quoted, slash-escaped values.
		 *
		 * @param string $query   SQL with %s placeholders.
		 * @param mixed  ...$args Values.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared_args = $args;

			$parts = explode( '%s', (string) $query );
			$sql   = (string) array_shift( $parts );

			foreach ( $parts as $i => $part ) {
				$sql .= "'" . addslashes( (string) ( $args[ $i ] ?? '' ) ) . "'" . $part;
			}

			return $sql;
		}
	}
}
