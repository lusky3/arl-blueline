<?php
/**
 * Fake $wpdb for the archival tests: answers the module's one aggregate query (first and last
 * event date and event count per season term) from the in-memory posts and their season terms.
 *
 * @package blueline-core
 */

// This file sorts before the base class's, and the glob loader does not order by dependency.
require_once __DIR__ . '/class-blueline-core-test-wpdb.php';

if ( ! class_exists( 'Blueline_Core_Test_Archival_Wpdb' ) ) {
	/**
	 * First and last event date per season term, computed from the fake world.
	 */
	final class Blueline_Core_Test_Archival_Wpdb extends Blueline_Core_Test_Wpdb {

		/**
		 * Table name.
		 *
		 * @var string
		 */
		public $term_relationships = 'wp_term_relationships';

		/**
		 * Table name.
		 *
		 * @var string
		 */
		public $term_taxonomy = 'wp_term_taxonomy';

		/**
		 * Number of times the aggregate query ran.
		 *
		 * @var int
		 */
		public $queries = 0;

		/**
		 * Rows the real query would return for the fake world.
		 *
		 * @param string $query Unused.
		 * @return object[]
		 */
		public function get_results( $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with wpdb.
			++$this->queries;

			$state  = &blueline_test_state();
			$ranges = array();

			foreach ( $state['posts'] as $post_id => $post ) {
				if ( 'sp_event' !== ( $post['type'] ?? '' ) || ! in_array( $post['status'] ?? '', array( 'publish', 'future' ), true ) ) {
					continue;
				}

				foreach ( $state['post_terms'][ $post_id ]['sp_season'] ?? array() as $term_id ) {
					$date = (string) $post['date'];

					$ranges[ $term_id ] = array(
						'first_date' => isset( $ranges[ $term_id ] ) ? min( $ranges[ $term_id ]['first_date'], $date ) : $date,
						'last_date'  => isset( $ranges[ $term_id ] ) ? max( $ranges[ $term_id ]['last_date'], $date ) : $date,
						'events'     => ( $ranges[ $term_id ]['events'] ?? 0 ) + 1,
					);
				}
			}

			// wpdb returns every column as a string.
			$rows = array();
			foreach ( $ranges as $term_id => $range ) {
				$rows[] = (object) array(
					'term_id'    => (string) $term_id,
					'first_date' => $range['first_date'],
					'last_date'  => $range['last_date'],
					'events'     => (string) $range['events'],
				);
			}

			return $rows;
		}
	}
}
