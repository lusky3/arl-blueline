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

/*
 * Pin PHP's default timezone for the whole suite.
 *
 * This suite's correctness otherwise depends on the machine's own
 * `date.timezone` ini setting, which is not something a test run should be
 * able to change the answer to. The theme has real code that pairs
 * `strtotime()` (default zone) with `gmdate()` (UTC) in one expression --
 * blueline_season_state_data()'s recent-events bounds, inc/season-state.php
 * -- and those two only agree when the default zone IS UTC. With
 * `php -d date.timezone=America/Toronto` the suite went red on exactly that
 * pairing before this line existed; on a CI box with a local zone set, it
 * would have failed for a reason nobody would look for here.
 *
 * UTC specifically, because that is what the theme's code assumes and what
 * WordPress is understood to set for itself at boot -- the latter is NOT
 * verified here (there is no core checkout in this worktree, and the
 * committed oracle at tests/fixtures/wp-core-option-contract.json covers
 * the option lifecycle only). Treat "production runs UTC" as an assumption
 * this line reproduces rather than a fact this suite proves; what it does
 * prove is that the theme behaves consistently under the zone it was
 * written against.
 */
date_default_timezone_set( 'UTC' ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- test bootstrap pinning the ambient zone so the suite cannot depend on the host's php.ini; see above.

/*
 * WordPress core's own time-unit constants. blueline_season_state_data()
 * (inc/season-state.php) reaches its set_transient( ..., MINUTE_IN_SECONDS )
 * call unconditionally -- not only on the post_type_exists( 'sp_event' )
 * branch DAY_IN_SECONDS lives on -- and no test called that function
 * end-to-end before ChromeSectionsTest needed to render blueline_site_header()
 * (which calls it via blueline_header_cta()), so neither constant had ever
 * been exercised, and neither was defined.
 */
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

/*
 * Blueline theme version constant, for fallback cache-busting when asset
 * metadata is unavailable. defined in the theme's functions.php, but
 * needed here for blueline_stylesheet_version() to work in tests.
 */
define( 'BLUELINE_VERSION', '1.0.1' );
define( 'BLUELINE_DIR', dirname( __DIR__ ) );

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
	 * A faithful-enough stand-in for WordPress' esc_url() -- Task 8's fix
	 * round found the previous filter_var( FILTER_SANITIZE_URL ) version did
	 * NOT strip a literal `"`, unlike real core (wp-includes/formatting.php),
	 * which replaces every character outside an explicit allow-list. That
	 * gap mattered concretely: a test asserting an href="" built from this
	 * stub is safe against a `"`-carrying hostile value would have passed
	 * for the wrong reason (the stub happened to be more permissive than
	 * production, not because production is unsafe). This mirrors core's
	 * real allow-list regex, so a character real WordPress strips (`"`,
	 * `<`, `>`, space) is stripped here too, and a test proving safety
	 * against this stub is proving something true of the real function.
	 *
	 * @param string $t URL to sanitize.
	 * @return string
	 */
	function esc_url( $t ) {
		$url = str_replace( ' ', '%20', ltrim( (string) $t ) );
		return (string) preg_replace( '/[^a-z0-9\-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i', '', $url );
	}
}
if ( ! function_exists( 'checked' ) ) {
	/**
	 * Minimal stand-in for WordPress' checked() -- needed the moment a test
	 * calls blueline_settings_render_field() for a `bool`/`section` field
	 * (inc/settings/page.php), which no test did until Task 2's fix round 1
	 * added tests exercising a `section` field's `help` text: nothing before
	 * that ever rendered this branch, so the gap was invisible.
	 *
	 * WHAT THIS STUB DOES, stated as the stub's own behaviour rather than as
	 * a claim about core: it compares the two arguments as strings with
	 * `===` -- a strict comparison of stringified values, which is not the
	 * same thing as PHP's own loose `==` -- and returns the attribute with
	 * DOUBLE quotes.
	 *
	 * Neither of those is verified against WordPress core. There is no core
	 * checkout in this worktree to check `__checked_selected_helper()`
	 * against, and an earlier version of this docblock asserted both anyway
	 * (it claimed a loose `==` the code does not perform, and attributed the
	 * quote style to core). Treat the exact output as this stub's convention:
	 * a test asserting on it is asserting on the stub, so anything load-
	 * bearing about core's real output needs checking against core first.
	 *
	 * @param mixed $checked    One of the values to compare.
	 * @param mixed $current    The other value to compare (default true).
	 * @param bool  $should_echo Whether to echo the result (default true).
	 * @return string ` checked="checked"` or an empty string.
	 */
	function checked( $checked, $current = true, $should_echo = true ) {
		$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
		if ( $should_echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub mirroring core's own unescaped echo; the literal string is not user input.
		}
		return $result;
	}
}
if ( ! function_exists( 'selected' ) ) {
	/**
	 * Stand-in for WordPress' selected(), built the same way as checked()
	 * above -- same stringified `===` comparison, same double-quoted
	 * attribute -- and carrying the same caveat: that shape is this stub's
	 * own convention, not a verified reproduction of core's, since there is
	 * no core checkout here to check it against. See checked()'s docblock.
	 *
	 * Added for Task 6 (P1b-panel-completion): blueline_settings_render_field()
	 * had never been exercised for a `band_photos` field before, so nothing
	 * had ever called selected() under test. The theme calls it from two
	 * places -- that field's per-photograph alignment `<option>` list
	 * (inc/settings/page.php) and sportspress/player-selector.php's player
	 * `<option>` list -- and Task 7's fix round added a third, the `choices`
	 * select in blueline_settings_render_field() itself.
	 *
	 * @param mixed $selected    One of the values to compare.
	 * @param mixed $current     The other value to compare (default true).
	 * @param bool  $should_echo Whether to echo the result (default true).
	 * @return string ` selected="selected"` or an empty string.
	 */
	function selected( $selected, $current = true, $should_echo = true ) {
		$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
		if ( $should_echo ) {
			echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub mirroring core's own unescaped echo; the literal string is not user input.
		}
		return $result;
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
if ( ! function_exists( 'has_nav_menu' ) ) {
	/**
	 * Minimal stand-in for WordPress' has_nav_menu(): reports whether $location
	 * has a menu assigned, purely from the in-memory list a test populates via
	 * $GLOBALS['bl_test_nav_menu_locations'] -- no test called
	 * blueline_site_header() end-to-end before Task 4 (P1b-panel-completion)
	 * needed to prove the chrome_utility_nav toggle withholds the utility nav
	 * even when a real menu IS assigned to that location, so nothing stubbed
	 * this function until now.
	 *
	 * @param string $location Theme location slug.
	 * @return bool
	 */
	function has_nav_menu( $location ) {
		return in_array( $location, $GLOBALS['bl_test_nav_menu_locations'] ?? array(), true );
	}
}
if ( ! function_exists( 'wp_nav_menu' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_nav_menu(). Faithful to three of
	 * core's own return/echo rules (wp-includes/nav-menu-template.php) --
	 * fix round 1 on Task 4 found the first version of this stub always
	 * returned null and never echoed regardless of $args, which meant every
	 * ChromeSectionsTest assertion would have passed identically against an
	 * arbitrarily wrong stub; nothing bound it to core's actual contract:
	 *
	 * - No menu assigned to the requested theme_location, and no callable
	 *   `fallback_cb`: returns `false` and prints nothing -- core's own
	 *   `if ( ! $menu || is_wp_error( $menu ) ) { return false; }` branch,
	 *   which fires unconditionally, BEFORE core ever consults `echo`.
	 * - A callable `fallback_cb` given and no menu assigned: calls it and
	 *   returns its result, matching core's own fallback branch. Never
	 *   exercised by this theme's own call sites (blueline_site_header()
	 *   always passes `fallback_cb => false` on both its calls), kept here
	 *   only for stub fidelity.
	 * - A menu assigned: builds one recognisable, non-empty marker string
	 *   carrying the requested theme_location (real menu-walking output is
	 *   irrelevant to every test in this suite) and either echoes it and
	 *   returns null (`echo => true`, the default -- core's own contract),
	 *   or returns the string unprinted (`echo => false`).
	 *
	 * @param array $args wp_nav_menu() args.
	 * @return string|bool|null
	 */
	function wp_nav_menu( $args = array() ) {
		$args = array_merge(
			array(
				'echo'           => true,
				'theme_location' => '',
				'fallback_cb'    => 'wp_page_menu',
			),
			(array) $args
		);

		$has_menu = in_array( $args['theme_location'], $GLOBALS['bl_test_nav_menu_locations'] ?? array(), true );

		if ( ! $has_menu ) {
			if ( is_callable( $args['fallback_cb'] ) ) {
				return call_user_func( $args['fallback_cb'], $args );
			}
			return false;
		}

		$output = '<div class="bl-test-nav-menu" data-theme-location="' . esc_attr( $args['theme_location'] ) . '"></div>';

		if ( ! $args['echo'] ) {
			return $output;
		}

		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub building its own trusted, already-escaped markup.
		return null;
	}
}
if ( ! function_exists( 'has_custom_logo' ) ) {
	/**
	 * Minimal stand-in for WordPress' has_custom_logo(): always false, so
	 * blueline_site_header() takes its fallback logo branch (leaf mark plus
	 * site title) -- the only branch any test needs, and the one that avoids
	 * also having to stub the_custom_logo() and the Customizer machinery
	 * behind it.
	 *
	 * @return bool
	 */
	function has_custom_logo() {
		return false;
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
	 * before the allowlist-based strip_tags() runs, in two steps:
	 *
	 * 1. A greedy same-tag pattern, reapplied until the string stops
	 *    changing, so a nested same-tag block (e.g. a <script> containing
	 *    another <script>, which is invalid HTML but not something an
	 *    attacker is obliged to avoid) collapses to nothing rather than
	 *    leaking the inner tag's trailing text once the outer match is
	 *    removed. NOTE: because the pattern is greedy and \1 only pins the
	 *    tag NAME, two separate same-tag blocks with real content between
	 *    them (e.g. "<script>a</script>safe text<script>b</script>") will
	 *    also collapse into one match and take that safe text with them.
	 *    That is a known, deliberate over-strip: for a test STUB, erring
	 *    toward removing too much is the safe failure mode, and under
	 *    removing too little is the dangerous one.
	 * 2. A cleanup pass for an UNTERMINATED <script>/<style> tag -- one
	 *    with no matching closing tag for step 1 to find -- which strips
	 *    the opening tag and everything after it, rather than leaving a
	 *    dangling, possibly attacker-controlled tail behind.
	 *
	 * This function is a TEST STUB, not a security boundary: it exists so a
	 * unit test can assert "markup survived" or "markup was stripped"
	 * meaningfully. Production code must never rely on it; only WordPress
	 * core's real wp_kses() is a security boundary.
	 *
	 * @param string $t            String to sanitize.
	 * @param array  $allowed_html Map of allowed tag name => attributes.
	 * @return string
	 */
	function wp_kses( $t, $allowed_html = array() ) {
		$t = (string) $t;
		do {
			$before = $t;
			$t      = (string) preg_replace( '@<(script|style)[^>]*>.*</\1>@si', '', $t );
		} while ( $t !== $before );
		$t = (string) preg_replace( '@<(?:script|style)\b[^>]*>.*@si', '', $t );

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
if ( ! function_exists( 'is_email' ) ) {
	/**
	 * A faithful-enough stand-in for WordPress' is_email() -- close enough to
	 * core's real local-part/domain character-class and structure checks
	 * (wp-includes/formatting.php) that a test asserting a hostile string is
	 * rejected is asserting something true of the real function too, not
	 * just of a permissive stub. In particular: neither the local part nor
	 * any domain label may contain a space, quote, or angle bracket -- the
	 * exact characters an attack aimed at an unescaped `mailto:` href would
	 * need.
	 *
	 * @param string $email Candidate email address.
	 * @return string|false The email unchanged if it looks valid, false otherwise.
	 */
	function is_email( $email ) {
		$email = (string) $email;

		if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
			return false;
		}

		list( $local, $domain ) = explode( '@', $email, 2 );

		if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/', $local ) ) {
			return false;
		}

		if ( '' === $domain || trim( $domain, " \t\n\r\0\x0B." ) !== $domain || preg_match( '/\.{2,}/', $domain ) ) {
			return false;
		}

		$subs = explode( '.', $domain );

		if ( count( $subs ) < 2 ) {
			return false;
		}

		foreach ( $subs as $sub ) {
			if ( '' === $sub || trim( $sub, " \t\n\r\0\x0B-" ) !== $sub || ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
				return false;
			}
		}

		return $email;
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
if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Minimal stand-in for WordPress' sanitize_title() -- lowercases,
	 * collapses any run of non alphanumeric characters to a single
	 * hyphen, and trims leading/trailing hyphens. Not a faithful port of
	 * core's accent-stripping remove_accents() behaviour, but every label
	 * this suite ever feeds it is plain ASCII, so that gap is never
	 * exercised.
	 *
	 * @param string $title Raw text.
	 * @return string
	 */
	function sanitize_title( $title ) {
		$title = strtolower( trim( (string) $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
		return trim( (string) $title, '-' );
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
 * @return array{post_types:string[], taxonomies:string[], post_meta:array<int,array<string,mixed>>, user_meta:array<int,array<string,mixed>>, users:array<int,object>, caps:array<string,bool>, current_user_id:int, posts:array<int,array{status:string,permalink:string}>, terms:array<int,object>, post_terms:array<int,array<string,int[]>>, active_sidebars:array<string,int>}
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
		'posts'           => array(),
		'terms'           => array(),
		'post_terms'      => array(),
		// Sidebar id => widget count, read by is_active_sidebar() and
		// wp_get_sidebars_widgets() below -- added for Task 5
		// (P1b-panel-completion). Empty by default, reproducing the pre-Task-5
		// always-false is_active_sidebar() behaviour for every test that
		// never seeds it.
		'active_sidebars' => array(),
		// The site's configured timezone, as wp_timezone() below hands it
		// back -- added for Task 6 (P1b-panel-completion). Defaults to the
		// zone this league actually plays in rather than UTC, deliberately:
		// a date helper that quietly ignored the site zone would still pass
		// every assertion written against a UTC-configured stub.
		'timezone'        => 'America/Toronto',
		// The instant current_time() below reports, as a Unix timestamp.
		// null means the real clock -- added for Task 7's fix round, so a
		// test can pin blueline_season_state_moment()'s two branches to the
		// same moment and assert they agree.
		'now'             => null,
	);

	return $state;
}

/**
 * Register a fake post in the in-memory store, so get_post_status() and
 * get_permalink() can be exercised for a specific ID/status combination --
 * "page 42 is published", "page 43 is trashed", or (by simply never calling
 * this for an ID) "page 44 doesn't exist".
 *
 * The permalink defaults to a plausible, well-formed URL derived from the
 * ID, deliberately generated regardless of $status -- this mirrors core's
 * real get_permalink(), which does NOT check post_status and will happily
 * hand back a permalink for a trashed or draft post. A test that needs to
 * assert against a specific URL may pass one explicitly.
 *
 * @param int         $id        Post ID.
 * @param string      $status    Post status (e.g. 'publish', 'trash', 'draft').
 * @param string|null $permalink Optional explicit permalink; defaults to a
 *                               generated, plausible URL for this ID.
 * @return void
 */
function blueline_test_register_post( int $id, string $status, ?string $permalink = null ): void {
	$state = &blueline_test_state();

	$state['posts'][ $id ] = array(
		'status'    => $status,
		'permalink' => $permalink ?? ( 'https://example.test/?page_id=' . $id ),
	);
}

/**
 * Register a fake taxonomy term in the in-memory store, so get_term() and
 * get_terms() can be exercised for a specific ID/taxonomy/parent
 * combination -- "term 91 exists in product_cat with parent 0", or (by
 * simply never calling this for an ID) "term 91 doesn't exist".
 *
 * Also registers $taxonomy itself in the taxonomy registry (see
 * taxonomy_exists() below), since a term cannot meaningfully exist in a
 * taxonomy the fake environment doesn't otherwise know about -- mirroring
 * how blueline_test_register_post() doesn't require a separate post_type
 * registration step, but unlike that helper, get_term()/get_terms() below
 * both consult taxonomy_exists() first (faithful to core, which refuses an
 * unregistered taxonomy outright), so a test that specifically wants to
 * exercise "the taxonomy itself is missing" must NOT call this helper at
 * all for that taxonomy.
 *
 * @param int    $id        Term ID.
 * @param string $taxonomy  Taxonomy this term belongs to (e.g. 'product_cat').
 * @param string $name      Term name/label.
 * @param int    $parent_id Parent term ID (0 for a top-level term).
 * @return void
 */
function blueline_test_register_term( int $id, string $taxonomy, string $name = '', int $parent_id = 0 ): void {
	$state = &blueline_test_state();

	if ( ! in_array( $taxonomy, $state['taxonomies'], true ) ) {
		$state['taxonomies'][] = $taxonomy;
	}

	$state['terms'][ $id ] = (object) array(
		'term_id'  => $id,
		'taxonomy' => $taxonomy,
		'name'     => '' !== $name ? $name : ( 'Term ' . $id ),
		'slug'     => 'term-' . $id,
		'parent'   => $parent_id,
	);
}

/**
 * Associate a fake post with fake terms (registered via
 * blueline_test_register_term()) in a given taxonomy, so wp_get_post_terms()
 * below has something to return.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy the term IDs belong to.
 * @param int[]  $term_ids Term IDs to associate with this post.
 * @return void
 */
function blueline_test_set_post_terms( int $post_id, string $taxonomy, array $term_ids ): void {
	$state = &blueline_test_state();

	$state['post_terms'][ $post_id ][ $taxonomy ] = array_map( 'intval', $term_ids );
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
		'posts'           => array(),
		'terms'           => array(),
		'post_terms'      => array(),
		'active_sidebars' => array(),
		'timezone'        => 'America/Toronto',
		'now'             => null,
		'inline_styles'   => array(),
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

if ( ! trait_exists( 'Blueline_Assert_WP_Error' ) ) {
	/**
	 * Minimal stand-in for the assertWPError() assertion WP_UnitTestCase
	 * provides in a full WordPress test install. This suite's tests extend
	 * plain PHPUnit\Framework\TestCase rather than WP_UnitTestCase (there is
	 * no WordPress install here for it to depend on), so a test that wants
	 * this one assertion pulls it in with `use Blueline_Assert_WP_Error;`
	 * rather than every test in the suite inheriting a heavier base class it
	 * does not need.
	 *
	 * Mirrors core's own implementation exactly: a plain instanceof check
	 * against the WP_Error stub above, surfaced through PHPUnit's own
	 * assertInstanceOf() so failures report the normal PHPUnit diff/message
	 * rather than a bespoke one.
	 */
	trait Blueline_Assert_WP_Error {

		/**
		 * Assert that a value is a WP_Error instance.
		 *
		 * @param mixed  $actual  Value under test.
		 * @param string $message Optional failure message.
		 * @return void
		 */
		public function assertWPError( $actual, string $message = '' ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- must match WP_UnitTestCase::assertWPError()'s exact name; a snake_case rename would silently stop satisfying the assertWPError() calls the brief's own tests (and PHPUnit\Framework\TestCase's assertion-name convention generally) require.
			self::assertInstanceOf( WP_Error::class, $actual, $message );
		}
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
if ( ! class_exists( 'Blueline_Test_Meta_Rows' ) ) {
	/**
	 * Marks a post_meta entry as SEVERAL rows sharing one meta_key, which is
	 * how WordPress actually stores repeated meta and what SportsPress does for
	 * sp_current_team / sp_past_team / sp_team.
	 *
	 * Needed because a bare array in the post_meta store is a single row whose
	 * VALUE is an array (sp_colors is exactly that), so array-ness alone cannot
	 * distinguish "one array value" from "many rows". Without this marker the
	 * double could not express the shape that broke blueline_get_player_team():
	 * a leading '0' placeholder row followed by the real team id, which
	 * get_post_meta( ..., true ) resolves to '0' because WordPress returns the
	 * FIRST row by meta_id.
	 */
	class Blueline_Test_Meta_Rows {
		/**
		 * Row values in meta_id order.
		 *
		 * @var array<int,mixed>
		 */
		public array $rows;

		/**
		 * Build a multi-row meta entry.
		 *
		 * @param array<int,mixed> $rows Row values, lowest meta_id first.
		 */
		public function __construct( array $rows ) {
			$this->rows = array_values( $rows );
		}
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_post_meta().
	 *
	 * Mirrors core's single-value contract: $single === true returns the FIRST
	 * row, not a merge and not the last, so a placeholder row shadows the real
	 * value exactly as it does in production.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key = '', $single = false ) {
		$state = &blueline_test_state();
		$value = $state['post_meta'][ (int) $post_id ][ (string) $key ] ?? '';

		if ( $value instanceof Blueline_Test_Meta_Rows ) {
			if ( ! $single ) {
				return $value->rows;
			}
			// Core returns '' for a key with no rows, else the first row.
			return $value->rows ? $value->rows[0] : '';
		}

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
if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * Minimal stand-in for WordPress' update_user_meta().
	 *
	 * @param int    $user_id User ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return true
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$state = &blueline_test_state();
		$state['user_meta'][ (int) $user_id ][ (string) $key ] = $value;
		return true;
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
			if ( ! isset( $meta[ $key ] ) || (string) $meta[ $key ] !== $value ) {
				continue;
			}

			$post_id = (int) $post_id;

			/*
			 * post_type and post_status are honoured because callers rely on
			 * them for correctness, not as decoration: a resolver that asks for
			 * one published sp_calendar must not be handed a draft, or a post of
			 * another type that happens to share the meta key. A stub that
			 * ignored them would let such a test pass while the real query
			 * behaved differently -- the failure mode this suite has been bitten
			 * by repeatedly. A post the test never registered has no type or
			 * status to check, so it is matched on meta alone, preserving the
			 * older meta-only tests written before this store existed.
			 */
			$registered = $state['posts'][ $post_id ] ?? null;

			if ( is_array( $registered ) ) {
				$want_type = $args['post_type'] ?? '';

				if ( $want_type && isset( $registered['type'] ) && $registered['type'] !== $want_type ) {
					continue;
				}

				$want_status = $args['post_status'] ?? '';

				if ( $want_status && 'any' !== $want_status
					&& isset( $registered['status'] ) && $registered['status'] !== $want_status ) {
					continue;
				}
			}

			$found[] = $post_id;
		}

		sort( $found );

		// Only ID ordering is modelled, which is all any caller here asks for.
		if ( isset( $args['order'] ) && 'DESC' === strtoupper( (string) $args['order'] ) ) {
			$found = array_reverse( $found );
		}

		$limit = (int) ( $args['posts_per_page'] ?? -1 );

		return ( $limit > 0 ) ? array_slice( $found, 0, $limit ) : $found;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_query_arg(), supporting the two call
	 * shapes this theme uses: ( array $args, string $url ) and
	 * ( string $key, string $value, string $url ).
	 *
	 * Faithful on the points that matter to callers: values are urlencoded,
	 * an existing query string is preserved and appended to rather than
	 * replaced, and a key already present is overwritten rather than
	 * duplicated -- all three of which core does and a naive "?k=v" concat
	 * does not.
	 *
	 * @param mixed ...$args Either ( array, url ) or ( key, value, url ).
	 * @return string
	 */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$pairs = $args[0];
			$url   = (string) ( $args[1] ?? '' );
		} else {
			$pairs = array( (string) $args[0] => $args[1] );
			$url   = (string) ( $args[2] ?? '' );
		}

		$parts    = explode( '#', $url, 2 );
		$fragment = isset( $parts[1] ) ? '#' . $parts[1] : '';
		$base     = $parts[0];

		$existing = array();
		if ( false !== strpos( $base, '?' ) ) {
			list( $base, $query ) = explode( '?', $base, 2 );
			parse_str( $query, $existing );
		}

		foreach ( $pairs as $key => $value ) {
			$existing[ $key ] = $value;
		}

		/*
		 * NOT urlencoded, because core does not encode either: add_query_arg()
		 * builds through build_query(), which calls
		 * _http_build_query( $data, null, '&', '', false ) -- that final
		 * `false` is $urlencode (wp-includes/functions.php, read from the
		 * installed core rather than assumed). A caller that needs an encoded
		 * value must encode it itself.
		 *
		 * This stub encoded at first, and that one divergence was enough to
		 * make a passing test report a double-encoding bug that did not exist,
		 * and to make the "fix" for it emit a genuinely broken URL. Left
		 * documented rather than merely corrected, because the tempting
		 * "improvement" here is to add encoding back.
		 */
		$pairs_out = array();

		foreach ( $existing as $key => $value ) {
			$pairs_out[] = $key . '=' . $value;
		}

		$query = implode( '&', $pairs_out );

		return $base . ( '' !== $query ? '?' . $query : '' ) . $fragment;
	}
}
if ( ! function_exists( 'is_active_sidebar' ) ) {
	/**
	 * Minimal stand-in for WordPress' is_active_sidebar(): consults
	 * blueline_test_state()'s 'active_sidebars' map (sidebar id => widget
	 * count), true only for an id a test explicitly seeded with a non-zero
	 * count. Default state's 'active_sidebars' is empty, so this reproduces
	 * the old always-false behaviour exactly for every test that never
	 * touches it (e.g. blueline_homepage_module_new_here() still falls
	 * through to its own default content unless a test opts in).
	 *
	 * Widened from an unconditional `return false;` for Task 5
	 * (P1b-panel-completion): blueline_section_widget_warning() needs a
	 * sidebar that CAN report active, with a specific widget count behind
	 * it, to be testable at all.
	 *
	 * @param string|int $index Sidebar ID.
	 * @return bool
	 */
	function is_active_sidebar( $index ) {
		$state = blueline_test_state();

		return ! empty( $state['active_sidebars'][ $index ] );
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Stand-in for WordPress' wp_timezone(). The only part of its behaviour
	 * this theme depends on -- and therefore the only part this stub
	 * reproduces -- is that it hands back a DateTimeZone for the site's own
	 * configured timezone, which blueline_site_timestamp()
	 * (inc/announcement.php) resolves admin-entered dates against.
	 *
	 * The zone comes from blueline_test_state()'s 'timezone' entry so a test
	 * can change it and assert the resolved instant moves with it; its
	 * default is deliberately NOT UTC (see that entry's own comment).
	 *
	 * @return DateTimeZone
	 */
	function wp_timezone() {
		$state = blueline_test_state();

		return new DateTimeZone( $state['timezone'] );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Stand-in for WordPress' current_time(), covering only the 'mysql' type
	 * this theme actually asks for: the current time as a site-local
	 * `Y-m-d H:i:s` wall-clock string. That local-not-UTC shape is the whole
	 * reason blueline_season_state_data() (inc/season-state.php) compares it
	 * against `post_date`, and it is what blueline_season_state_moment()'s
	 * `$now` branch has to reproduce for the two branches to mean the same
	 * thing.
	 *
	 * The instant comes from blueline_test_state()'s 'now' entry when a test
	 * sets one, which is what lets a test pin both branches of that function
	 * to the same moment and assert they agree. null (the default) means the
	 * real clock.
	 *
	 * Any other $type, and the $gmt flag, are deliberately unimplemented
	 * rather than guessed at: no theme code asks for either, and there is no
	 * core checkout here to check a guess against.
	 *
	 * @param string $type Only 'mysql' is supported.
	 * @param int    $gmt  Unsupported; a truthy value throws rather than
	 *                     silently handing back local time.
	 * @return string Site-local `Y-m-d H:i:s`.
	 * @throws InvalidArgumentException If $type is not 'mysql', or $gmt is truthy.
	 */
	function current_time( $type, $gmt = 0 ) {
		if ( 'mysql' !== $type || $gmt ) {
			throw new InvalidArgumentException( "the current_time() test stub only implements current_time( 'mysql' )" );
		}

		$state   = blueline_test_state();
		$instant = $state['now'] ?? null;

		return ( new DateTimeImmutable( null === $instant ? 'now' : '@' . $instant ) )
			->setTimezone( wp_timezone() )
			->format( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_get_sidebars_widgets' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_get_sidebars_widgets(): built from
	 * the same 'active_sidebars' map is_active_sidebar() above reads, so the
	 * two stubs can never disagree about how many widgets a sidebar holds.
	 * Matches core's return shape -- an array keyed by sidebar id, each an
	 * array of widget id strings, plus a 'wp_inactive_widgets' key -- though
	 * the widget id strings themselves are placeholders (`widget-1`,
	 * `widget-2`, ...): no test in this suite needs a specific widget's
	 * identity, only the count blueline_active_widget_count() derives from
	 * count( $result[ $area ] ).
	 *
	 * The 'wp_inactive_widgets' bucket here always stays empty: no test
	 * seeds it, and this stub has no mechanism to populate it independently
	 * of 'active_sidebars' (a real sidebar id, never that one). Whether
	 * blueline_active_widget_count( 'wp_inactive_widgets' ) would wrongly
	 * report orphaned widgets as a real area's live count is therefore
	 * untested here -- deliberately: that function is never called with
	 * that key in production either (blueline_section_widget_warning()'s own
	 * `$areas` map only ever names real sidebar ids), so there is nothing
	 * this stub could assert about it beyond "unreachable in practice",
	 * which this comment states plainly rather than faking a test around.
	 *
	 * @return array<string,string[]>
	 */
	function wp_get_sidebars_widgets() {
		$state  = blueline_test_state();
		$result = array( 'wp_inactive_widgets' => array() );

		foreach ( $state['active_sidebars'] as $id => $count ) {
			$result[ $id ] = array();
			for ( $i = 1; $i <= $count; $i++ ) {
				$result[ $id ][] = "widget-{$i}";
			}
		}

		return $result;
	}
}
if ( ! function_exists( 'dynamic_sidebar' ) ) {
	/**
	 * Minimal stand-in for WordPress' dynamic_sidebar(): a no-op, regardless
	 * of what is_active_sidebar() above reports for $index.
	 *
	 * Previously documented as "unreachable while is_active_sidebar() is
	 * always false" -- that stopped being true the moment is_active_sidebar()
	 * above was widened for Task 5 (P1b-panel-completion) to consult
	 * blueline_test_state()'s 'active_sidebars' map, which a test can now
	 * seed to make it report true. This stub still renders nothing when that
	 * happens: no test in this suite asserts real widget markup, only the
	 * WIDGET COUNT (via wp_get_sidebars_widgets() above), so there is
	 * nothing for this stub to fake beyond the no-op it already was.
	 *
	 * @param string|int $index Sidebar ID (unused, kept for signature parity).
	 * @return bool
	 */
	function dynamic_sidebar( $index ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; a deliberate no-op regardless of is_active_sidebar()'s answer -- see docblock.
		return false;
	}
}
if ( ! function_exists( 'bloginfo' ) ) {
	/**
	 * Minimal stand-in for WordPress' bloginfo(): echoes a fixed,
	 * recognisable string regardless of $show, so a test can assert the
	 * real call site actually ran without a real site configured.
	 *
	 * @param string $show Which piece of info to echo (unused, kept for signature parity).
	 * @return void
	 */
	function bloginfo( $show = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; fixed stub value regardless of $show.
		echo 'Blueline Test Site'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub: a fixed, non-user-controlled string, not real render output.
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_bloginfo(): the return-value
	 * counterpart to bloginfo() above, same fixed string regardless of $show.
	 *
	 * @param string $show Which piece of info to return (unused, kept for signature parity).
	 * @return string
	 */
	function get_bloginfo( $show = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; fixed stub value regardless of $show.
		return 'Blueline Test Site';
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
if ( ! function_exists( 'get_the_time' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_the_time() -- returns a fixed,
	 * recognisable string, mirroring get_the_date() above.
	 *
	 * @param string $format Time format (unused, kept for signature parity).
	 * @param mixed  $post   Post (unused, kept for signature parity).
	 * @return string
	 */
	function get_the_time( $format = '', $post = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- fixed stub value; no test needs real time formatting.
		return '7:00 PM';
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_post_status(): the status string of
	 * a post a test registered via blueline_test_register_post(), or `false`
	 * for an ID no test ever registered -- exactly like core's own return for
	 * a post that does not exist. Never returns `null` or `''` for an unknown
	 * ID; a caller relying on a strict `false` check (e.g.
	 * blueline_resolve_link()'s `'publish' === get_post_status( $id )`) must
	 * see the same falsy-but-typed value core would produce.
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @return string|false
	 */
	function get_post_status( $post = 0 ) {
		$state = &blueline_test_state();
		$id    = (int) $post;

		return $state['posts'][ $id ]['status'] ?? false;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_post_type(): the post type a test
	 * registered via blueline_test_register_post(), or `false` for an ID no
	 * test ever registered -- core's own return for a post that does not
	 * exist. Tests that never set a type get `''`, which is falsy but still a
	 * string, so an `in_array( get_post_type( $id ), array( ... ), true )`
	 * caller behaves as it would against a real post of an unlisted type.
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @return string|false
	 */
	function get_post_type( $post = null ) {
		$state = &blueline_test_state();
		$id    = (int) $post;

		if ( ! isset( $state['posts'][ $id ] ) ) {
			return false;
		}

		return $state['posts'][ $id ]['type'] ?? '';
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_the_title(): the title a test
	 * registered, or `''` for an unknown ID -- core returns an empty string
	 * rather than false here, so callers that concatenate the result do not
	 * see the string "1" from a boolean.
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @return string
	 */
	function get_the_title( $post = 0 ) {
		$state = &blueline_test_state();
		$id    = (int) $post;

		return (string) ( $state['posts'][ $id ]['title'] ?? '' );
	}
}
if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_attachment_is_image(): true only for
	 * a post a test registered with `is_image`.
	 *
	 * Deliberately false for an ID no test registered, matching core's answer
	 * for a post that does not exist -- the sanitizer that calls this is
	 * guarding against arbitrary IDs arriving from a form, so "unknown" must
	 * never read as "fine".
	 *
	 * @param int|object $post Attachment ID (only the int form is exercised here).
	 * @return bool
	 */
	function wp_attachment_is_image( $post = null ) {
		$state = &blueline_test_state();

		return ! empty( $state['posts'][ (int) $post ]['is_image'] );
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_get_attachment_image_url(): a
	 * plausible URL for a registered image, `false` otherwise.
	 *
	 * Returning false for an unregistered ID is the case that matters: it is
	 * how a deleted attachment behaves, and callers are expected to drop such
	 * a photograph rather than emit a url() pointing at nothing.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Requested size name.
	 * @return string|false
	 */
	function wp_get_attachment_image_url( $attachment_id = 0, $size = 'thumbnail' ) {
		$state = &blueline_test_state();
		$id    = (int) $attachment_id;

		if ( empty( $state['posts'][ $id ]['is_image'] ) ) {
			return false;
		}

		return 'https://example.test/uploads/photo-' . $id . '-' . (string) $size . '.jpg';
	}
}
if ( ! function_exists( 'has_post_thumbnail' ) ) {
	/**
	 * Minimal stand-in for WordPress' has_post_thumbnail().
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @return bool
	 */
	function has_post_thumbnail( $post = null ) {
		$state = &blueline_test_state();

		return ! empty( $state['posts'][ (int) $post ]['thumbnail_id'] );
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_post_thumbnail_id(): core returns
	 * `0` (not false) for a post with no featured image.
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @return int
	 */
	function get_post_thumbnail_id( $post = null ) {
		$state = &blueline_test_state();

		return (int) ( $state['posts'][ (int) $post ]['thumbnail_id'] ?? 0 );
	}
}
if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_the_post_thumbnail_url(): a
	 * recognisable, deterministic URL string when a thumbnail_id is set on
	 * the post (same `posts[id]['thumbnail_id']` state has_post_thumbnail()/
	 * get_post_thumbnail_id() already read above), or `false` for none --
	 * matching core's own return contract for a post with no featured image.
	 *
	 * @param int|object $post Post ID (only the int form is exercised by this suite).
	 * @param string     $size Image size (unused, kept for signature parity).
	 * @return string|false
	 */
	function get_the_post_thumbnail_url( $post = null, $size = 'post-thumbnail' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- fixed stub value; no test needs real image-size resolution.
		$id = get_post_thumbnail_id( $post );

		return $id ? 'https://example.test/thumb-' . $id . '.jpg' : false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_permalink(): deliberately faithful
	 * to a real footgun core has -- this does NOT check post_status. A post
	 * registered as 'trash' (or 'draft', or any other status) still returns
	 * its plausible permalink here, exactly as core's real get_permalink()
	 * does. Callers that need "is this actually safe to link to" MUST check
	 * get_post_status() themselves; that is the whole point of
	 * blueline_resolve_link() existing. Only an ID no test ever registered
	 * (the "post is gone" case) returns `false`.
	 *
	 * @param int|object $post      Post ID (only the int form is exercised by this suite).
	 * @param bool       $leavename Unused; kept for signature parity with core.
	 * @return string|false
	 */
	function get_permalink( $post = 0, $leavename = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test needs %pagename% resolution.
		$state = &blueline_test_state();
		$id    = (int) $post;

		return $state['posts'][ $id ]['permalink'] ?? false;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_term(): a term a test explicitly
	 * registered via blueline_test_register_term(), or `null` for an ID no
	 * test ever registered -- exactly like core's own return for a term
	 * that does not exist. Faithful to a second real core distinction too:
	 * a registered term ID whose actual taxonomy does not match the
	 * `$taxonomy` argument returns a WP_Error (`'invalid_taxonomy'`), not
	 * `null` and not the term anyway -- callers relying on
	 * `$term && ! is_wp_error( $term )` (e.g.
	 * blueline_term_id_exists(), inc/settings/commerce.php) must see the
	 * same "does not exist for my purposes" outcome from either case,
	 * never a truthy object for either.
	 *
	 * @param int|object $term     Term ID (only the int form is exercised by this suite).
	 * @param string     $taxonomy Optional taxonomy to constrain the lookup to.
	 * @return object|WP_Error|null
	 */
	function get_term( $term, $taxonomy = '' ) {
		$state = &blueline_test_state();
		$id    = (int) $term;

		if ( ! isset( $state['terms'][ $id ] ) ) {
			return null;
		}

		$found = $state['terms'][ $id ];

		if ( '' !== $taxonomy && $found->taxonomy !== $taxonomy ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		return $found;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_terms(): filters
	 * blueline_test_state()'s registered terms (blueline_test_register_term())
	 * by `taxonomy` and, when present in `$args`, `parent` -- enough to
	 * exercise blueline_registration_season_product_ids() and
	 * blueline_get_user_registration_status() (both season-term lookups by
	 * parent) against a fake term tree, without a real WordPress/WooCommerce
	 * install. `orderby` (only 'term_id' is exercised by this suite),
	 * `order` ('ASC'/'DESC') and `number` are honoured; `hide_empty` is
	 * accepted but ignored -- nothing in this suite registers WooCommerce
	 * product counts for a term to filter on.
	 *
	 * Faithful to core on the one thing every real call site here already
	 * guards against directly (taxonomy_exists() before calling): an
	 * unregistered taxonomy returns a WP_Error, never an empty array a
	 * caller might mistake for "taxonomy fine, nothing found".
	 *
	 * @param array $args Query args.
	 * @return object[]|WP_Error
	 */
	function get_terms( $args = array() ) {
		$taxonomy = (string) ( $args['taxonomy'] ?? '' );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		$state   = &blueline_test_state();
		$matches = array();

		foreach ( $state['terms'] as $candidate ) {
			if ( $candidate->taxonomy !== $taxonomy ) {
				continue;
			}

			if ( array_key_exists( 'parent', $args ) && (int) $candidate->parent !== (int) $args['parent'] ) {
				continue;
			}

			$matches[] = $candidate;
		}

		usort(
			$matches,
			static function ( $a, $b ) {
				return $a->term_id <=> $b->term_id;
			}
		);

		if ( 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ) {
			$matches = array_reverse( $matches );
		}

		$number = (int) ( $args['number'] ?? 0 );
		if ( $number > 0 ) {
			$matches = array_slice( $matches, 0, $number );
		}

		return $matches;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_get_post_terms(): the term objects
	 * a test associated with a fake post via blueline_test_set_post_terms(),
	 * or an empty array for a post/taxonomy no test ever associated any
	 * terms with (faithful to core's own contract: an empty array, never
	 * `false` or `null`, is a normal "no terms" result -- only an actually
	 * invalid taxonomy returns a WP_Error).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy to fetch terms from.
	 * @param array  $args     Unused; kept for signature parity with core.
	 * @return object[]|WP_Error
	 */
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test needs args-based filtering (fields, orderby, ...).
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'invalid_taxonomy', 'Invalid taxonomy.' );
		}

		$state    = &blueline_test_state();
		$term_ids = $state['post_terms'][ (int) $post_id ][ $taxonomy ] ?? array();

		$terms = array();
		foreach ( $term_ids as $term_id ) {
			if ( isset( $state['terms'][ $term_id ] ) ) {
				$terms[] = $state['terms'][ $term_id ];
			}
		}

		return $terms;
	}
}
$GLOBALS['bl_test_hooks']              = array();
$GLOBALS['bl_test_options']            = array();
$GLOBALS['bl_test_option_autoload']    = array();
$GLOBALS['bl_test_transients']         = array();
$GLOBALS['bl_test_cache']              = array();
$GLOBALS['bl_test_nav_menu_locations'] = array();
$GLOBALS['bl_test_cron']               = array();

/**
 * Reset the in-memory option store. Call from setUp() (directly, or via the
 * combined blueline_test_reset()) in any test that touches options.
 */
function blueline_test_reset_options(): void {
	$GLOBALS['bl_test_options']         = array();
	$GLOBALS['bl_test_option_autoload'] = array();
}

/**
 * Write an option value STRAIGHT into the store, firing no hooks at all --
 * the in-memory stand-in for `wp db import`, a `$wpdb` write, or someone
 * editing the row in phpMyAdmin.
 *
 * This exists because update_option() is NOT a way to get an invalid value
 * into storage. inc/settings/page.php registers
 * blueline_settings_sanitize_callback() on `sanitize_option_{$option}` at
 * file scope (deliberately -- see that file's "Every write path is
 * validated" docblock), the stub below dispatches that filter exactly like
 * core does, and blueline_test_reset_hooks() restores a baseline captured
 * AFTER that file-scope registration. So the sanitizer is live in every
 * test, and a test trying to model a corrupt stored value by calling
 * update_option() gets its bad value rejected and the previous one kept
 * instead.
 *
 * That is not a hypothetical. Task 7's fix round added the `choices`
 * save-time guard, and in doing so silently made both read-time clamps
 * untested: two tests that had been passing invalid values through
 * update_option() started asserting against values that were never stored.
 * Deleting either clamp left the whole suite green. Any test whose subject
 * is "what happens when storage already holds something the sanitizer would
 * refuse" has to seed it through here, not through update_option().
 *
 * @param string $option Option name.
 * @param mixed  $value  Value to store verbatim, unsanitized and unfiltered.
 * @return void
 */
function blueline_test_seed_option_bypassing_sanitizer( string $option, $value ): void {
	$GLOBALS['bl_test_options'][ $option ] = $value;
}

/**
 * Every `$autoload` argument update_option()/add_option() were CALLED with
 * for $option, in call order -- `null` for a call that passed none.
 *
 * This stub store deliberately records the ARGUMENT, not an autoload state:
 * nothing here models the wp_options.autoload column, and how core's real
 * update_option() forwards (or does not forward) $autoload to add_option()
 * on a first-ever write is not something this repository can check against
 * core's own source. What a test CAN prove with this is what the theme
 * asked for -- e.g. that a snapshot write passes an explicit `false`
 * instead of leaving the decision to a default -- which is exactly the
 * requirement the settings spec states.
 *
 * @param string $option Option name.
 * @return array<int, mixed> The $autoload argument of each call, in order.
 */
function blueline_test_option_autoload_args( string $option ): array {
	return $GLOBALS['bl_test_option_autoload'][ $option ] ?? array();
}

/**
 * Reset the in-memory transient store. Call from setUp() (directly, or via
 * the combined blueline_test_reset()) in any test that touches transients.
 */
function blueline_test_reset_transients(): void {
	$GLOBALS['bl_test_transients'] = array();
}

/**
 * Reset the in-memory object-cache store. Call from setUp() (directly, or
 * via the combined blueline_test_reset()) in any test that touches
 * wp_cache_*() -- most importantly a test exercising a wp_cache_add() lock
 * (e.g. the settings migration guard), since without a reset the first test
 * to acquire that lock would hold it, unreleased, for the rest of the run.
 */
function blueline_test_reset_cache(): void {
	$GLOBALS['bl_test_cache'] = array();
}

/**
 * Reset the in-memory WP-Cron store. Call from setUp() (directly, or via
 * the combined blueline_test_reset()) in any test that schedules or
 * checks a cron event.
 */
function blueline_test_reset_cron(): void {
	$GLOBALS['bl_test_cron'] = array();
}

/**
 * Reset the in-memory hook store to the state it was in immediately after
 * PHPUnit finished loading every test file -- NOT to empty.
 *
 * Why not empty: production code registers real hooks at file scope (e.g.
 * inc/sportspress.php's `add_filter( 'body_class', ... )`,
 * inc/template-tags.php's `add_filter( 'wp_nav_menu_objects', ... )`). Those
 * files are require_once'd once, from the top of whichever test file needs
 * them, and PHPUnit requires every test file (which is where that
 * require_once lives) while building the test suite -- before any test's
 * setUp() runs. So the FIRST time this function is ever called, in the
 * very first test's setUp(), the hook store already contains every one of
 * those production registrations and nothing a test has added yet: that is
 * captured as the baseline. If this cleared the store to empty instead,
 * production's file-scope add_filter()/add_action() calls would be gone for
 * the rest of the run, because require_once will not execute them a second
 * time to re-register.
 *
 * Call from setUp() (directly, or via the combined blueline_test_reset())
 * in any test that calls add_filter()/add_action() itself, so one test's
 * registration cannot leak into the next.
 */
function blueline_test_reset_hooks(): void {
	static $baseline = null;

	if ( null === $baseline ) {
		$baseline = $GLOBALS['bl_test_hooks'];
		return;
	}

	$GLOBALS['bl_test_hooks'] = $baseline;
}

/**
 * Reset every in-memory store. The common case for a test that touches
 * hooks, options, transients and/or the object cache is this single call
 * rather than remembering each of blueline_test_reset_hooks(),
 * blueline_test_reset_options(), blueline_test_reset_transients() and
 * blueline_test_reset_cache() separately.
 */
function blueline_test_reset(): void {
	blueline_test_reset_hooks();
	blueline_test_reset_options();
	blueline_test_reset_transients();
	blueline_test_reset_cache();
	blueline_test_reset_cron();
	blueline_test_reset_settings_errors();
	blueline_test_reset_admin_pages();
	blueline_test_reset_inline_scripts();
	blueline_test_reset_registered_settings();
	blueline_test_reset_settings_page_hook();
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

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Minimal stand-in for WordPress' remove_filter(): drops the matching
	 * callback from the priority bucket it was registered against, and reports
	 * whether anything was actually removed, as core does.
	 *
	 * Matching is by callback identity at a specific priority, again like core
	 * -- removing at the wrong priority is a silent no-op there and must be one
	 * here too, or a caller that unhooks and re-hooks around a get_option()
	 * would appear to work in tests while leaving the filter live in
	 * production.
	 *
	 * @param string   $tag      Filter name.
	 * @param callable $callback Callback to remove.
	 * @param int      $priority Priority it was registered at.
	 * @return bool
	 */
	function remove_filter( $tag, $callback, $priority = 10 ) {
		$bucket = &$GLOBALS['bl_test_hooks'][ $tag ][ $priority ];

		if ( ! is_array( $bucket ) ) {
			return false;
		}

		$removed = false;

		foreach ( $bucket as $i => $hook ) {
			if ( $hook['cb'] === $callback ) {
				unset( $bucket[ $i ] );
				$removed = true;
			}
		}

		$bucket = array_values( $bucket );

		return $removed;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * Minimal stand-in for WordPress' remove_action(); actions and filters
	 * share one hook store here, exactly as they do in core.
	 *
	 * @param string   $tag      Action name.
	 * @param callable $callback Callback to remove.
	 * @param int      $priority Priority it was registered at.
	 * @return bool
	 */
	function remove_action( $tag, $callback, $priority = 10 ) {
		return remove_filter( $tag, $callback, $priority );
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

if ( ! function_exists( 'blueline_test_fire_added_option_hooks' ) ) {
	/**
	 * Fires the pair of hooks core's real add_option() fires once a brand
	 * new option has actually been written -- shared by update_option()'s
	 * first-write branch below and add_option() itself, since core's own
	 * update_option() literally delegates to add_option() internally for
	 * that case (see update_option()'s docblock for why that matters).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value just added.
	 * @return void
	 */
	function blueline_test_fire_added_option_hooks( $option, $value ) {
		do_action( "add_option_{$option}", $option, $value );
		do_action( 'added_option', $option, $value );
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' update_option(): mirrors core's
	 * documented dispatch order -- sanitize_option_{$option}, then
	 * pre_update_option_{$option}, then pre_update_option, then (if the
	 * value actually changed) the write, then EITHER
	 * add_option_{$option}/added_option (first-ever write) OR
	 * update_option_{$option} (every subsequent write) -- never both, and
	 * never the wrong one for which case this is.
	 *
	 * The sanitize step is the hook register_setting()'s sanitize_callback
	 * attaches to, and the behaviour P1's settings layer depends on to
	 * guarantee WP-CLI and JSON import cannot bypass validation. The two
	 * pre_update_option* filters are what a cross-tab merge lives on: they
	 * are the only point in this sequence that receives the value CURRENTLY
	 * in storage as a plain parameter, with no get_option() call and no
	 * race, which sanitize_option_* does not get.
	 *
	 * $old_value is read from the in-memory store before this call's write
	 * lands, exactly as core reads it from the DB/cache before its own
	 * write -- never the value this same call is about to write.
	 *
	 * The two pre_update_option* filters do NOT share an argument order --
	 * a well-known WordPress footgun this stub must reproduce faithfully
	 * rather than "fix":
	 *
	 *   pre_update_option_{$option} -> ( $value, $old_value, $option )
	 *   pre_update_option (generic) -> ( $value, $option, $old_value )
	 *
	 * $option and $old_value are swapped between the two. A callback
	 * written for one and reused on the other silently reads the wrong
	 * argument, which is exactly the bug this stub exists to be able to
	 * catch (see BootstrapFidelityTest::
	 * test_update_option_dispatches_hooks_in_core_order(), which asserts
	 * each hook's arguments individually, not merely that each fires).
	 *
	 * The add_option_{$option}-vs-update_option_{$option} branch below is a
	 * SEPARATE fidelity fix, added after a task-6 code review caught this
	 * stub firing update_option_{$option} unconditionally, including on an
	 * option's first-ever write. Core's real update_option() does not: when
	 * the option does not already exist, $old_value equals the (usually
	 * `false`) registered default, and update_option() short-circuits into
	 * calling add_option() internally -- which fires add_option_{$option}
	 * and added_option, NOT update_option_{$option}/updated_option. A
	 * callback hooked only to update_option_{$option} (as
	 * inc/settings/cache.php's purge trigger originally was) would silently
	 * never run on that first save. See BootstrapFidelityTest::
	 * test_update_option_fires_add_option_hook_on_first_write_only() for the
	 * pinning test, and inc/settings/cache.php's blueline_flush_page_cache_on_first_save()
	 * for the production callback this asymmetry required.
	 *
	 * Also matches core's short-circuit: if the option already exists and
	 * the value survives sanitizing/the pre_update_option* filters
	 * unchanged, nothing is written and update_option_{$option} does not
	 * fire -- an unconditional write/fire here would let a test believe a
	 * merge or migration guard runs on every save when core would in fact
	 * skip a no-op one.
	 *
	 * First-write delegation is a REAL re-entrant call into this stub's own
	 * add_option() below, not merely a comment saying so -- a task-7
	 * fix-round-5 finding caught this stub previously writing $value and
	 * firing the add_option_{$option}/added_option pair directly inline,
	 * which is faithful to WHICH hooks fire but not to HOW core gets
	 * there. Core's real add_option() is a fully independent public
	 * function that re-applies sanitize_option_{$option} to whatever it is
	 * given, regardless of who calls it or why -- so on a genuine first
	 * write, the sanitize callback runs TWICE (once here, once again
	 * inside add_option()) while pre_update_option_{$option} (where a
	 * cross-tab merge, if one is registered, lives) runs only ONCE, here,
	 * BEFORE the delegation -- never again inside add_option(), which has
	 * no equivalent filter of its own. A sanitize callback that
	 * unconditionally appends bookkeeping (e.g. inc/settings/page.php's
	 * `_posted_fields`) to every value it returns will therefore have that
	 * bookkeeping re-added by the second, unmerged pass and persisted
	 * verbatim into the stored option on a first write -- a real, if
	 * currently cosmetic, quirk of core's own documented behaviour (see
	 * BootstrapFidelityTest::test_update_option_first_write_sanitizes_twice_but_merges_once()),
	 * not something this stub should paper over by only firing the hooks
	 * without the double dispatch that produces them.
	 *
	 * sanitize_option_{$option} dispatches with THREE arguments, matching
	 * core's real sanitize_option() (wp-includes/formatting.php) exactly:
	 * ( $value, $option, $original_value ) -- $original_value is the value
	 * as it arrived at this function, captured before any filter has had a
	 * chance to touch it. This stub does not model core's per-option
	 * built-in switch-based sanitization (the case blocks inside core's
	 * sanitize_option() for e.g. 'blogname', 'siteurl', etc.) at all -- it
	 * has no notion of any specific option name needing bespoke coercion --
	 * so here $original_value is simply $value at entry, which is exactly
	 * what core's sanitize_option() itself does before ITS switch statement
	 * runs. A callback that reads $original_value to recover the raw
	 * pre-sanitize input (something the 2-argument version of this filter
	 * could never let it do) now behaves identically here and in
	 * production. This was a real, previously-undiscovered gap between this
	 * stub and core, found while building tests/WpCoreContractTest.php, and
	 * closed on review rather than left as a recorded exception -- see that
	 * fixture's now-empty `known_gaps`.
	 *
	 * The generic 'update_option' action fires immediately before the write
	 * on every write to an option that ALREADY exists -- i.e. only on this
	 * function's subsequent-write branch below, never on a first-ever write
	 * (which returns via add_option()'s delegation before reaching it), and
	 * 'updated_option' fires immediately after update_option_{$option}, on
	 * that same branch. Both are core's generic, option-name-agnostic
	 * counterparts to update_option_{$option} -- a callback hooked onto
	 * either generically (an audit log, for instance) rather than a
	 * specific option name now runs here exactly as it would in production;
	 * previously this stub did not fire either at all. See this function's
	 * own docblock note above on sanitize_option_{$option} for why this,
	 * too, is a closed gap rather than a documented one.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    New value.
	 * @param mixed  $autoload Recorded (see blueline_test_option_autoload_args())
	 *                          but not otherwise honoured -- this store has
	 *                          no autoload column to honour it in.
	 * @return bool True if the value was written, false if the option
	 *              already held this exact value and nothing changed.
	 */
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['bl_test_option_autoload'][ $option ][] = $autoload;

		$original_value = $value;
		$value          = apply_filters( "sanitize_option_{$option}", $value, $option, $original_value );

		$exists    = array_key_exists( $option, $GLOBALS['bl_test_options'] );
		$old_value = $exists ? $GLOBALS['bl_test_options'][ $option ] : false;

		$value = apply_filters( "pre_update_option_{$option}", $value, $old_value, $option );
		$value = apply_filters( 'pre_update_option', $value, $option, $old_value );

		if ( $exists && $value === $old_value ) {
			return false;
		}

		if ( ! $exists ) {
			// First-ever write: core delegates to add_option() here, not
			// its own UPDATE path -- a real re-entrant call, not an inline
			// approximation of it; see this function's own docblock for
			// why that distinction is load-bearing.
			return add_option( $option, $value );
		}

		do_action( 'update_option', $option, $old_value, $value );

		$GLOBALS['bl_test_options'][ $option ] = $value;

		do_action( "update_option_{$option}", $old_value, $value, $option );
		do_action( 'updated_option', $option, $old_value, $value );

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_option(): applies
	 * sanitize_option_{$option} unconditionally, exactly as core does --
	 * before the exists check, so the filter still runs even on a call that
	 * ends up returning false because the option is already there. Fires
	 * the same add_option_{$option}/added_option pair update_option()'s
	 * first-write branch fires above, since in real core both paths are
	 * this same function.
	 *
	 * The sanitize_option_{$option} filter dispatches with THREE arguments
	 * here too -- ( $value, $option, $original_value ), $original_value
	 * being $value as it arrived at THIS call to add_option() (whether
	 * called directly, or reached via update_option()'s first-write
	 * delegation, in which case it is whatever value that delegation passed
	 * in, not the original caller's raw input) -- see update_option()'s
	 * docblock for the fuller rationale, which applies identically here.
	 *
	 * The generic 'add_option' action fires immediately before the write,
	 * on every add that actually happens -- core's option-name-agnostic
	 * counterpart to add_option_{$option}, previously not fired by this
	 * stub at all.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Option value.
	 * @param string $deprecated Unused; kept for signature parity.
	 * @param mixed  $autoload   Recorded (see blueline_test_option_autoload_args())
	 *                            but not otherwise honoured -- this store
	 *                            has no autoload column to honour it in.
	 * @return bool True on add, false if the option already exists.
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $deprecated is signature parity with WP core.
		$GLOBALS['bl_test_option_autoload'][ $option ][] = $autoload;

		$original_value = $value;
		$value          = apply_filters( "sanitize_option_{$option}", $value, $option, $original_value );

		if ( array_key_exists( $option, $GLOBALS['bl_test_options'] ) ) {
			return false;
		}

		do_action( 'add_option', $option, $value );

		$GLOBALS['bl_test_options'][ $option ] = $value;

		blueline_test_fire_added_option_hooks( $option, $value );

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Minimal stand-in for WordPress' delete_option() over the in-memory store.
	 *
	 * Returns false for an option that was not stored, true when a row was
	 * actually removed. This stub returned an unconditional `true` until
	 * SettingsDeleteDataTest went looking for the difference: any caller
	 * distinguishing "deleted four things" from "there was nothing to delete"
	 * -- which is exactly what a teardown reports back to an admin -- got the
	 * wrong answer in tests while getting the right one in production.
	 *
	 * That core returns false for an absent option is this stub's own
	 * convention here, matching the documented contract; it is NOT independently
	 * verified, because there is no WP core checkout in this worktree (see
	 * tests/WpCoreContractTest.php, whose oracle job skips for the same reason).
	 * A test asserting on this return value is asserting on the stub.
	 *
	 * @param string $option Option name.
	 * @return bool Whether a stored option was removed.
	 */
	function delete_option( $option ) {
		if ( ! isset( $GLOBALS['bl_test_options'][ $option ] ) ) {
			return false;
		}

		unset( $GLOBALS['bl_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_transient() over an in-memory
	 * store -- no expiry is modelled, so a transient set by set_transient()
	 * is visible until delete_transient() or blueline_test_reset_transients()
	 * removes it.
	 *
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_transient( $transient ) {
		return array_key_exists( $transient, $GLOBALS['bl_test_transients'] )
			? $GLOBALS['bl_test_transients'][ $transient ]
			: false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Minimal stand-in for WordPress' set_transient().
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Expiration in seconds (unused; this stub models no elapsed time).
	 * @return true
	 */
	function set_transient( $transient, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; this stub has no notion of elapsed time to expire against.
		$GLOBALS['bl_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Minimal stand-in for WordPress' delete_transient().
	 *
	 * @param string $transient Transient name.
	 * @return true
	 */
	function delete_transient( $transient ) {
		// False for an absent transient, true when one was actually removed --
		// the same correction, and the same caveat, as delete_option() above.
		if ( ! isset( $GLOBALS['bl_test_transients'][ $transient ] ) ) {
			return false;
		}

		unset( $GLOBALS['bl_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_cache_add() over an in-memory store,
	 * keyed by group:key -- true (and stores) only if that pair is not
	 * already present, exactly like core's "add if absent" semantics.
	 *
	 * $expire is accepted for signature parity but not enforced: this stub
	 * models no elapsed time, so a lock acquired here holds until
	 * wp_cache_delete() or blueline_test_reset_cache() releases it. Any test
	 * exercising a wp_cache_add() lock across multiple cases must reset the
	 * cache store between them (blueline_test_reset() does this) or the
	 * first acquisition will appear to hold forever, unlike production where
	 * $expire eventually reclaims it.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $data   Value to store.
	 * @param string $group  Cache group.
	 * @param int    $expire Expiration in seconds (unused; kept for signature parity).
	 * @return bool
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; this stub has no notion of elapsed time to expire against.
		$cache_key = $group . ':' . $key;

		if ( array_key_exists( $cache_key, $GLOBALS['bl_test_cache'] ) ) {
			return false;
		}

		$GLOBALS['bl_test_cache'][ $cache_key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_cache_delete().
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 * @return bool True if the key existed and was removed, false otherwise.
	 */
	function wp_cache_delete( $key, $group = '' ) {
		$cache_key = $group . ':' . $key;
		$existed   = array_key_exists( $cache_key, $GLOBALS['bl_test_cache'] );
		unset( $GLOBALS['bl_test_cache'][ $cache_key ] );
		return $existed;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_next_scheduled(): the timestamp
	 * currently scheduled for $hook, or false if none is. $args is
	 * accepted for signature parity but not distinguished -- nothing in
	 * this theme schedules the same hook with two different argument sets.
	 *
	 * @param string $hook Cron hook name.
	 * @param array  $args Unused; signature parity with WP core.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; see docblock.
		return $GLOBALS['bl_test_cron'][ $hook ] ?? false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_schedule_single_event(): records
	 * $timestamp as the next occurrence of $hook, replacing whatever was
	 * previously scheduled for it.
	 *
	 * @param int    $timestamp Unix timestamp to schedule for.
	 * @param string $hook      Cron hook name.
	 * @param array  $args      Unused; signature parity with WP core.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; see docblock.
		$GLOBALS['bl_test_cron'][ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_unschedule_event(): clears
	 * whatever is scheduled for $hook. $timestamp is accepted for
	 * signature parity but not checked against what is actually stored --
	 * this stub only ever tracks one scheduled occurrence per hook.
	 *
	 * @param int    $timestamp Unused beyond signature parity; see docblock.
	 * @param string $hook      Cron hook name.
	 * @param array  $args      Unused; signature parity with WP core.
	 * @return bool
	 */
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; see docblock.
		unset( $GLOBALS['bl_test_cron'][ $hook ] );
		return true;
	}
}

// -----------------------------------------------------------------------
// Task 7 (Appearance -> Blueline admin page) additions below. Every stub
// in this block exists because inc/settings/page.php is the first file in
// this theme to touch wp-admin's Settings API and page-registration
// surface -- none of it was needed before. Each mirrors core's real
// signature/behaviour closely enough for a unit test to exercise the
// production code path directly (register_setting()'s wiring of
// sanitize_option_{$option}, add_settings_error()'s "no escaping, that's
// the caller's job" contract, etc.) without pretending to be a faithful
// full reimplementation of wp-admin.
// -----------------------------------------------------------------------

$GLOBALS['bl_test_settings_errors']     = array();
$GLOBALS['bl_test_registered_settings'] = array();
$GLOBALS['bl_test_admin_pages']         = array();
$GLOBALS['blueline_settings_page_hook'] = null;

/**
 * Reset the in-memory settings-errors store. Call from setUp() (directly,
 * or via blueline_test_reset()) in any test that calls add_settings_error()
 * or reads get_settings_errors(), so one test's errors cannot leak into the
 * next.
 *
 * @return void
 */
function blueline_test_reset_settings_errors(): void {
	$GLOBALS['bl_test_settings_errors'] = array();
}

/**
 * Reset the in-memory registered-admin-pages store (add_theme_page()'s
 * call log). Call from setUp() in any test that asserts against it.
 *
 * @return void
 */
function blueline_test_reset_admin_pages(): void {
	$GLOBALS['bl_test_admin_pages'] = array();
}

/**
 * Reset the in-memory register_setting() call log. Call from setUp()
 * (directly, or via blueline_test_reset()) in any test that asserts
 * against it, so a stale entry from an earlier test cannot make a
 * regressed registration look present.
 *
 * @return void
 */
function blueline_test_reset_registered_settings(): void {
	$GLOBALS['bl_test_registered_settings'] = array();
}

/**
 * Reset blueline_settings_page_hook()'s stored hook suffix. Call from
 * setUp() (directly, or via blueline_test_reset()) in any test that reads
 * or sets it, so one test's admin_menu registration cannot leak into the
 * next -- the same shape as blueline_test_reset_hooks()'s own baseline
 * problem, but for a single production value instead of the whole hook
 * store.
 *
 * @return void
 */
function blueline_test_reset_settings_page_hook(): void {
	$GLOBALS['blueline_settings_page_hook'] = null;
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_unslash().
	 *
	 * @param mixed $value Value to strip slashes from.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Minimal stand-in for WordPress' sanitize_key().
	 *
	 * @param string $key Key to sanitize.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_html__().
	 *
	 * @param string $text Text to translate (not) and escape.
	 * @param string $d    Text domain (unused, kept for signature parity).
	 * @return string
	 */
	function esc_html__( $text, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test loads translations, so this stub does not delegate to __() (its own definition just returns $t unchanged anyway -- see above -- and calling it here would be a translation-function call site with a non-literal argument, which is exactly the pattern WordPress.WP.I18n exists to flag in REAL plugin code; delegating adds no behaviour a direct return doesn't already have in this stub environment).
		return esc_html( (string) $text );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_html_e().
	 *
	 * @param string $text Text to translate (not), escape, and echo.
	 * @param string $d    Text domain (unused, kept for signature parity).
	 * @return void
	 */
	function esc_html_e( $text, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test loads translations, so this stub does not delegate to esc_html__() -- calling a same-named-pattern i18n function with a variable argument from inside another stub's body is exactly what WordPress.WP.I18n exists to flag in real plugin code, so this escapes directly instead.
		echo esc_html( (string) $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for esc_html_e(); esc_html() directly above already escapes.
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_attr_e(): escapes for an attribute
	 * and echoes, without loading translations.
	 *
	 * @param string $text Text to translate (not), escape, and echo.
	 * @param string $d    Text domain (unused, kept for signature parity).
	 * @return void
	 */
	function esc_attr_e( $text, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core, matching esc_html_e() above.
		echo esc_attr( (string) $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for esc_attr_e(); esc_attr() already escapes.
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Minimal stand-in for WordPress' is_admin().
	 *
	 * Defaults to false, i.e. the FRONT END -- the context every theme-side
	 * filter in this codebase is scoped to, and the one whose behaviour the
	 * tests care about. A test that needs the admin branch sets
	 * $GLOBALS['bl_test_is_admin'] itself.
	 *
	 * @return bool
	 */
	function is_admin() {
		return ! empty( $GLOBALS['bl_test_is_admin'] );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_doing_ajax().
	 *
	 * Core's own implementation is `apply_filters( 'wp_doing_ajax',
	 * defined( 'DOING_AJAX' ) && DOING_AJAX )`; the constant half is
	 * honoured here verbatim so this stub cannot disagree with production
	 * about the one thing it actually reports. The `$GLOBALS` escape hatch
	 * exists because a PHP constant, once defined, can never be undefined:
	 * without it, the FIRST test in a process to model an AJAX request
	 * would silently pin every later test in that same process to the AJAX
	 * branch. Same pattern, and same reason, as is_admin() above.
	 *
	 * @return bool
	 */
	function wp_doing_ajax() {
		if ( ! empty( $GLOBALS['bl_test_doing_ajax'] ) ) {
			return true;
		}

		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}
}

if ( ! function_exists( 'wp_add_inline_style' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_add_inline_style(): records every
	 * call in blueline_test_state()['inline_styles'] as [$handle, $css]
	 * rather than actually queuing anything for output, so a test can
	 * assert on exactly what a real call site tried to add without a real
	 * style-dependency system behind it.
	 *
	 * @param string $handle Registered style handle.
	 * @param string $data   CSS to add inline.
	 * @return bool Always true, matching core's own "handle exists" return
	 *              shape closely enough for every call site in this theme,
	 *              none of which branch on the return value.
	 */
	function wp_add_inline_style( $handle, $data ) {
		$state                    = &blueline_test_state();
		$state['inline_styles'][] = array( $handle, $data );

		return true;
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	/**
	 * Minimal stand-in for WordPress' esc_attr__().
	 *
	 * @param string $text Text to translate (not) and escape.
	 * @param string $d    Text domain (unused, kept for signature parity).
	 * @return string
	 */
	function esc_attr__( $text, $d = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; no test loads translations, so this stub does not delegate to __() -- see esc_html__()'s own comment above for why.
		return esc_attr( (string) $text );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Minimal stand-in for WordPress' admin_url().
	 *
	 * @param string $path Path relative to wp-admin/.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_create_nonce(): a deterministic
	 * token derived from the action alone.
	 *
	 * Deliberately NOT a reimplementation of core's real nonce (which is
	 * tied to the user, the session token and a 12/24-hour tick this stub
	 * environment has none of): what the tests using it need is that a
	 * token minted for action A verifies for action A and nothing else, so
	 * a handler's check_admin_referer() call can be shown to actually gate
	 * the request.
	 *
	 * @param string|int $action Action the nonce is scoped to.
	 * @return string
	 */
	function wp_create_nonce( $action = -1 ) {
		return substr( md5( 'bl-test-nonce|' . $action ), 0, 10 );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_verify_nonce().
	 *
	 * @param string     $nonce  Token to check.
	 * @param string|int $action Action it must have been minted for.
	 * @return int|false 1 when valid, false otherwise. Core also returns 2
	 *                    for a token in its second tick; this stub models
	 *                    no clock, so it never does.
	 */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return hash_equals( wp_create_nonce( $action ), (string) $nonce ) ? 1 : false;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_nonce_field(): emits only the
	 * nonce input, not core's additional `_wp_http_referer` field (nothing
	 * in this theme reads it).
	 *
	 * @param string|int $action  Action the nonce is scoped to.
	 * @param string     $name    Field name.
	 * @param bool       $referer Unused; kept for signature parity.
	 * @param bool       $display Echo the field (true) or return it.
	 * @return string The field markup.
	 */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $referer is signature parity with WP core; this stub emits no referer field.
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';

		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for wp_nonce_field(); both dynamic parts are esc_attr()'d as they are assembled above.
		}

		return $field;
	}
}

if ( ! function_exists( 'check_admin_referer' ) ) {
	/**
	 * Minimal stand-in for WordPress' check_admin_referer(): reads the
	 * token out of $_REQUEST and wp_die()s (which this stub environment
	 * raises as Blueline_Test_WP_Die_Exception) when it does not verify --
	 * core reaches the same dead end via wp_nonce_ays().
	 *
	 * @param string|int $action    Action the nonce must have been minted for.
	 * @param string     $query_arg Request key carrying the token.
	 * @return int 1 when the token verifies.
	 */
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		$nonce = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this IS the nonce check.

		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( 'Are you sure you want to do this?' );
		}

		return 1;
	}
}

if ( ! class_exists( 'Blueline_Test_Redirect_Exception' ) ) {
	/**
	 * Thrown by the wp_safe_redirect() stub below instead of returning, so the
	 * `exit` that follows every real redirect is never reached.
	 *
	 * Without this, a handler ending `wp_safe_redirect( ... ); exit;` is simply
	 * not testable: `exit` cannot be intercepted from PHP, so the PHPUnit
	 * process dies mid-run. That is why the delete-all-data handler shipped
	 * with no coverage at all while its pure counterpart was thoroughly tested
	 * -- the untestable shape, not the risk, decided what got tested.
	 *
	 * Carries the target URL so a test can assert where a handler sent the
	 * admin, not merely that it redirected.
	 */
	class Blueline_Test_Redirect_Exception extends \RuntimeException {

		/**
		 * The URL passed to wp_safe_redirect().
		 *
		 * @var string
		 */
		public string $location = '';
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_safe_redirect(): throws rather than
	 * returning, so the caller's `exit` is unreachable.
	 *
	 * This stub does NOT model core's allow-listing of the destination host
	 * (that is what "safe" means in the real function's name), because nothing
	 * in this theme redirects anywhere but back to its own settings page. A
	 * test asserting on this stub is asserting on the stub; core's host
	 * filtering is not verified here, and there is no WP core checkout in this
	 * worktree to verify it against.
	 *
	 * @param string $location Destination URL.
	 * @param int    $status   HTTP status (accepted for signature parity, unused).
	 * @return void
	 * @throws Blueline_Test_Redirect_Exception Always.
	 */
	function wp_safe_redirect( $location, $status = 302 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with core; this stub models the control-flow effect only, not the status code.
		$exception           = new Blueline_Test_Redirect_Exception( 'redirect: ' . (string) $location );
		$exception->location = (string) $location;
		throw $exception;
	}
}

if ( ! class_exists( 'Blueline_Test_WP_Die_Exception' ) ) {
	/**
	 * Thrown by the wp_die() stub below instead of actually terminating the
	 * process -- lets a test assert a capability guard fired via
	 * expectException() rather than killing the PHPUnit run, matching the
	 * pattern WP_UnitTestCase's own real wp_die() override uses.
	 */
	class Blueline_Test_WP_Die_Exception extends \RuntimeException {}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_die(): throws rather than exits, so
	 * a capability-guard test can assert on it.
	 *
	 * @param string $message Message (unused beyond the thrown exception).
	 * @param string $title   Title (unused, kept for signature parity).
	 * @param array  $args    Args (unused, kept for signature parity).
	 * @return void
	 * @throws Blueline_Test_WP_Die_Exception Always.
	 */
	function wp_die( $message = '', $title = '', $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core.
		$text = is_string( $message ) ? wp_strip_all_tags( $message ) : 'wp_die';
		throw new Blueline_Test_WP_Die_Exception( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output at all: this is a thrown exception's message in a test-only stub, never echoed; wp_die()'s real implementation is what would eventually echo something, and that happens in WordPress core, not here.
	}
}

if ( ! function_exists( 'add_theme_page' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_theme_page(): records the call so
	 * a test can assert the capability and slug it registered with, and
	 * returns a plausible hook suffix.
	 *
	 * @param string   $page_title Page title.
	 * @param string   $menu_title Menu title.
	 * @param string   $capability Required capability.
	 * @param string   $menu_slug  Menu slug.
	 * @param callable $callback   Render callback.
	 * @return string The hook suffix.
	 */
	function add_theme_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '' ) {
		$hook = 'appearance_page_' . $menu_slug;

		$GLOBALS['bl_test_admin_pages'][] = array(
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'callback'   => $callback,
			'hook'       => $hook,
		);

		return $hook;
	}
}

if ( ! function_exists( 'register_setting' ) ) {
	/**
	 * Minimal stand-in for WordPress' register_setting(): the one piece of
	 * its behaviour Task 7 actually depends on -- wiring $args['sanitize_callback']
	 * onto the `sanitize_option_{$option_name}` filter, exactly as core's
	 * real implementation does, so a test can exercise the full
	 * update_option() -> sanitize_option_* -> pre_update_option_* dispatch
	 * chain (see the update_option() stub above) with the real production
	 * sanitize callback wired up the same way it is in wp-admin.
	 *
	 * @param string $option_group Settings group name.
	 * @param string $option_name  Option name.
	 * @param array  $args         Registration args; only 'sanitize_callback' is honoured.
	 * @return void
	 */
	function register_setting( $option_group, $option_name, $args = array() ) {
		$GLOBALS['bl_test_registered_settings'][ $option_group ][ $option_name ] = $args;

		if ( ! empty( $args['sanitize_callback'] ) ) {
			add_filter( "sanitize_option_{$option_name}", $args['sanitize_callback'] );
		}
	}
}

if ( ! function_exists( 'add_settings_error' ) ) {
	/**
	 * Minimal stand-in for WordPress' add_settings_error(): stores the
	 * message VERBATIM, deliberately not escaping it -- matching core's
	 * real contract exactly (escaping is documented as the caller's job,
	 * enforced at the point $message is constructed, not here).
	 *
	 * @param string $setting Settings group/option the error belongs to.
	 * @param string $code    Error code (also used as the field key by
	 *                         blueline_settings_sanitize_callback()).
	 * @param string $message Error message.
	 * @param string $type    'error', 'success', 'warning', or 'info'.
	 * @return void
	 */
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['bl_test_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}

if ( ! function_exists( 'get_settings_errors' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_settings_errors().
	 *
	 * @param string $setting  Optional. Filter to errors for this setting only.
	 * @param bool   $sanitize Unused; kept for signature parity.
	 * @return array<int, array{setting:string, code:string, message:string, type:string}>
	 */
	function get_settings_errors( $setting = '', $sanitize = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature parity with WP core; this stub does not model the sanitize-on-read transient quirk.
		$errors = $GLOBALS['bl_test_settings_errors'] ?? array();

		if ( '' === $setting ) {
			return $errors;
		}

		return array_values(
			array_filter(
				$errors,
				static fn( $error ) => $setting === $error['setting']
			)
		);
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	/**
	 * Minimal stand-in for WordPress' settings_fields(): echoes the hidden
	 * `option_page` field a real submission needs; deliberately does not
	 * model the nonce core's real version adds (production relies on the
	 * real WordPress function for that; this stub only needs to let a
	 * render-output test see a well-formed <form>).
	 *
	 * @param string $option_group Settings group name.
	 * @return void
	 */
	function settings_fields( $option_group ) {
		echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for settings_fields(), whose real implementation self-escapes; esc_attr() is applied inline above.
		echo '<input type="hidden" name="action" value="update" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal, nothing to escape.
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	/**
	 * Minimal stand-in for WordPress' submit_button().
	 *
	 * @param string $text Button text.
	 * @param string $type Button type (unused beyond a CSS class).
	 * @param string $name Button name attribute.
	 * @return void
	 */
	function submit_button( $text = 'Save Changes', $type = 'primary', $name = 'submit' ) {
		echo '<p class="submit"><button type="submit" name="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '">' . esc_html( $text ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for submit_button(); every dynamic part above is individually escaped.
	}
}

if ( ! function_exists( 'wp_dropdown_pages' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_dropdown_pages(): builds a <select>
	 * from blueline_test_state()'s registered posts (blueline_test_register_post()),
	 * enough for a test to assert the selected option and that page titles
	 * (not raw IDs) are what an admin sees.
	 *
	 * @param array $args Same shape as core's own args; 'name', 'id',
	 *                     'selected', 'show_option_none', 'option_none_value'
	 *                     and 'echo' are honoured.
	 * @return string|void Markup when 'echo' => false, otherwise void (echoes).
	 */
	function wp_dropdown_pages( $args = array() ) {
		$defaults = array(
			'name'              => 'page_id',
			'id'                => '',
			'selected'          => 0,
			'show_option_none'  => '',
			'option_none_value' => '',
			'echo'              => 1,
		);
		$r        = array_merge( $defaults, $args );

		$state = &blueline_test_state();
		$pages = array();
		foreach ( $state['posts'] as $id => $post ) {
			$pages[ $id ] = $post['title'] ?? ( 'Page ' . $id );
		}
		ksort( $pages );

		$id_attr = '' !== $r['id'] ? ' id="' . esc_attr( $r['id'] ) . '"' : '';
		$output  = '<select name="' . esc_attr( $r['name'] ) . '"' . $id_attr . '>' . "\n";

		if ( '' !== $r['show_option_none'] ) {
			$none_selected = (int) $r['selected'] === (int) $r['option_none_value'];
			$output       .= '<option value="' . esc_attr( (string) $r['option_none_value'] ) . '"' . ( $none_selected ? ' selected="selected"' : '' ) . '>' . esc_html( $r['show_option_none'] ) . "</option>\n";
		}

		foreach ( $pages as $id => $title ) {
			$selected = (int) $r['selected'] === (int) $id;
			$output  .= '<option value="' . esc_attr( (string) $id ) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $title ) . "</option>\n";
		}

		$output .= "</select>\n";

		if ( $r['echo'] ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for wp_dropdown_pages(); every dynamic part above is individually escaped when $output was built.
			return null;
		}

		return $output;
	}
}

/**
 * Register a fake test page/post with a human-readable title, extending
 * blueline_test_register_post() (which only carries status + permalink) so
 * wp_dropdown_pages()'s stub above has something meaningful to label an
 * option with.
 *
 * @param int    $id     Post ID.
 * @param string $status Post status.
 * @param string $title  Page title.
 * @return void
 */
function blueline_test_register_page_with_title( int $id, string $status, string $title ): void {
	blueline_test_register_post( $id, $status );
	$state                          = &blueline_test_state();
	$state['posts'][ $id ]['title'] = $title;
}

if ( ! function_exists( 'wp_dropdown_categories' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_dropdown_categories(): builds a
	 * <select> from blueline_test_state()'s registered terms
	 * (blueline_test_register_term()), scoped to the requested `taxonomy` --
	 * enough for a test to assert the selected option and that term names
	 * (not raw IDs) are what an admin sees. Deliberately the same shape as
	 * the wp_dropdown_pages() stub immediately above, since
	 * blueline_settings_render_field() (inc/settings/page.php) uses both the
	 * same way for their respective field types.
	 *
	 * @param array $args Same shape as core's own args; 'taxonomy', 'name',
	 *                     'id', 'selected', 'show_option_none',
	 *                     'option_none_value' and 'echo' are honoured.
	 * @return string|void Markup when 'echo' => false, otherwise void (echoes).
	 */
	function wp_dropdown_categories( $args = array() ) {
		$defaults = array(
			'taxonomy'          => 'category',
			'name'              => 'cat',
			'id'                => '',
			'selected'          => 0,
			'show_option_none'  => '',
			'option_none_value' => '',
			'echo'              => 1,
		);
		$r        = array_merge( $defaults, $args );

		$state = &blueline_test_state();
		$terms = array();
		foreach ( $state['terms'] as $id => $term ) {
			if ( $term->taxonomy === $r['taxonomy'] ) {
				$terms[ $id ] = $term->name;
			}
		}
		ksort( $terms );

		$id_attr = '' !== $r['id'] ? ' id="' . esc_attr( $r['id'] ) . '"' : '';
		$output  = '<select name="' . esc_attr( $r['name'] ) . '"' . $id_attr . '>' . "\n";

		if ( '' !== $r['show_option_none'] ) {
			$none_selected = (int) $r['selected'] === (int) $r['option_none_value'];
			$output       .= '<option value="' . esc_attr( (string) $r['option_none_value'] ) . '"' . ( $none_selected ? ' selected="selected"' : '' ) . '>' . esc_html( $r['show_option_none'] ) . "</option>\n";
		}

		foreach ( $terms as $id => $name ) {
			$selected = (int) $r['selected'] === (int) $id;
			$output  .= '<option value="' . esc_attr( (string) $id ) . '"' . ( $selected ? ' selected="selected"' : '' ) . '>' . esc_html( $name ) . "</option>\n";
		}

		$output .= "</select>\n";

		if ( $r['echo'] ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- this IS the stand-in for wp_dropdown_categories(); every dynamic part above is individually escaped when $output was built.
			return null;
		}

		return $output;
	}
}

$GLOBALS['bl_test_inline_scripts'] = array();

/**
 * Reset the in-memory wp_add_inline_script() call log. Call from setUp()
 * (directly, or via blueline_test_reset()) in any test that asserts
 * against it.
 *
 * @return void
 */
function blueline_test_reset_inline_scripts(): void {
	$GLOBALS['bl_test_inline_scripts'] = array();
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	/**
	 * Minimal stand-in for WordPress' wp_add_inline_script(): records the
	 * call (handle, data, position) so a test can assert a script was
	 * enqueued for the right handle and that its content does what it
	 * claims to. Does not model actually attaching the snippet to a real
	 * `<script>` tag -- no test needs that; the target of these calls is
	 * always the browser's own runtime, verified separately.
	 *
	 * @param string $handle   Script handle to attach the inline code to.
	 * @param string $data     The inline JavaScript.
	 * @param string $position 'before' or 'after' the handle's own script.
	 * @return bool
	 */
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['bl_test_inline_scripts'][] = array(
			'handle'   => $handle,
			'data'     => $data,
			'position' => $position,
		);
		return true;
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_stylesheet_directory(): returns
	 * the directory used by blueline_stylesheet_version() to find style.css.
	 * For tests, returns a temporary directory that doesn't have style.css,
	 * so filemtime() fails and the function falls back to BLUELINE_VERSION.
	 *
	 * @return string
	 */
	function get_stylesheet_directory() {
		return sys_get_temp_dir();
	}
}

if ( ! function_exists( 'get_stylesheet_uri' ) ) {
	/**
	 * Minimal stand-in for WordPress' get_stylesheet_uri().
	 *
	 * @return string
	 */
	function get_stylesheet_uri() {
		return 'http://example.com/style.css';
	}
}

if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	/**
	 * Minimal stand-in for WordPress' flush_rewrite_rules(): a no-op.
	 *
	 * Exists only so a test may fire `after_switch_theme` for real.
	 * inc/account/endpoints.php registers this core function directly on
	 * that hook at file scope, so any do_action( 'after_switch_theme' )
	 * reaches it -- there is no rewrite-rule state in this stub
	 * environment for it to act on, and no test asserts anything about it;
	 * it just must not be an undefined function when the hook runs.
	 *
	 * @param bool $hard Unused; signature parity with WP core.
	 * @return void
	 */
	function flush_rewrite_rules( $hard = true ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WP core; see docblock.
	}
}
