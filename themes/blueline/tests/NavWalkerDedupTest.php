<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Covers the Task 7 regression this suite had ZERO durable coverage for
 * (Task 16 item d): Blueline_Nav_Walker must hide a duplicate "Register to
 * Play" item ONLY when it is genuinely the Register CTA showing -- reusing
 * the same de-dup path for whatever CTA happens to be visible silently
 * deleted the site's permanent "Schedule" nav link in 4 of 5 season states.
 * See the $register_duplicate_path docblock in inc/template-tags.php for
 * the full incident.
 */
final class NavWalkerDedupTest extends TestCase {

	/**
	 * Builds a fake top-level, childless nav menu item as
	 * Walker_Nav_Menu::start_el() would receive it.
	 *
	 * @param string $url   Resolved item URL.
	 * @param string $title Item title.
	 * @return object
	 */
	private function menu_item( string $url, string $title = 'Item' ) {
		return (object) array(
			'ID'         => 501,
			'title'      => $title,
			'url'        => $url,
			'classes'    => array(),
			'attr_title' => '',
			'target'     => '',
			'xfn'        => '',
		);
	}

	/**
	 * Renders one item through the walker and returns the emitted HTML.
	 *
	 * @param Blueline_Nav_Walker $walker    Walker under test.
	 * @param object              $item      Fake menu item.
	 * @param int                 $depth     Depth to render at.
	 * @param bool                $has_child Whether args->has_children is true.
	 * @return string
	 */
	private function render( Blueline_Nav_Walker $walker, $item, int $depth = 0, bool $has_child = false ): string {
		$output = '';
		$args   = (object) array( 'has_children' => $has_child );
		$walker->start_el( $output, $item, $depth, $args );
		$walker->end_el( $output, $item, $depth, $args );
		return $output;
	}

	/**
	 * A depth-0, childless item whose URL matches the CTA's Register URL
	 * must be hidden -- this is the entire point of the mechanism.
	 */
	public function test_item_matching_the_register_cta_url_is_hidden(): void {
		$walker = new Blueline_Nav_Walker( 'https://example.test/register' );
		$html   = $this->render( $walker, $this->menu_item( 'https://example.test/register', 'Register to Play' ) );

		$this->assertSame( '', $html, 'the duplicate Register item must render nothing at all, not even an empty <li>' );
	}

	/**
	 * The exact regression: a permanent "Schedule" item sharing no URL with
	 * the Register CTA must NEVER be hidden, regardless of which CTA the
	 * header happens to be showing right now.
	 */
	public function test_schedule_item_is_never_hidden_by_the_register_dedup(): void {
		$walker = new Blueline_Nav_Walker( 'https://example.test/register' );
		$html   = $this->render( $walker, $this->menu_item( 'https://example.test/schedule', 'Schedule' ) );

		$this->assertStringContainsString( '<li', $html );
		$this->assertStringContainsString( 'Schedule', $html );
	}

	/**
	 * When the header CTA is NOT Register (e.g. it is showing "Schedule"),
	 * the walker is constructed with an empty de-dup path -- confirmed here
	 * by simulating exactly that construction and proving the menu's own
	 * real "Schedule" item survives. This is the specific bug: reusing the
	 * de-dup parameter for a non-Register CTA URL used to delete this item.
	 */
	public function test_empty_dedup_path_hides_nothing(): void {
		$walker = new Blueline_Nav_Walker( '' );

		$schedule = $this->render( $walker, $this->menu_item( 'https://example.test/schedule', 'Schedule' ) );
		$register = $this->render( $walker, $this->menu_item( 'https://example.test/register', 'Register to Play' ) );

		$this->assertStringContainsString( 'Schedule', $schedule );
		$this->assertStringContainsString( 'Register to Play', $register );
	}

	/**
	 * A PARENT item (has children) sharing the Register CTA's URL must
	 * still render -- the de-dup only ever hides simple, childless items,
	 * since hiding a parent would also destroy its submenu.
	 */
	public function test_parent_item_matching_register_url_is_not_hidden(): void {
		$walker = new Blueline_Nav_Walker( 'https://example.test/register' );
		$html   = $this->render( $walker, $this->menu_item( 'https://example.test/register', 'Register to Play' ), 0, true );

		$this->assertStringContainsString( '<li', $html );
	}

	/**
	 * The de-dup only ever applies at depth 0 -- a submenu item that
	 * happens to share the Register URL (unlikely in practice, but not
	 * ruled out by menu structure) must still render.
	 */
	public function test_submenu_item_matching_register_url_is_not_hidden(): void {
		$walker = new Blueline_Nav_Walker( 'https://example.test/register' );
		$html   = $this->render( $walker, $this->menu_item( 'https://example.test/register', 'Register to Play' ), 1 );

		$this->assertStringContainsString( '<li', $html );
	}

	/**
	 * URL comparison is by normalized path, not raw string -- trailing
	 * slash and scheme/case differences must not defeat the de-dup.
	 */
	public function test_dedup_matches_regardless_of_trailing_slash_or_case(): void {
		$walker = new Blueline_Nav_Walker( 'https://example.test/Register/' );
		$html   = $this->render( $walker, $this->menu_item( 'https://example.test/register', 'Register to Play' ) );

		$this->assertSame( '', $html );
	}
}
