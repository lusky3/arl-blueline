<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Test bootstrap. Defines minimal WordPress stubs so pure logic can be unit tested
 * without a WordPress install.
 *
 * A single stub class (Walker_Nav_Menu) is deliberately kept in this file
 * alongside the many stub functions below, rather than split into its own
 * class-walker-nav-menu.php -- both file-organisation sniffs are disabled
 * (not ignored) for exactly that reason, per the same convention
 * inc/template-tags.php established for the same trade-off.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- same trade-off as the Mixed disable above: the Walker_Nav_Menu and WP_Error stand-ins are both one-liner stubs of WordPress globals, and splitting a test bootstrap into per-class files would scatter the stub environment across four files for no reader's benefit.

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! class_exists( 'Walker_Nav_Menu' ) ) {
	/**
	 * Minimal stand-in for WordPress core's Walker_Nav_Menu -- only the
	 * method signatures Blueline_Nav_Walker overrides are needed; the base
	 * class's own rendering logic is never exercised by this suite, since
	 * every test calls start_el()/end_el() directly rather than the full
	 * Walker::walk() traversal.
	 */
	class Walker_Nav_Menu {}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_html().
	 *
	 * @param string $t Text to escape.
	 * @return string
	 */
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_attr().
	 *
	 * @param string $t Text to escape.
	 * @return string
	 */
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_url().
	 *
	 * @param string $t URL to sanitize.
	 * @return string
	 */
	function esc_url( $t ) {
		return filter_var( (string) $t, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' home_url().
	 *
	 * @param string $path Path to append.
	 * @return string
	 */
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal stand-in for WordPress' __() -- text domain is irrelevant to
	 * unit tests, which never load translations.
	 *
	 * @param string $t Text to (not) translate.
	 * @param string $d Text domain (unused, kept for signature parity).
	 * @return string
	 */
	function __( $t, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test loads translations.
		return $t;
	}
}
if ( ! function_exists( '_n' ) ) {
	/**
	 * Minimal stand-in for WordPress' _n(): real plural-form selection
	 * (English singular/plural only), no translation loaded.
	 *
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Number to check.
	 * @param string $d      Text domain (unused, kept for signature parity).
	 * @return string
	 */
	function _n( $single, $plural, $number, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test loads translations.
		return 1 === (int) $number ? $single : $plural;
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_strip_all_tags().
	 *
	 * @param string $t String to strip tags from.
	 * @return string
	 */
	function wp_strip_all_tags( $t ) {
		return trim( wp_kses( (string) $t, array() ) );
	}
}
if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_kses(): strips every tag not present
	 * in the allowlist, so tests can assert that markup actually survives (or
	 * is stripped by) escaping rather than passing vacuously either way.
	 *
	 * Core wp_kses() special-cases <script> and <style>: it removes the whole
	 * element, tags AND enclosed content, regardless of the allowlist -- a
	 * plain strip_tags() would leave the enclosed text behind as inert-looking
	 * (but still attacker-controlled) output. That removal is replicated here
	 * before the allowlist-based strip_tags() runs.
	 *
	 * @param string $t            String to sanitize.
	 * @param array  $allowed_html Map of allowed tag name => attributes.
	 * @return string
	 */
	function wp_kses( $t, $allowed_html = array() ) {
		$t       = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', (string) $t );
		$allowed = array_keys( is_array( $allowed_html ) ? $allowed_html : array() );
		return $allowed
			? strip_tags( $t, '<' . implode( '><', $allowed ) . '>' )
			: strip_tags( $t ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- this stub IS wp_kses(), which wp_strip_all_tags() above delegates to; calling wp_strip_all_tags() here would recurse infinitely.
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_kses_post(): the same "post" tag
	 * allowlist core ships, expressed via this stub's wp_kses().
	 *
	 * @param string $t String to sanitize.
	 * @return string
	 */
	function wp_kses_post( $t ) {
		return wp_kses(
			$t,
			array_fill_keys(
				array( 'a', 'strong', 'em', 'b', 'i', 'br', 'p', 'span', 'ul', 'ol', 'li', 'code' ),
				array()
			)
		);
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Minimal stand-in for WordPress' sanitize_text_field().
	 *
	 * @param string $t Text to sanitize.
	 * @return string
	 */
	function sanitize_text_field( $t ) {
		return trim( wp_strip_all_tags( (string) $t ) );
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	/**
	 * Minimal stand-in for WordPress' sanitize_html_class().
	 *
	 * @param string $html_class HTML class candidate.
	 * @return string
	 */
	function sanitize_html_class( $html_class ) {
		return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $html_class );
	}
}
if ( ! function_exists( 'absint' ) ) {
	/**
	 * Minimal stand-in for WordPress' absint().
	 *
	 * @param mixed $n Value to cast.
	 * @return int
	 */
	function absint( $n ) {
		return abs( (int) $n );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_parse_url() -- PHP's own parse_url()
	 * is sufficient for every URL shape exercised by this suite.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component PHP_URL_* component, or -1 for the full array.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- this IS the stand-in for wp_parse_url() in a non-WP test environment.
	}
}
/**
 * The mutable fake-WordPress state the stubs below read.
 *
 * Returned BY REFERENCE so a test can register a post type, set a capability,
 * or seed post meta and have the stubs see it -- the alternative (stubs that
 * return a hardcoded answer forever) is what let taxonomy_exists() and
 * post_type_exists() return false unconditionally, so any test of a function
 * guarded by them silently exercised only its guard clause and asserted
 * nothing about the code underneath. Default state is deliberately EMPTY,
 * which reproduces the old always-false behaviour exactly, so tests written
 * against the previous stubs are unaffected.
 *
 * @return array{post_types:string[], taxonomies:string[], post_meta:array<int,array<string,mixed>>, user_meta:array<int,array<string,mixed>>, users:array<int,object>, caps:array<string,bool>, current_user_id:int}
 */
function &blueline_test_state(): array {
	static $state = array(
		'post_types'      => array(),
		'taxonomies'      => array(),
		'post_meta'       => array(),
		'user_meta'       => array(),
		'users'           => array(),
		'caps'            => array(),
		'current_user_id' => 0,
	);

	return $state;
}

/**
 * Return the fake-WordPress state to its empty default, and clear
 * player-link.php's request-scoped linked-player cache along with it. Call
 * this from setUp() in any test that touches the stateful stubs, so tests
 * cannot leak state into each other through either store.
 */
function blueline_test_reset_state(): void {
	$state = &blueline_test_state();
	$state = array(
		'post_types'      => array(),
		'taxonomies'      => array(),
		'post_meta'       => array(),
		'user_meta'       => array(),
		'users'           => array(),
		'caps'            => array(),
		'current_user_id' => 0,
	);

	if ( function_exists( 'blueline_linked_player_cache' ) ) {
		$cache = &blueline_linked_player_cache();
		$cache = array();
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	/**
	 * Minimal stand-in for WordPress' taxonomy_exists(): true only for a
	 * taxonomy a test explicitly registered in blueline_test_state().
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	function taxonomy_exists( $taxonomy ) {
		$state = &blueline_test_state();
		return in_array( (string) $taxonomy, $state['taxonomies'], true );
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Minimal stand-in for WordPress' post_type_exists(): true only for a post
	 * type a test explicitly registered in blueline_test_state().
	 *
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	function post_type_exists( $post_type ) {
		$state = &blueline_test_state();
		return in_array( (string) $post_type, $state['post_types'], true );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress' WP_Error -- only the code/message pair
	 * blueline_link_player_to_user() constructs and its callers read back.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Human-readable message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		/**
		 * The error code.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * The error message.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Minimal stand-in for WordPress' is_wp_error().
	 *
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_current_user_id().
	 *
	 * @return int
	 */
	function get_current_user_id() {
		$state = &blueline_test_state();
		return (int) $state['current_user_id'];
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Minimal stand-in for WordPress' is_user_logged_in(): matches core's
	 * own definition -- true whenever there is a non-zero current user id.
	 * Reuses blueline_test_state()'s existing 'current_user_id' field
	 * rather than a second flag, so a test only has one thing to set.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return get_current_user_id() > 0;
	}
}
if ( ! function_exists( 'wp_login_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_login_url().
	 *
	 * @param string $redirect Optional post-login redirect (unused, kept for signature parity).
	 * @return string
	 */
	function wp_login_url( $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; no test needs the redirect param honoured.
		return 'https://example.test/wp-login.php';
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Minimal stand-in for WordPress' current_user_can(): only capabilities a
	 * test explicitly granted in blueline_test_state() are held.
	 *
	 * @param string $capability Capability name.
	 * @return bool
	 */
	function current_user_can( $capability ) {
		$state = &blueline_test_state();
		return ! empty( $state['caps'][ (string) $capability ] );
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_post_meta() (single-value form only).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$state = &blueline_test_state();
		$value = $state['post_meta'][ (int) $post_id ][ (string) $key ] ?? '';
		return $single ? $value : array( $value );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Minimal stand-in for WordPress' update_post_meta().
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return true
	 */
	function update_post_meta( $post_id, $key, $value ) {
		$state = &blueline_test_state();
		$state['post_meta'][ (int) $post_id ][ (string) $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_user_meta() (single-value form only).
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		$state = &blueline_test_state();
		$value = $state['user_meta'][ (int) $user_id ][ (string) $key ] ?? '';
		return $single ? $value : array( $value );
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_userdata(): an object carrying
	 * display_name, or false for an unknown user.
	 *
	 * @param int $user_id User ID.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		$state = &blueline_test_state();
		return $state['users'][ (int) $user_id ] ?? false;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_posts(), supporting only the one
	 * shape this suite exercises: blueline_get_linked_player_id()'s
	 * post_type + meta_key/meta_value + fields=ids lookup, resolved against
	 * blueline_test_state()'s post_meta store.
	 *
	 * @param array $args Query args.
	 * @return int[]
	 */
	function get_posts( $args = array() ) {
		$state = &blueline_test_state();

		$key   = (string) ( $args['meta_key'] ?? '' );
		$value = (string) ( $args['meta_value'] ?? '' );

		if ( '' === $key ) {
			return array();
		}

		$found = array();
		foreach ( $state['post_meta'] as $post_id => $meta ) {
			if ( isset( $meta[ $key ] ) && (string) $meta[ $key ] === $value ) {
				$found[] = (int) $post_id;
			}
		}

		sort( $found );

		$limit = (int) ( $args['posts_per_page'] ?? -1 );

		return ( $limit > 0 ) ? array_slice( $found, 0, $limit ) : $found;
	}
}
if ( ! function_exists( 'get_the_date' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_the_date() -- returns a fixed,
	 * recognisable string so tests can assert it was actually used.
	 *
	 * @param string $format Date format (unused, kept for signature parity).
	 * @param mixed  $post   Post (unused, kept for signature parity).
	 * @return string
	 */
	function get_the_date( $format = '', $post = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- fixed stub value; no test needs real date formatting.
		return 'Aug 20';
	}
}
$GLOBALS['bl_test_hooks']   = array();
$GLOBALS['bl_test_options'] = array();

/**
 * Reset the in-memory option store. Call from setUp().
 */
function blueline_test_reset_options(): void {
	$GLOBALS['bl_test_options'] = array();
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_filter(): registers the callback
	 * against the in-memory hook store, bucketed by priority.
	 *
	 * @param string   $tag           Filter name.
	 * @param callable $callback      Callback to run.
	 * @param int      $priority      Priority; lower runs first.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 * @return true
	 */
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['bl_test_hooks'][ $tag ][ $priority ][] = array(
			'cb'   => $callback,
			'args' => $accepted_args,
		);
		ksort( $GLOBALS['bl_test_hooks'][ $tag ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Minimal stand-in for WordPress' apply_filters(): actually dispatches to
	 * every callback registered via add_filter(), in priority order, honouring
	 * each callback's own accepted_args.
	 *
	 * @param string $tag   Filter name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Extra arguments forwarded per accepted_args.
	 * @return mixed
	 */
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				$params = array_merge(
					array( $value ),
					array_slice( $args, 0, max( 0, $hook['args'] - 1 ) )
				);
				$value  = call_user_func_array( $hook['cb'], $params );
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_action(): actions and filters share
	 * the same hook store in this stub environment.
	 *
	 * @param string   $tag           Action name.
	 * @param callable $callback      Callback to run.
	 * @param int      $priority      Priority; lower runs first.
	 * @param int      $accepted_args Number of arguments the callback accepts.
	 * @return true
	 */
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Minimal stand-in for WordPress' do_action(): runs every callback
	 * registered via add_action()/add_filter() against this tag, in priority
	 * order, discarding return values as core does.
	 *
	 * @param string $tag     Action name.
	 * @param mixed  ...$args Arguments forwarded per accepted_args.
	 * @return void
	 */
	function do_action( $tag, ...$args ) {
		foreach ( $GLOBALS['bl_test_hooks'][ $tag ] ?? array() as $bucket ) {
			foreach ( $bucket as $hook ) {
				call_user_func_array( $hook['cb'], array_slice( $args, 0, $hook['args'] ) );
			}
		}
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_option() over an in-memory store.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Default to return when the option is unset.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		return array_key_exists( $option, $GLOBALS['bl_test_options'] )
			? $GLOBALS['bl_test_options'][ $option ]
			: $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' update_option(): mirrors core by
	 * running the sanitize_option_{$option} filter on every write -- this is
	 * the hook register_setting()'s sanitize_callback attaches to, and the
	 * behaviour P1's settings layer depends on to guarantee WP-CLI and JSON
	 * import cannot bypass validation.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    New value.
	 * @param mixed  $autoload Unused; kept for signature parity.
	 * @return true
	 */
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test needs autoload honoured.
		$value                                 = apply_filters( "sanitize_option_{$option}", $value, $option );
		$GLOBALS['bl_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' delete_option().
	 *
	 * @param string $option Option name.
	 * @return true
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['bl_test_options'][ $option ] );
		return true;
	}
}
