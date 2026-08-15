<?php
/**
 * Fix round 1 (Task 8): proves the four fields this round wired --
 * `footer_heading`, `footer_location`, `contact_email`, `hero_offseason_cta`
 * -- actually change what renders on the public site when an admin changes
 * them. Before this fix, all four were schema fields an admin could edit and
 * save ("Settings saved.") with no effect whatsoever: inc/template-tags.php
 * and inc/homepage-modules.php still read the original hardcoded literal.
 * SchemaFieldCoverageTest guards against a FUTURE field shipping in that
 * same shape; this file proves these four specific fields do not remain in
 * it.
 *
 * Each test writes through the real Settings API pipeline
 * (update_option( BLUELINE_SETTINGS_OPTION, ... ), which dispatches the real
 * sanitize_option_blueline_settings filter -- wired unconditionally at file
 * scope in inc/settings/page.php -- exactly the path a real save takes),
 * not by poking blueline_settings_defaults() or the option store directly.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/sanitize.php';
require_once __DIR__ . '/../inc/settings/links.php';
require_once __DIR__ . '/../inc/settings/page.php';
require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/homepage-modules.php';

/**
 * Covers the render side of the four newly-wired fields, plus the
 * `contact_email` `mailto:` href safety the coordinator asked for
 * explicitly.
 */
final class FooterAndHeroSettingsRenderTest extends TestCase {

	/**
	 * Reset every stateful stub this file's tests touch before each test.
	 */
	protected function setUp(): void {
		blueline_test_reset();
	}

	/**
	 * Render blueline_site_footer() and capture its output.
	 *
	 * @return string
	 */
	private function render_footer(): string {
		ob_start();
		blueline_site_footer();
		return (string) ob_get_clean();
	}

	/**
	 * Changing `footer_heading` through a real save changes the rendered
	 * `<h2 class="widget-title">` -- not merely the stored option value.
	 */
	public function test_changing_footer_heading_changes_the_rendered_heading(): void {
		$default_html = $this->render_footer();
		$this->assertStringContainsString( '<h2 class="widget-title">The League</h2>', $default_html );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_heading' => 'Rookie Hockey Burlington' ) );

		$changed_html = $this->render_footer();
		$this->assertStringContainsString( '<h2 class="widget-title">Rookie Hockey Burlington</h2>', $changed_html );
		$this->assertStringNotContainsString( 'The League', $changed_html );
	}

	/**
	 * Changing `footer_location` through a real save changes the rendered
	 * `<p class="bl-footer__location">`.
	 */
	public function test_changing_footer_location_changes_the_rendered_location(): void {
		$default_html = $this->render_footer();
		$this->assertStringContainsString( '<p class="bl-footer__location">Burlington, Ontario</p>', $default_html );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'footer_location' => 'Oakville, Ontario' ) );

		$changed_html = $this->render_footer();
		$this->assertStringContainsString( '<p class="bl-footer__location">Oakville, Ontario</p>', $changed_html );
		$this->assertStringNotContainsString( 'Burlington, Ontario', $changed_html );
	}

	/**
	 * Changing `contact_email` through a real save changes BOTH the
	 * `mailto:` href and the visible link text -- the field is echoed twice
	 * at this one call site, and both must track a saved change.
	 */
	public function test_changing_contact_email_changes_both_the_href_and_the_visible_text(): void {
		$default_html = $this->render_footer();
		$this->assertStringContainsString( 'href="mailto:play@rookiehockey.ca"', $default_html );
		$this->assertStringContainsString( '>play@rookiehockey.ca<', $default_html );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'league@rookiehockey.ca' ) );

		$changed_html = $this->render_footer();
		$this->assertStringContainsString( 'href="mailto:league@rookiehockey.ca"', $changed_html );
		$this->assertStringContainsString( '>league@rookiehockey.ca<', $changed_html );
		$this->assertStringNotContainsString( 'play@rookiehockey.ca', $changed_html );
	}

	/**
	 * Changing `hero_offseason_cta` through a real save changes the
	 * off-season hero's rendered CTA label.
	 */
	public function test_changing_hero_offseason_cta_changes_the_rendered_cta_label(): void {
		$default_content = blueline_homepage_hero_offseason_content();
		$this->assertSame( 'Join the mailing list', $default_content['cta_label'] );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'hero_offseason_cta' => 'Get season updates' ) );

		$changed_content = blueline_homepage_hero_offseason_content();
		$this->assertSame( 'Get season updates', $changed_content['cta_label'] );
	}

	/**
	 * The concrete reason `contact_email` is now validated with is_email()
	 * (inc/settings/sanitize.php): a value shaped like an href-injection
	 * attempt (a space and a double quote -- the exact characters needed to
	 * break out of `href="..."`) is not a real email address, so it is
	 * REJECTED by the real save pipeline -- update_option() through the
	 * live sanitize_option_blueline_settings filter, not a hand call to the
	 * sanitizer -- and the previously-stored safe value survives untouched.
	 * The hostile string never reaches blueline_site_footer() at all.
	 */
	public function test_a_hostile_contact_email_value_is_rejected_and_never_reaches_the_footer(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'league@rookiehockey.ca' ) );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'contact_email' => 'foo@bar.com" onclick="alert(1)' ) );

		$this->assertSame( 'league@rookiehockey.ca', blueline_settings( 'contact_email' ), 'the rejected value must not overwrite the last-good one' );

		$html = $this->render_footer();
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringContainsString( 'href="mailto:league@rookiehockey.ca"', $html );
	}

	/**
	 * Defence in depth, independent of the sanitizer: even if a hostile
	 * value somehow reached storage already (pre-existing data from before
	 * this fix, a direct database edit -- anything that bypasses
	 * update_option()'s sanitize_option_ filter entirely), esc_url() at the
	 * render site still neutralises it. Writes straight to the in-memory
	 * option store, bypassing update_option() and therefore the sanitizer
	 * on purpose, to simulate exactly that case.
	 */
	public function test_the_mailto_href_stays_attribute_safe_even_if_the_sanitizer_were_bypassed(): void {
		$hostile = array_merge(
			blueline_settings_defaults(),
			array( 'contact_email' => 'foo@bar.com" onclick="alert(1)' )
		);
		$GLOBALS['bl_test_options'][ BLUELINE_SETTINGS_OPTION ] = $hostile;

		$html = $this->render_footer();

		// The actual mechanism an href-injection needs is a NEW, unescaped
		// `"` inside the attribute value -- that closes href="" early and
		// turns the rest of the hostile string into a second, attacker-
		// controlled attribute on the same <a> tag (e.g. onclick="..."). Not
		// "the word onclick appears somewhere" (harmless, inert text inside
		// an href is not a vulnerability) but "the tag still has exactly the
		// two quotes href="" itself opens and closes with, and no more".
		$this->assertMatchesRegularExpression( '/<a href="mailto:[^>]*">/', $html, 'expected a well-formed <a href="mailto:...">, not one broken open by the hostile value' );
		preg_match( '/<a href="mailto:[^>]*">/', $html, $match );
		$this->assertSame(
			2,
			substr_count( $match[0], '"' ),
			'the hostile value must not introduce a new quote that closes href="" early and opens a second, attacker-controlled attribute'
		);
	}
}
