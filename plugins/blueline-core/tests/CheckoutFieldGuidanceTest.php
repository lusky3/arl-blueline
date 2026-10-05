<?php
/**
 * Unit tests for the checkout field guidance: moving the "Requested Team"/"Requested Partner"
 * placeholder caveats into a persistent description, giving "Preferred Division" its missing
 * example text, and the autofill tokens. See includes/checkout/checkout.php.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/checkout/checkout.php';

/**
 * Covers includes/checkout/checkout.php. blueline_wc_checkout_field_guidance() is an array-in,
 * array-out function, so tests build the minimal $fields shape instead of a real checkout.
 */
final class CheckoutFieldGuidanceTest extends TestCase {

	/**
	 * A "Preferred Division" field (WCFE section "player_profile") with neither a placeholder nor
	 * a description gets both.
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
	 * The long "Remember: this is only a *request* and not a *guarantee*" placeholder is moved into
	 * the description, and the placeholder itself is shortened.
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
		// The field's own description survives; the caveat is appended.
		$this->assertStringContainsString( 'placed on a specific team', $result['description'] );
	}

	/**
	 * All three "Requested Partner"/"Additional Partner" fields (arl_request, arl_request2,
	 * arl_request3) share the same truncating placeholder and are all fixed.
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

		// Each field's own description survives.
		$this->assertStringContainsString( 'joining with a partner', $result['arl_request']['description'] );
		$this->assertStringContainsString( 'Anyone else?', $result['arl_request2']['description'] );
	}

	/**
	 * The same keys (arl_team, arl_request) reused by a separate "waitlist" section with short,
	 * already-fine placeholders ("Team Name", "Person's Name") are left untouched.
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
	 * An unknown field, and a $fields array with no 'player_profile' section, are tolerated (this
	 * filter runs on every checkout, including ones with none of these custom fields).
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

		// billing_first_name matches no guidance copy, so placeholder and description stay untouched,
		// but it is a WooCommerce default field and so gains an autocomplete token.
		$expected = $fields;
		$expected['billing']['billing_first_name']['autocomplete'] = 'given-name';

		$this->assertSame( $expected, blueline_wc_checkout_field_guidance( $fields ) );
	}

	/**
	 * The function is hooked on `woocommerce_checkout_fields` at priority 1100, after Checkout
	 * Field Editor Pro's own callback (1000), and rewrites the fields when the filter runs.
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

		$this->assertSame( 1100, has_filter( 'woocommerce_checkout_fields', 'blueline_wc_checkout_field_guidance' ) );
		$this->assertSame( 'Select skill level(s)', $result['player_profile']['arl_division']['placeholder'] );
	}

	/**
	 * The `woocommerce_form_field_args` filter, the one that reaches the rendered HTML (WCFE custom
	 * sections go through woocommerce_form_field() directly), fills the placeholder/description.
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
	 * The per-field function both filters delegate to matches by key and existing placeholder content.
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

		// The waitlist section's arl_team placeholder never contains "Remember": left alone.
		$untouched = blueline_wc_checkout_field_guidance_for_key(
			'arl_team',
			array(
				'placeholder' => 'Team Name',
				'description' => '',
			)
		);
		$this->assertSame( 'Team Name', $untouched['placeholder'] );
	}

	/**
	 * The standard autofill-token map (see blueline_wc_checkout_field_autocomplete()).
	 */
	public function test_autocomplete_map_covers_known_billing_and_shipping_keys(): void {
		$this->assertSame( 'given-name', blueline_wc_checkout_field_autocomplete( 'billing_first_name' ) );
		$this->assertSame( 'family-name', blueline_wc_checkout_field_autocomplete( 'shipping_last_name' ) );
		$this->assertSame( 'email', blueline_wc_checkout_field_autocomplete( 'billing_email' ) );
		$this->assertSame( 'tel', blueline_wc_checkout_field_autocomplete( 'billing_phone' ) );
		$this->assertSame( 'address-line1', blueline_wc_checkout_field_autocomplete( 'billing_address_1' ) );
		$this->assertSame( 'postal-code', blueline_wc_checkout_field_autocomplete( 'shipping_postcode' ) );
	}

	/**
	 * A key with no established autofill token (the arl_* custom fields, anything unmapped) gets
	 * null, and the per-key helper leaves $args['autocomplete'] unset for it.
	 */
	public function test_autocomplete_map_returns_null_for_custom_fields(): void {
		$this->assertNull( blueline_wc_checkout_field_autocomplete( 'arl_division' ) );
		$this->assertNull( blueline_wc_checkout_field_autocomplete( 'arl_emergency_contact' ) );

		$result = blueline_wc_checkout_field_guidance_for_key(
			'arl_emergency_contact',
			array( 'placeholder' => 'Jane Doe' )
		);
		$this->assertArrayNotHasKey( 'autocomplete', $result );
	}

