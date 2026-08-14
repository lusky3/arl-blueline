<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests.
 *
 * @package blueline
 */

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- same trade-off as tests/bootstrap.php's identical disable: this file's one-off wc_get_orders() stub has nowhere more useful to live than beside the one test class that needs it.

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/commerce.php';
require_once __DIR__ . '/../inc/season-state.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/homepage-modules.php';

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * Minimal stand-in for WooCommerce's wc_get_orders(): always empty. No
	 * test in this file needs a real order, only to prove
	 * blueline_get_user_registration_status() reaches (or safely stops
	 * short of reaching) this call at all -- see
	 * blueline_find_registration_order()'s own null-safe handling of an
	 * empty result.
	 *
	 * @param array $args Query args (unused; kept for signature parity).
	 * @return array
	 */
	function wc_get_orders( $args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with WooCommerce; this stub never has a real order to return.
		return array();
	}
}

/**
 * Covers blueline_resolve_registration_term() (inc/settings/commerce.php):
 * the Commerce tab's hardcoded-term-ID-to-verified-term-ID resolver, and the
 * four call sites (inc/season-state.php, inc/account/player-data.php x2,
 * inc/homepage-modules.php) Task 9 rewired to use it instead of reading
 * BLUELINE_REGISTRATION_TERM_ID directly.
 *
 * The trap this resolver exists to close: BLUELINE_REGISTRATION_TERM_ID (91)
 * was always a hardcoded, UNVERIFIED term ID -- an inventory pass tried to
 * confirm it by numeric ID and failed. If that ID ever stops resolving to a
 * real product_cat term (e.g. a volunteer deletes and recreates the
 * "Registration" category, which assigns the recreated term a NEW term_id),
 * every call site that trusted the raw constant would silently degrade:
 * get_terms() finds nothing, has_purchasable_product stays false, the
 * homepage falls through to the SportsPress signals and renders a
 * valid-looking wrong hero -- no error, no fallback message.
 * test_configured_but_nonexistent_term_falls_back_to_a_real_season_term()
 * below is the one that actually proves the fix closes that gap: a dead
 * configured ID finds nothing, but the resolved fallback finds the real
 * season term.
 */
final class SettingsCommerceTest extends TestCase {

	/**
	 * Reset every in-memory store, including the fake term/post registries,
	 * before each test so one test's registered terms and saved settings
	 * cannot leak into the next.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A configured term that actually exists in product_cat must resolve to
	 * itself, not the fallback.
	 */
	public function test_configured_term_resolves(): void {
		blueline_test_register_term( 200, 'product_cat', 'Merchandise' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'registration_term' => 200 ) );

