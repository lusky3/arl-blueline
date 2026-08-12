<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * P0 finding 10: PRODUCT.md principle 4 says naming only the arena ("Twin
 * Rinks") doesn't tell a player which pad to walk to -- but the site now has
 * the inverse bug, naming only the pad ("Red") and never the arena at all.
 *
 * This suite covers blueline_venue_arena_name_for_address(), the pure,
 * address-keyed lookup half of blueline_venue_label() -- the half that
 * doesn't need a live WP_Term/get_option() environment to test directly.
 * The full label (arena + get_term()'s pad name) is exercised live via the
 * staging smoke pass instead; this suite covers the part that is pure data
 * lookup.
 */
final class VenueLabelTest extends TestCase {

	/**
	 * The one rename this task confirmed live (post 11113, /register: "Mr.
	 * Lube and Tires Arena (formerly known as the Wave Twin Rinks)") must
	 * resolve for the exact address string stored on the venue terms that
	 * share it -- both the with- and without-postal-code forms actually
	 * seen live (Red/Black store it without a postal code; StoneRidge Red/
	 * Wave Twin Rinks Blue store it with one -- same building, two strings).
	 */
	public function test_known_arena_address_resolves_case_insensitively(): void {
		$this->assertSame(
			'Mr. Lube and Tires Arena',
			blueline_venue_arena_name_for_address( '1179 Northside Rd, Burlington, ON L7M, Canada' )
		);
		$this->assertSame(
			'Mr. Lube and Tires Arena',
			blueline_venue_arena_name_for_address( '1179 NORTHSIDE RD, BURLINGTON, ON L7M, CANADA' )
		);
		$this->assertSame(
			'Mr. Lube and Tires Arena',
			blueline_venue_arena_name_for_address( '1179 Northside Rd, Burlington, ON L7M 1H5, Canada' )
		);
	}

	/**
	 * Any address not in the known map returns '' -- blueline_venue_label()
	 * relies on this to fall back to the venue's own term name rather than
	 * inventing or guessing a name (see that function's own docblock for why
	 * parsing the term description was ruled out).
	 */
	public function test_unknown_address_returns_empty_string(): void {
		$this->assertSame( '', blueline_venue_arena_name_for_address( '1201 Appleby Line, Burlington, ON L7L 5H9, Canada' ) );
		$this->assertSame( '', blueline_venue_arena_name_for_address( '' ) );
		$this->assertSame( '', blueline_venue_arena_name_for_address( '   ' ) );
	}

	/**
	 * Leading/trailing whitespace on the stored address must not defeat the
	 * lookup -- SportsPress's own admin field is free text.
	 */
	public function test_whitespace_padded_address_still_resolves(): void {
		$this->assertSame(
			'Mr. Lube and Tires Arena',
			blueline_venue_arena_name_for_address( "  1179 Northside Rd, Burlington, ON L7M, Canada\n" )
		);
	}
}
