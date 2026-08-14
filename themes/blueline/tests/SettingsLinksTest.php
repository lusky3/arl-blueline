<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/links.php';

/**
 * Covers blueline_resolve_link(): the Links tab's hardcoded-path-to-page-ID
 * resolver.
 *
 * WordPress' get_permalink() does NOT check post_status. Passed a TRASHED
 * page's ID it still returns a plausible, well-formed, non-`false` URL --
 * one that 404s for a visitor, because the page it points at is no longer
 * publicly visible. The naive implementation ("if get_permalink() isn't
 * false, use it") would treat that URL as good and reintroduce, through the panel
 * itself, exactly the bug class this panel exists to prevent -- the theme
 * already shipped one live 404 this way (`/contact-us`, fixed in af381c3;
 * see ContactUrlTest). test_trashed_page_falls_back_to_the_configured_path()
 * below is the one that actually proves the fix: it shows get_permalink()
 * returning a plausible URL for the trashed fixture, and the resolver
 * nonetheless refusing it.
 */
final class SettingsLinksTest extends TestCase {

	/**
	 * Reset every in-memory store, including the fake post registry, before
	 * each test so one test's registered posts and saved settings cannot
	 * leak into the next.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * An unset field (the schema default, `0`) must resolve to the fallback
	 * path -- there is no page ID to even attempt a lookup for.
	 */
	public function test_unset_field_resolves_to_the_fallback_path(): void {
		$this->assertSame(
			home_url( '/schedule' ),
			blueline_resolve_link( 'page_schedule' )
		);
	}

	/**
	 * A configured page that is actually published must resolve to its real
	 * permalink, not the fallback path.
	 */
	public function test_published_page_resolves_to_its_permalink(): void {
		blueline_test_register_post( 42, 'publish', 'https://example.test/schedule-2026/' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 42 ) );

		$this->assertSame(
			'https://example.test/schedule-2026/',
			blueline_resolve_link( 'page_schedule' )
		);
	}

	/**
	 * The load-bearing case. get_post_status() for a page registered as
	 * 'trash' is not 'publish', so the resolver must fall back to the
	 * configured path -- it must NOT return get_permalink()'s plausible
	 * value, which is asserted directly below to prove this isn't a
	 * vacuous pass: the permalink genuinely exists and genuinely differs
	 * from the fallback, and the resolver must still refuse it.
	 */
	public function test_trashed_page_falls_back_to_the_configured_path(): void {
		blueline_test_register_post( 43, 'trash', 'https://example.test/schedule-2026/' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 43 ) );

		// Prove the trap: get_permalink() alone returns a plausible,
		// non-false URL for the trashed page -- this is the exact value a
		// naive "if get_permalink() isn't false" implementation would ship.
		$this->assertNotFalse( get_permalink( 43 ) );
		$this->assertSame( 'https://example.test/schedule-2026/', get_permalink( 43 ) );

		// And yet the resolver must not use it.
		$resolved = blueline_resolve_link( 'page_schedule' );
		$this->assertSame( home_url( '/schedule' ), $resolved );
		$this->assertNotSame(
			get_permalink( 43 ),
			$resolved,
			'a trashed page 404s for visitors -- the resolver must never return its plausible-but-dead permalink'
		);
	}

	/**
	 * A configured page ID that no longer exists at all (deleted, never
	 * registered) must fall back -- get_post_status() returns `false` for
	 * it, which is not `'publish'`.
	 */
	public function test_deleted_page_falls_back_to_the_configured_path(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 999 ) );

		// Confirm the fixture really is "gone" from the fake registry's
		// point of view before asserting on the resolver.
		$this->assertFalse( get_post_status( 999 ) );

		$this->assertSame(
			home_url( '/schedule' ),
			blueline_resolve_link( 'page_schedule' )
		);
	}

	/**
	 * A draft page -- saved but never published -- must fall back too.
	 * Draft is exactly the kind of non-publish status get_post_status()
	 * can return that a naive `false !== get_permalink()` check would miss
	 * entirely, since drafts also get a plausible permalink.
	 */
	public function test_draft_page_falls_back_to_the_configured_path(): void {
		blueline_test_register_post( 44, 'draft', 'https://example.test/draft-page/' );
		update_option( BLUELINE_SETTINGS_OPTION, array( 'page_schedule' => 44 ) );

		$this->assertSame(
			home_url( '/schedule' ),
			blueline_resolve_link( 'page_schedule' )
		);
	}
}
