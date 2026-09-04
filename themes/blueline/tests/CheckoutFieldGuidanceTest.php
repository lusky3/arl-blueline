<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why (the sniff's error is anchored to the T_OPEN_TAG token on line 1).
/**
 * Unit tests for the live-site UX review's checkout field guidance fixes
 * (findings 1 & 2): moving "Requested Team"/"Requested Partner"'s
 * truncating/disappearing placeholder caveats into a persistent
 * description, and giving "Preferred Division" the example text it never
 * had. See inc/woocommerce.php's own comment above
 * blueline_wc_checkout_field_guidance() for the live-audited field keys and
 * content this suite exercises against.
 *
 * The require below would otherwise no-op in this plain-PHPUnit
 * environment: inc/woocommerce.php's own
 * `if ( ! class_exists( 'WooCommerce' ) ) { return; }` guard (necessary in
 * production, since this file must no-op on a site without WooCommerce
 * active) needs SOME WooCommerce class to exist first, and no real
 * WooCommerce is ever loaded here -- tests/bootstrap.php declares a shared
 * minimal stub for exactly this, loaded before this file, so
 * `class_exists( 'WooCommerce' )` is already true by the time the require
 * below runs.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/woocommerce.php';

/**
 * This suite exercises blueline_wc_checkout_field_guidance(), a pure
 * array-in, array-out function (see its own docblock in inc/woocommerce.php
 * for why), so every test here builds the minimal $fields shape it
 * reads/writes rather than standing up a real WooCommerce checkout.
 */
final class CheckoutFieldGuidanceTest extends TestCase {

	/**
	 * The exact "Preferred Division" field shape read live off staging
	 * (thwcfe_sections, section "player_profile") gets both a placeholder
	 * and a description where it previously had neither.
	 */
	public function test_division_field_gains_placeholder_and_description(): void {
		$fields = array(
			'player_profile' => array(
				'arl_division' => array(
					'id'          => 'arl_division',
					'name'        => 'arl_division',
					'type'        => 'multiselect',
					'required'    => 'yes',
					'placeholder' => '',
					'description' => '',
				),
			),
		);

		$result = blueline_wc_checkout_field_guidance( $fields );

		$this->assertNotSame( '', $result['player_profile']['arl_division']['placeholder'] );
		$this->assertNotSame( '', $result['player_profile']['arl_division']['description'] );
		$this->assertStringContainsString( 'division', $result['player_profile']['arl_division']['description'] );
	}

	/**
	 * The exact "Requested Team" placeholder read live off staging -- the
	 * long "Remember: this is only a *request* and not a *guarantee*"
	 * caveat -- is moved into the description, and the placeholder itself
	 * is shortened rather than left to keep truncating.
	 */
	public function test_team_field_moves_caveat_from_placeholder_to_description(): void {
		$fields = array(
			'player_profile' => array(
				'arl_team' => array(
					'id'          => 'arl_team',
					'name'        => 'arl_team',
					'type'        => 'text',
					'placeholder' => "Team or Captain's name. Remember: This is only a *request* and not a *guarantee*",
					'description' => 'If you would like to be placed on a specific team.',
				),
			),
		);

		$result = blueline_wc_checkout_field_guidance( $fields )['player_profile']['arl_team'];

		$this->assertStringNotContainsString( 'Remember', $result['placeholder'] );
		$this->assertStringContainsString( 'Remember', $result['description'] );
		// The field's own pre-existing description must survive, not be
		// clobbered by the caveat being appended.
		$this->assertStringContainsString( 'placed on a specific team', $result['description'] );
	}

	/**
	 * All three "Requested Partner"/"Additional Partner" fields
	 * (arl_request, arl_request2, arl_request3) share the identical
	 * truncating placeholder on staging and must all three be fixed, not
	 * just the first.
	 */
	public function test_all_three_partner_fields_move_caveat_from_placeholder_to_description(): void {
		$placeholder = 'Please enter only 1 name... Do not use this field for captains or team names.';

		$fields = array(
			'player_profile' => array(
				'arl_request'  => array(
					'placeholder' => $placeholder,
					'description' => 'Are you joining with a partner (spouse, sibling, friend, etc.)',
				),
				'arl_request2' => array(
					'placeholder' => $placeholder,
					'description' => 'Anyone else?',
				),
				'arl_request3' => array(
					'placeholder' => $placeholder,
					'description' => 'Anyone else?',
				),
			),
		);

		$result = blueline_wc_checkout_field_guidance( $fields )['player_profile'];

		foreach ( array( 'arl_request', 'arl_request2', 'arl_request3' ) as $key ) {
			$this->assertStringNotContainsString( 'Please enter only 1 name', $result[ $key ]['placeholder'], "field {$key}" );
			$this->assertStringContainsString( 'only 1 name', $result[ $key ]['description'], "field {$key}" );
		}

		// Each field's own distinct pre-existing description survives.
		$this->assertStringContainsString( 'joining with a partner', $result['arl_request']['description'] );
		$this->assertStringContainsString( 'Anyone else?', $result['arl_request2']['description'] );
	}

	/**
	 * The exact bug this whole fix targets: a bare content-match guard
	 * means the SAME field keys (arl_team, arl_request), reused by a
	 * different "waitlist" section on staging with their own short,
	 * already-fine placeholders ("Team Name", "Person's Name"), must be
	 * left completely untouched.
	 */
	public function test_other_sections_reusing_the_same_field_keys_are_untouched(): void {
		$fields = array(
			'waitlist' => array(
				'arl_team'    => array(
					'placeholder' => 'Team Name',
					'description' => '',
				),
				'arl_request' => array(
					'placeholder' => "Person's Name",
					'description' => '',
				),
			),
		);

		$result = blueline_wc_checkout_field_guidance( $fields )['waitlist'];

		$this->assertSame( 'Team Name', $result['arl_team']['placeholder'] );
		$this->assertSame( '', $result['arl_team']['description'] );
		$this->assertSame( "Person's Name", $result['arl_request']['placeholder'] );
		$this->assertSame( '', $result['arl_request']['description'] );
	}

	/**
	 * A field this function doesn't know about, and a $fields array with no
	 * 'player_profile' section at all, must both be tolerated rather than
	 * triggering an "undefined array key" notice/fatal -- this filter runs
	 * on every checkout, including ones with none of these custom fields.
	 */
	public function test_missing_fields_and_sections_are_tolerated(): void {
		$this->assertSame( array(), blueline_wc_checkout_field_guidance( array() ) );

		$fields = array(
			'billing' => array(
				'billing_first_name' => array(
					'placeholder' => '',
					'description' => '',
				),
			),
		);

		$this->assertSame( $fields, blueline_wc_checkout_field_guidance( $fields ) );
	}

	/**
	 * End-to-end wiring check: the function must actually be registered on
	 * `woocommerce_checkout_fields` (not merely defined and never hooked up)
	 * at a priority late enough to run after Checkout Field Editor Pro's own
	 * filter (documented as priority 1000 in the comment above the
	 * add_filter() call in inc/woocommerce.php).
	 */
	public function test_function_is_registered_on_the_checkout_fields_filter_after_wcfe(): void {
		$fields = array(
			'player_profile' => array(
				'arl_division' => array(
					'placeholder' => '',
					'description' => '',
				),
			),
		);

		$result = apply_filters( 'woocommerce_checkout_fields', $fields );

		$this->assertNotSame( '', $result['player_profile']['arl_division']['placeholder'] );
	}

	/**
	 * The actual rendering path this whole fix targets (confirmed live: a
	 * `woocommerce_checkout_fields`-only fix deployed to staging changed
	 * nothing on the page). Checkout Field Editor Pro's custom sections
	 * render via WooCommerce core's own woocommerce_form_field(), which
	 * applies `woocommerce_form_field_args` to every field it prints
	 * regardless of where the field's args came from -- so THIS filter, not
	 * `woocommerce_checkout_fields`, is the one that must actually change
	 * the rendered placeholder/description. See
	 * blueline_wc_checkout_form_field_guidance()'s own docblock.
	 */
	public function test_form_field_args_filter_actually_fixes_the_rendered_field(): void {
		$result = apply_filters(
			'woocommerce_form_field_args',
			array(
				'placeholder' => '',
				'description' => '',
			),
			'arl_division',
			null
		);

		$this->assertNotSame( '', $result['placeholder'] );
		$this->assertNotSame( '', $result['description'] );
	}

	/**
	 * This exercises blueline_wc_checkout_field_guidance_for_key(), the pure,
	 * per-field function both filters above delegate to, directly, so
	 * a future refactor of either wrapper can't quietly stop calling it
	 * without a test noticing.
	 */
	public function test_per_key_helper_matches_by_key_and_existing_placeholder_content(): void {
		$division = blueline_wc_checkout_field_guidance_for_key(
			'arl_division',
			array(
				'placeholder' => '',
				'description' => '',
			)
		);
		$this->assertNotSame( '', $division['placeholder'] );

		// Same key as the "waitlist" section's own arl_team, but that
		// field's real, already-fine placeholder never contains "Remember"
		// -- must be left alone.
		$untouched = blueline_wc_checkout_field_guidance_for_key(
			'arl_team',
			array(
				'placeholder' => 'Team Name',
				'description' => '',
			)
		);
		$this->assertSame( 'Team Name', $untouched['placeholder'] );
	}
}
