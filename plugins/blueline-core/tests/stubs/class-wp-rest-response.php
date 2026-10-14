<?php
/**
 * WP_REST_Response stand-in for the privacy module's tests.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal WP_REST_Response: response data plus links keyed by relation.
	 */
	class WP_REST_Response {

		/**
		 * Response data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Links: relation => list of link arrays.
		 *
		 * @var array
		 */
		private $links = array();

		/**
		 * Build a response.
		 *
		 * @param mixed $data Response data.
		 */
		public function __construct( $data = null ) {
			$this->data = $data;
		}

		/**
		 * The response data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}

		/**
		 * Replace the response data.
		 *
		 * @param mixed $data Response data.
		 * @return void
		 */
		public function set_data( $data ) {
			$this->data = $data;
		}

		/**
		 * Add a link.
		 *
		 * @param string $rel  Link relation.
		 * @param string $href Target URL.
		 * @return void
		 */
		public function add_link( $rel, $href ) {
			$this->links[ $rel ][] = array( 'href' => $href );
		}

		/**
		 * Remove every link with a relation.
		 *
		 * @param string $rel Link relation.
		 * @return void
		 */
		public function remove_link( $rel ) {
			unset( $this->links[ $rel ] );
		}

		/**
		 * All links.
		 *
		 * @return array
		 */
		public function get_links() {
			return $this->links;
		}
	}
}
