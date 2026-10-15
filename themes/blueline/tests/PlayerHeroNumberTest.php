<?php
/**
 * The player hero's jersey number (inc/sportspress/heroes.php): the big
 * decorative numeral stays hidden from assistive tech, and the number is
 * spoken once, with what it is, after the player's name.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * Unit tests.
 */
final class PlayerHeroNumberTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset_state();
	}

	/**
	 * Render the hero for player 116 with the given number.
	 *
	 * @param string $number sp_number meta.
	 * @return string
	 */
	private function render( string $number ): string {
		$state                                     = &blueline_test_state();
		$state['posts'][116]                       = array(
			'status' => 'publish',
			'title'  => 'Andrew McRorie',
		);
		$state['post_meta'][116]['sp_number']      = $number;
		$state['post_meta'][116]['sp_nationality'] = '';

		ob_start();
		blueline_sp_player_hero( 116 );

		return (string) ob_get_clean();
	}

	/**
	 * The numeral is decoration; the words come after the h1.
	 */
	public function test_the_number_is_spoken_once_with_its_meaning(): void {
		$html = $this->render( '12' );

		$this->assertStringContainsString( '<span class="bl-sp-hero__number" aria-hidden="true">12</span>', $html );
		$this->assertMatchesRegularExpression(
			'#</h1>\s*<p class="bl-sp-hero__meta">\s*<span class="screen-reader-text">Jersey number 12</span>#',
			$html
		);
	}

	/**
	 * No number, nothing to say.
	 */
	public function test_no_number_means_no_jersey_text(): void {
		$this->assertStringNotContainsString( 'Jersey number', $this->render( '' ) );
	}

	/**
	 * The number is escaped.
	 */
	public function test_the_number_is_escaped(): void {
		$this->assertStringContainsString( 'Jersey number 1&lt;b&gt;', $this->render( '1<b>' ) );
	}
}
