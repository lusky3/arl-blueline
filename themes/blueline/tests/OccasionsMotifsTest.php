<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';

/**
 * Covers the shipped motif SVG set (design spec §5/§7.7): static,
 * enumerated, decorative motifs aria-hidden, the poppy carrying a real
 * accessible name instead.
 */
final class OccasionsMotifsTest extends TestCase {

	/**
	 * Capture a render function's printed output.
	 *
	 * @param callable $render A no-argument render function.
	 * @return string
	 */
	private function captured( callable $render ): string {
		ob_start();
		$render();
		return (string) ob_get_clean();
	}

	/**
	 * Asserts 'none' and an unrecognised motif name both render nothing.
	 */
	public function test_none_and_unknown_render_nothing(): void {
		$this->assertSame( '', $this->captured( static fn() => blueline_render_occasion_motif( 'none' ) ) );
		$this->assertSame( '', $this->captured( static fn() => blueline_render_occasion_motif( 'fireworks' ) ) );
	}

	/**
	 * Asserts each of the three purely decorative motifs renders an
	 * aria-hidden, non-focusable inline <svg>.
	 */
	public function test_decorative_motifs_are_aria_hidden_and_not_focusable(): void {
		foreach ( array( 'maple-leaf', 'snowflake', 'sparkle' ) as $motif ) {
			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

			$this->assertStringContainsString( '<svg', $markup, "$motif did not render an <svg>" );
			$this->assertStringContainsString( 'aria-hidden="true"', $markup, "$motif is missing aria-hidden" );
			$this->assertStringContainsString( 'focusable="false"', $markup, "$motif is missing focusable=\"false\"" );
			$this->assertStringContainsString( "bl-occasion-motif--$motif", $markup, "$motif is missing its own modifier class" );
		}
	}

	/**
	 * Asserts the poppy is NOT aria-hidden and carries a real accessible
	 * name via a <title> element -- design spec §5/§7.7: "the poppy
	 * carries an accessible name."
	 */
	public function test_poppy_carries_an_accessible_name(): void {
		$markup = $this->captured( 'blueline_render_occasion_motif_poppy' );

		$this->assertStringContainsString( '<svg', $markup );
		$this->assertStringNotContainsString( 'aria-hidden', $markup );
		$this->assertStringContainsString( 'role="img"', $markup );
		$this->assertMatchesRegularExpression( '#<title>[^<]+</title>#', $markup );
	}

	/**
	 * Asserts none of the four real motifs contains any animation --
	 * design spec §5/§7.7: "static only, no animation" -- checked as the
	 * absence of the element/attribute names that would introduce motion.
	 */
	public function test_motifs_are_static_no_animation(): void {
		foreach ( array( 'maple-leaf', 'poppy', 'snowflake', 'sparkle' ) as $motif ) {
			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

			foreach ( array( '<animate', '<animateTransform', '<animateMotion', 'style="animation' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $markup, "$motif must not animate" );
			}
		}
	}

	/**
	 * Asserts the dispatcher's branches match blueline_occasion_motifs()'s
	 * enumeration exactly -- every real motif (excluding 'none') renders
	 * something, and there is no motif in the enum this dispatcher forgot.
	 */
	public function test_dispatcher_covers_every_enumerated_motif(): void {
		foreach ( blueline_occasion_motifs() as $motif ) {
			if ( 'none' === $motif ) {
				continue;
			}

			$markup = $this->captured( static fn() => blueline_render_occasion_motif( $motif ) );

			$this->assertNotSame( '', $markup, "the dispatcher has no branch for '$motif'" );
		}
	}
}
