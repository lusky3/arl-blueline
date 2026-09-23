<?php
/**
 * Which pages get the "on this page" jump-nav rail instead of the plain
 * widget rail is decided purely from the page's own rendered content --
 * how many real <h2>/<h3> headings it has -- never a hardcoded page id or
 * slug list. blueline_extract_and_anchor_headings() is the mechanism: it
 * assigns every heading a stable, unique anchor id and hands back the list
 * blueline_page_needs_jump_nav()/blueline_render_page_jump_nav() read.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/page-sidebar-navigation.php';

/**
 * Guards the pure heading-extraction/anchoring logic behind the jump-nav.
 */
final class PageSidebarNavigationTest extends TestCase {

	/**
	 * A single heading is not enough to justify a jump-nav -- there is
	 * nothing to jump between.
	 */
	public function test_single_heading_produces_one_anchor(): void {
		$result = blueline_extract_and_anchor_headings( '<h2>Only Section</h2><p>Text.</p>' );

		$this->assertCount( 1, $result['anchors'] );
		$this->assertSame( 'Only Section', $result['anchors'][0]['label'] );
	}

	/**
	 * The real case a jump-nav exists for: several distinct sections.
	 */
	public function test_multiple_headings_are_each_anchored_in_order(): void {
		$html = '<h2>First Arena</h2><p>...</p><h2>Second Arena</h2><p>...</p><h3>Parking</h3>';

		$result = blueline_extract_and_anchor_headings( $html );

		$this->assertSame(
			array( 'First Arena', 'Second Arena', 'Parking' ),
			array_column( $result['anchors'], 'label' )
		);
	}

	/**
	 * Two headings with identical text (e.g. two teams both titled
	 * "Schedule") must not collide on the same #id -- the second one's link
	 * would silently jump to the first.
	 */
	public function test_duplicate_heading_text_gets_a_unique_id(): void {
		$html = '<h2>Schedule</h2><h2>Schedule</h2>';

		$result = blueline_extract_and_anchor_headings( $html );

		$ids = array_column( $result['anchors'], 'id' );

		$this->assertCount( 2, array_unique( $ids ) );
	}

	/**
	 * A heading the content already gave its own id (e.g. a manually placed
	 * anchor) keeps that id rather than being overwritten -- an existing
	 * inbound link to it must keep working.
	 */
	public function test_existing_heading_id_is_preserved(): void {
		$html = '<h2 id="registration">Registration</h2>';

		$result = blueline_extract_and_anchor_headings( $html );

		$this->assertSame( 'registration', $result['anchors'][0]['id'] );
		$this->assertStringContainsString( 'id="registration"', $result['html'] );
	}

	/**
	 * An empty heading (rare, but possible from a stray editor block) has
	 * nothing to link to or label a jump-nav entry with, so it is left
	 * alone rather than anchored.
	 */
	public function test_empty_heading_is_not_anchored(): void {
		$result = blueline_extract_and_anchor_headings( '<h2></h2><h2>Real Section</h2>' );

		$this->assertCount( 1, $result['anchors'] );
		$this->assertSame( 'Real Section', $result['anchors'][0]['label'] );
	}

	/**
	 * A heading joining two related questions with `<br><em>or</em><br>`
	 * (confirmed live on /faqs) must not glue them into one run-on string
	 * with no space where the tag was.
	 */
	public function test_br_separated_heading_keeps_a_space_between_parts(): void {
		$html = '<h3>Where can I check my status?<br><em>or</em><br>Where can I pay?</h3>';

		$result = blueline_extract_and_anchor_headings( $html );

		$this->assertSame(
			'Where can I check my status? or Where can I pay?',
			$result['anchors'][0]['label']
		);
	}

	/**
	 * Each anchor records which heading level it came from, so a two-tier
	 * page (FAQs: <h2> topics grouping <h3> questions) can render its topic
	 * headers differently from its individual questions.
	 */
	public function test_anchor_level_matches_the_heading_tag(): void {
		$html = '<h2>Registration &amp; Roster Changes</h2><h3>How do I register?</h3>';

		$result = blueline_extract_and_anchor_headings( $html );

		$this->assertSame( 2, $result['anchors'][0]['level'] );
		$this->assertSame( 3, $result['anchors'][1]['level'] );
	}

	/**
	 * The >= 2 threshold is what sidebar.php branches on to decide between
	 * the jump-nav rail and the plain widget rail.
	 */
	public function test_needs_jump_nav_requires_at_least_two_anchors(): void {
		$one = array(
			'id'    => 'a',
			'label' => 'A',
		);
		$two = array(
			'id'    => 'b',
			'label' => 'B',
		);

		blueline_page_heading_anchors( array() );
		$this->assertFalse( blueline_page_needs_jump_nav() );

		blueline_page_heading_anchors( array( $one ) );
		$this->assertFalse( blueline_page_needs_jump_nav() );

		blueline_page_heading_anchors( array( $one, $two ) );
		$this->assertTrue( blueline_page_needs_jump_nav() );
	}
}
