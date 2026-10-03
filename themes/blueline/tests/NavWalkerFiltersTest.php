<?php
/**
 * Blueline_Nav_Walker applies core's per-item nav filters (audit WP-09)
 * without letting a callback drop the classes the nav's CSS and JS need,
 * and renders byte-identically when nothing is hooked.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';

/**
 * Unit tests.
 */
final class NavWalkerFiltersTest extends TestCase {

	/**
	 * Clear hooks so no callback leaks between tests.
	 */
	protected function setUp(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * Clear hooks this test added.
	 */
	protected function tearDown(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * A fake menu item.
	 *
	 * @param array $over Field overrides.
	 * @return object
	 */
	private function item( array $over = array() ) {
		return (object) array_merge(
			array(
				'ID'         => 8,
				'title'      => 'Rules & FAQ',
				'url'        => 'https://example.test/faq',
				'classes'    => array( 'menu-item', 'current-menu-item' ),
				'attr_title' => 'Read the rules',
				'target'     => '_blank',
				'xfn'        => 'nofollow',
			),
			$over
		);
	}

	/**
	 * Render one item (with an empty submenu for parents).
	 *
	 * @param object $item      Menu item.
	 * @param int    $depth     Depth.
	 * @param bool   $has_child Whether it has children.
	 * @return string
	 */
	private function render( $item, int $depth = 0, bool $has_child = false ): string {
		$walker = new Blueline_Nav_Walker();
		$output = '';
		$args   = (object) array( 'has_children' => $has_child );
		$walker->start_el( $output, $item, $depth, $args );
		if ( $has_child ) {
			$walker->start_lvl( $output, $depth, $args );
			$walker->end_lvl( $output, $depth, $args );
		}
		$walker->end_el( $output, $item, $depth, $args );
		return $output;
	}

	/**
	 * With nothing hooked, output is exactly what the walker emitted before
	 * the filters were added (captured from the pre-change walker).
	 */
	public function test_default_output_is_unchanged(): void {
		$this->assertSame(
			'<li class="menu-item current-menu-item bl-nav__item"><a class="bl-nav__link" href="https://example.test/faq" title="Read the rules" target="_blank" rel="nofollow" aria-current="page"><span class="bl-skew"><span>Rules & FAQ</span></span></a></li>' . "\n",
			$this->render( $this->item() )
		);

		$this->assertSame(
			'<li class="menu-item menu-item-has-children bl-nav__item bl-nav__item--parent"><a class="bl-nav__link" href="#"><span class="bl-skew"><span>League</span></span></a><button type="button" class="bl-nav__toggle-sub" aria-expanded="false" aria-controls="bl-submenu-9"><span class="screen-reader-text">Show submenu for League</span><svg class="bl-nav__chevron" viewBox="0 0 12 8" aria-hidden="true" focusable="false"><path d="M1 1l5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' . "\n\t<ul id=\"bl-submenu-9\" class=\"sub-menu bl-nav__submenu\">\n\t</ul>\n</li>\n",
			$this->render(
				$this->item(
					array(
						'ID'         => 9,
						'title'      => 'League',
						'url'        => '',
						'classes'    => array( 'menu-item', 'menu-item-has-children' ),
						'attr_title' => '',
						'target'     => '',
						'xfn'        => '',
					)
				),
				0,
				true
			)
		);

		$this->assertSame(
			'<li class="bl-nav__item xy bl-nav__item"><a class="bl-nav__link" href="https://example.test/div-a"><span>Div <b>A</b></span></a></li>' . "\n",
			$this->render(
				$this->item(
					array(
						'ID'         => 10,
						'title'      => 'Div <b>A</b>',
						'url'        => 'https://example.test/div-a',
						'classes'    => array( 'bl-nav__item', 'x"y' ),
						'attr_title' => '',
						'target'     => '',
						'xfn'        => '',
					)
				),
				1
			)
		);
	}

	/**
	 * A nav_menu_css_class callback's class lands on the <li>, and it sees
	 * the item, args and depth core passes.
	 */
	public function test_css_class_filter_adds_a_class(): void {
		$seen = array();
		add_filter(
			'nav_menu_css_class',
			static function ( $classes, $item, $args, $depth ) use ( &$seen ) {
				$seen      = array( $item->ID, $args->has_children, $depth );
				$classes[] = 'has-icon';
				return $classes;
			},
			10,
			4
		);

		$this->assertStringContainsString( '<li class="menu-item current-menu-item bl-nav__item has-icon">', $this->render( $this->item(), 1 ) );
		$this->assertSame( array( 8, false, 1 ), $seen );
	}

	/**
	 * A callback that wipes the classes cannot remove the required ones.
	 */
	public function test_required_item_classes_survive_the_css_class_filter(): void {
		add_filter( 'nav_menu_css_class', static fn() => array( 'only-mine' ) );

		$html = $this->render( $this->item( array( 'classes' => array( 'menu-item-has-children' ) ) ), 0, true );

		$this->assertStringContainsString( '<li class="only-mine bl-nav__item bl-nav__item--parent">', $html );
	}

	/**
	 * A nav_menu_link_attributes callback's attribute lands on the <a>, and
	 * an emptied attribute is dropped as core's build_atts() drops it.
	 */
	public function test_link_attributes_filter_adds_and_removes_attributes(): void {
		add_filter(
			'nav_menu_link_attributes',
			static function ( $atts ) {
				$atts['data-track'] = 'nav "faq"';
				$atts['title']      = '';
				return $atts;
			}
		);

		$html = $this->render( $this->item() );

		$this->assertStringContainsString( 'data-track="nav &quot;faq&quot;"', $html );
		$this->assertStringNotContainsString( 'title=', $html );
		$this->assertStringContainsString( '<a class="bl-nav__link" href="https://example.test/faq"', $html );
	}

	/**
	 * Replacing or removing the link class cannot drop bl-nav__link.
	 */
	public function test_required_link_class_survives_the_link_attributes_filter(): void {
		add_filter(
			'nav_menu_link_attributes',
			static function ( $atts ) {
				$atts['class'] = 'button';
				return $atts;
			}
		);
		$this->assertStringContainsString( 'class="button bl-nav__link"', $this->render( $this->item() ) );

		blueline_test_reset_hooks();
		add_filter(
			'nav_menu_link_attributes',
			static function ( $atts ) {
				unset( $atts['class'] );
				return $atts;
			}
		);
		$this->assertMatchesRegularExpression( '#<a [^>]*class="bl-nav__link"#', $this->render( $this->item() ) );
	}

	/**
	 * The walker_nav_menu_start_el filter wraps the link only; the submenu toggle
	 * button still follows it.
	 */
	public function test_start_el_filter_wraps_the_link(): void {
		add_filter( 'walker_nav_menu_start_el', static fn( $html ) => '<i class="icon"></i>' . $html );

		$html = $this->render( $this->item( array( 'classes' => array( 'menu-item-has-children' ) ) ), 0, true );

		$this->assertStringContainsString( '<i class="icon"></i><a class="bl-nav__link"', $html );
		$this->assertStringContainsString( '</a><button type="button" class="bl-nav__toggle-sub"', $html );
	}

	/**
	 * An item the walker hides as a CTA duplicate runs no item filters.
	 */
	public function test_hidden_duplicate_item_runs_no_filters(): void {
		$calls = 0;
		add_filter(
			'nav_menu_css_class',
			static function ( $classes ) use ( &$calls ) {
				++$calls;
				return $classes;
			}
		);

		$walker = new Blueline_Nav_Walker( 'https://example.test/register' );
		$output = '';
		$walker->start_el( $output, $this->item( array( 'url' => 'https://example.test/register' ) ), 0, (object) array() );

		$this->assertSame( '', $output );
		$this->assertSame( 0, $calls );
	}
}
