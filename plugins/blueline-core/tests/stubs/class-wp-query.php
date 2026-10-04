<?php
/**
 * WP_Query stand-in for the search module's tests.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query: just the two predicates and get() that
	 * blueline_search_title_match_first() reads.
	 */
	class WP_Query {

		/**
		 * Whether this is a search query.
		 *
		 * @var bool
		 */
		public $search = false;

		/**
		 * Whether this is the main query.
		 *
		 * @var bool
		 */
		public $main = false;

		/**
		 * Query vars.
		 *
		 * @var array
		 */
		public $vars = array();

		/**
		 * Build a query.
		 *
		 * @param bool  $search Whether it is a search query.
		 * @param bool  $main   Whether it is the main query.
		 * @param array $vars   Query vars.
		 */
		public function __construct( bool $search = false, bool $main = false, array $vars = array() ) {
			$this->search = $search;
			$this->main   = $main;
			$this->vars   = $vars;
		}

		/**
		 * Whether this is a search.
		 *
		 * @return bool
		 */
		public function is_search() {
			return $this->search;
		}

		/**
		 * Whether this is the main query.
		 *
		 * @return bool
		 */
		public function is_main_query() {
			return $this->main;
		}

		/**
		 * A query var.
		 *
		 * @param string $key Var name.
		 * @return mixed
		 */
		public function get( $key ) {
			return $this->vars[ $key ] ?? '';
		}
	}
}