	/**
	 * The per-key helper sets the mapped token in $args for a known key, which WCFE's renderer
	 * reads before falling back to "off".
	 */
	public function test_per_key_helper_sets_autocomplete_for_known_billing_key(): void {
		$result = blueline_wc_checkout_field_guidance_for_key(
			'billing_email',
			array( 'placeholder' => '' )
		);
		$this->assertSame( 'email', $result['autocomplete'] );
	}

	/**
	 * Capture the clean hook baseline (the first reset call records it) before any test adds
	 * a filter of its own.
	 */
	protected function setUp(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * Reset hooks so a filter added by one test cannot leak into the next.
	 */
	protected function tearDown(): void {
		blueline_test_reset_hooks();
	}

	/**
	 * An admin-edited arl_division placeholder and description (Checkout Field Editor Pro) are
	 * not overwritten, matching the sibling branches' content guard.
	 */
	public function test_division_field_keeps_admin_edited_placeholder_and_description(): void {
		$result = blueline_wc_checkout_field_guidance_for_key(
			'arl_division',
			array(
				'placeholder' => 'Pick your level',
				'description' => 'Custom admin help text.',
			)
		);

		$this->assertSame( 'Pick your level', $result['placeholder'] );
		$this->assertSame( 'Custom admin help text.', $result['description'] );
	}

	/**
	 * The placeholder and description are guarded independently: an edited description survives
	 * while an empty placeholder is still filled in.
	 */
	public function test_division_field_guards_placeholder_and_description_independently(): void {
		$result = blueline_wc_checkout_field_guidance_for_key(
			'arl_division',
			array(
				'placeholder' => '',
				'description' => 'Custom admin help text.',
			)
		);

		$this->assertSame( 'Select skill level(s)', $result['placeholder'] );
		$this->assertSame( 'Custom admin help text.', $result['description'] );
	}

	/**
	 * Running the guidance twice (both filters run on a real checkout) is idempotent: the
	 * second pass sees the default copy and rewrites it to itself.
	 */
	public function test_division_guidance_is_idempotent(): void {
		$once  = blueline_wc_checkout_field_guidance_for_key(
			'arl_division',
			array(
				'placeholder' => '',
				'description' => '',
			)
		);
		$twice = blueline_wc_checkout_field_guidance_for_key( 'arl_division', $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * The `blueline_core_checkout_field_guidance` filter can add a field and change copy.
	 */
	public function test_guidance_map_is_filterable(): void {
		add_filter(
			'blueline_core_checkout_field_guidance',
			static function ( array $map ): array {
				$map['my_field']                    = array(
					'mode'        => 'set',
					'placeholder' => 'Custom placeholder',
					'description' => 'Custom description',
				);
				$map['arl_division']['placeholder'] = 'Level(s)';
				return $map;
			}
		);

		$custom = blueline_wc_checkout_field_guidance_for_key( 'my_field', array() );
		$this->assertSame( 'Custom placeholder', $custom['placeholder'] );
		$this->assertSame( 'Custom description', $custom['description'] );

		$division = blueline_wc_checkout_field_guidance_for_key( 'arl_division', array( 'placeholder' => '' ) );
		$this->assertSame( 'Level(s)', $division['placeholder'] );
	}

	/**
	 * A filter can remove a field from the map, and malformed results or entries are ignored
	 * rather than fataling.
	 */
	public function test_guidance_map_filter_tolerates_removed_and_malformed_entries(): void {
		add_filter(
			'blueline_core_checkout_field_guidance',
			static function ( array $map ): array {
				unset( $map['arl_division'] );
				$map['arl_team'] = 'not an array';
				$map['broken']   = array( 'mode' => 'set' );
				return $map;
			}
		);

		$args = array(
			'placeholder' => '',
			'description' => '',
		);

		$this->assertSame( $args, blueline_wc_checkout_field_guidance_for_key( 'arl_division', $args ) );
		$this->assertSame( $args, blueline_wc_checkout_field_guidance_for_key( 'arl_team', $args ) );
		$this->assertSame( $args, blueline_wc_checkout_field_guidance_for_key( 'broken', $args ) );

		blueline_test_reset_hooks();
		add_filter( 'blueline_core_checkout_field_guidance', static fn() => 'nope' );

		$division = blueline_wc_checkout_field_guidance_for_key( 'arl_division', $args );
		$this->assertSame( 'Select skill level(s)', $division['placeholder'] );
	}

	/**
	 * A realistic WooCommerce checkbox field, as woocommerce_form_field() renders it.
	 *
	 * @param string $input_attrs Attributes of the checkbox <input> (after type="checkbox").
	 * @return string
	 */
	private function checkbox_html( string $input_attrs ): string {
		return '<p class="form-row validate-required" id="arl_waiver_field" data-priority="">'
			. '<span class="woocommerce-input-wrapper"><label class="checkbox ">'
			. '<input type="checkbox" ' . $input_attrs . ' value="1" /> I agree&nbsp;'
			. '<abbr class="required" title="required">*</abbr></label></span></p>';
	}

	/**
	 * A required checkbox's <input> gains `required aria-required="true"` right after its name.
	 */
	public function test_required_checkbox_gains_required_and_aria_required(): void {
		$field  = $this->checkbox_html( 'class="input-checkbox " name="arl_waiver" id="arl_waiver"' );
		$result = blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array( 'required' => true ) );

		$this->assertStringContainsString( 'name="arl_waiver" required aria-required="true" id="arl_waiver"', $result );
		$this->assertSame( 1, substr_count( $result, 'aria-required="true"' ) );
	}

	/**
	 * A field that is not required is returned untouched.
	 */
	public function test_non_required_checkbox_is_untouched(): void {
		$field = $this->checkbox_html( 'class="input-checkbox " name="arl_waiver" id="arl_waiver"' );

		$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array() ) );
		$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array( 'required' => false ) ) );
	}

	/**
	 * An <input> that already carries the real attribute (bare or valued) is not patched again.
	 */
	public function test_checkbox_with_required_attribute_is_untouched(): void {
		$cases = array(
			'name="arl_waiver" required',
			'required="required" name="arl_waiver"',
			'name="arl_waiver" required aria-required="true"',
		);

		foreach ( $cases as $attrs ) {
			$field = $this->checkbox_html( $attrs );

			$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array( 'required' => true ) ), $attrs );
		}
	}

	/**
	 * An input with a similar name (a longer key, or a data-name attribute) is not patched, and
	 * a key not present at all is a no-op.
	 */
	public function test_other_inputs_with_similar_names_are_untouched(): void {
		$field = $this->checkbox_html( 'name="arl_waiver_2" id="arl_waiver_2"' );
		$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array( 'required' => true ) ) );

		$field = $this->checkbox_html( 'data-name="arl_waiver" name="other"' );
		$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'arl_waiver', array( 'required' => true ) ) );

		$field = $this->checkbox_html( 'name="arl_waiver" id="arl_waiver"' );
		$this->assertSame( $field, blueline_wc_required_checkbox_attributes( $field, 'missing_key', array( 'required' => true ) ) );
	}

	/**
	 * `validate-required` on the input's class, a `data-required` attribute and a value merely
	 * containing the word do NOT count as the real attribute: the input still gets patched.
	 */
	public function test_validate_required_like_tokens_do_not_block_the_patch(): void {
		$cases = array(
			'class="input-checkbox validate-required" name="arl_waiver"',
			'data-required="1" name="arl_waiver"',
			'name="arl_waiver" data-validate-required="yes"',
			'name="arl_waiver" title="this is required"',
		);

		foreach ( $cases as $attrs ) {
			$result = blueline_wc_required_checkbox_attributes( $this->checkbox_html( $attrs ), 'arl_waiver', array( 'required' => true ) );

			$this->assertStringContainsString( 'name="arl_waiver" required aria-required="true"', $result, $attrs );
		}
	}

	/**
	 * Only the first matching <input> is patched when the same tag appears twice.
	 */
	public function test_only_the_first_matching_input_is_patched(): void {
		$input  = '<input type="checkbox" name="arl_waiver" value="1" />';
		$result = blueline_wc_required_checkbox_attributes( $input . $input, 'arl_waiver', array( 'required' => true ) );

		$this->assertSame( 1, substr_count( $result, 'aria-required="true"' ) );
		$this->assertStringEndsWith( $input, $result );
	}

	/**
	 * The patch is registered on the checkbox field filter.
	 */
	public function test_required_checkbox_patch_is_registered_on_the_checkbox_field_filter(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_form_field_checkbox', 'blueline_wc_required_checkbox_attributes' ) );
	}
}
