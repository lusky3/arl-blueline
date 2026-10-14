<?php
/**
 * WP_REST_Request stand-in for the privacy module's tests.
 *
 * @package blueline-core
 */

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal WP_REST_Request: a method, a route and request params.
	 */
	class WP_REST_Request {

		/**
		 * HTTP method.
		 *
		 * @var string
		 */
		private $method;

		/**
		 * Route path, e.g. /wp/v2/users/12.
		 *
		 * @var string
		 */
		private $route;

		/**
		 * Request params.
		 *
		 * @var array
		 */
		private $params = array();

		/**
		 * Build a request.
		 *
		 * @param string $method HTTP method.
		 * @param string $route  Route path.
		 */
		public function __construct( $method = '', $route = '' ) {
			$this->method = (string) $method;
			$this->route  = (string) $route;
		}

		/**
		 * The HTTP method.
		 *
		 * @return string
		 */
		public function get_method() {
			return $this->method;
		}

		/**
		 * The route path.
		 *
		 * @return string
		 */
		public function get_route() {
			return $this->route;
		}

		/**
		 * One param, or null.
		 *
		 * @param string $key Param name.
		 * @return mixed
		 */
		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Set one param.
		 *
		 * @param string $key   Param name.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}
	}
}