		$this->assertSame( 200, blueline_resolve_registration_term() );
	}

	/**
	 * An unset field (the schema default, `0`) must resolve to the
	 * documented fallback (91) -- provided 91 itself actually exists.
	 */
	public function test_unset_field_falls_back_to_91(): void {
		blueline_test_register_term( 91, 'product_cat', 'Registration' );

		$this->assertSame( 91, blueline_resolve_registration_term() );
	}

	/**
	 * The load-bearing case: a configured term ID that no longer exists
	 * (deleted, or never registered -- e.g. the volunteer recreated the
	 * category, which assigns a NEW term_id and orphans the old one) must
	 * fall back to 91, not return the dead ID. Also proves the resolved
	 * fallback is not a token gesture: it actually finds the real season
	 * term through get_terms(), where the dead configured ID finds nothing
	 * -- i.e. the resolver genuinely prevents "silently producing an empty
	 * product set", not just "returns a different number".
	 */
	public function test_configured_but_nonexistent_term_falls_back_to_a_real_season_term(): void {
		blueline_test_register_term( 91, 'product_cat', 'Registration' );
		blueline_test_register_term( 205, 'product_cat', 'Winter 2026-27', 91 );

		// 999 was never registered -- stands in for a deleted/recreated
		// category whose old ID no longer resolves to anything.
		update_option( BLUELINE_SETTINGS_OPTION, array( 'registration_term' => 999 ) );

		$resolved = blueline_resolve_registration_term();
		$this->assertSame( 91, $resolved, 'a dead configured term must fall back to the verified fallback, not be trusted' );

		// Sanity check the trap: querying with the raw, dead configured ID
		// really does find nothing.
		$with_dead_id = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => 999,
				'hide_empty' => false,
			)
		);
		$this->assertSame( array(), $with_dead_id, 'sanity check: the dead configured ID alone must find nothing' );

		// And the resolved value actually finds the real season term.
		$season_terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'parent'     => $resolved,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
				'number'     => 1,
				'hide_empty' => false,
			)
		);
		$this->assertNotEmpty( $season_terms, 'the resolved fallback must find the real season term, not silently produce an empty set' );
		$this->assertSame( 205, $season_terms[0]->term_id );
	}

	/**
	 * The fallback itself being invalid (91 was never confirmed by numeric
	 * ID -- see this file's class docblock) must degrade safely: no fatal,
	 * no dangerous value, just `0` -- the sentinel every call site treats as
	 * "nothing to query" rather than forwarding into a `parent` taxonomy
	 * filter, where `0` would mean something else entirely (see
	 * blueline_resolve_registration_term()'s own docblock).
	 */
	public function test_fallback_itself_invalid_degrades_to_the_give_up_sentinel(): void {
		// product_cat exists as a taxonomy (via this unrelated term), but
		// neither the configured value (unset, so 0) nor 91 is registered
		// in it.
		blueline_test_register_term( 200, 'product_cat', 'Merchandise' );

		$this->assertSame( 0, blueline_resolve_registration_term() );
	}

	/**
	 * If product_cat is not even a registered taxonomy at all (WooCommerce
	 * missing/inactive), the resolver must still degrade to `0`, never
	 * fatal.
	 */
	public function test_missing_taxonomy_degrades_to_the_give_up_sentinel(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'registration_term' => 91 ) );

		$this->assertSame( 0, blueline_resolve_registration_term() );
	}

	/**
	 * Wiring proof #1: blueline_registration_season_product_ids()
	 * (inc/season-state.php) must not fatal or misbehave when the resolver
	 * gives up -- it must return an empty array, the same fail-closed shape
	 * every existing caller already tolerates, rather than ever reaching
	 * get_terms() with a bare `0` `parent` argument (which would silently
	 * widen the query to every top-level product category).
	 */
	public function test_season_product_ids_degrades_safely_when_registration_term_is_unresolvable(): void {
		blueline_test_register_term( 200, 'product_cat', 'Merchandise' );

		$this->assertSame( array(), blueline_registration_season_product_ids() );
	}

	/**
	 * Wiring proof #2: blueline_get_user_registration_status()
	 * (inc/account/player-data.php) must return null, not fatal, under the
	 * identical unresolvable-term condition -- proving the defined()
	 * BLUELINE_REGISTRATION_TERM_ID guard this task was told to leave
	 * working (see inc/season-state.php) and the newer resolver-based guard
	 * compose correctly rather than one masking a bug in the other.
	 */
	public function test_user_registration_status_degrades_safely_when_registration_term_is_unresolvable(): void {
		blueline_test_register_term( 200, 'product_cat', 'Merchandise' );

		$this->assertNull( blueline_get_user_registration_status( 7 ) );
	}

	/**
	 * Wiring proof #3: blueline_homepage_registration_season_label()
	 * (inc/homepage-modules.php) must return the empty string, not fatal or
	 * mislabel an unrelated top-level category as the season, when the
	 * resolver gives up.
	 */
	public function test_homepage_season_label_degrades_safely_when_registration_term_is_unresolvable(): void {
		blueline_test_register_term( 200, 'product_cat', 'Merchandise' );
		blueline_test_set_post_terms( 55, 'product_cat', array( 200 ) );

		$this->assertSame( '', blueline_homepage_registration_season_label( 55 ) );
	}
}
