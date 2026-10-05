<?php
/**
 * Checkout field fixes for Checkout Field Editor Pro: autofill tokens, division/team/partner
 * guidance copy, and the required-checkbox a11y patch.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/*
 * Checkout field guidance for the "WooCommerce Checkout Field Editor Pro" (WCFE) fields
 * arl_division (Preferred Division: a required multiselect with no placeholder or description) and
 * arl_team / arl_request* (Requested Team / Partner: the "request, not a guarantee" caveat sits in
 * a placeholder that truncates and disappears once the field has a value).
 *
 * Each rewrite is guarded by the field's EXISTING content, not its key alone: the same keys are
 * reused with different, already-fine placeholders by a separate "waitlist" section on another
 * product's checkout, which this must never touch.
 *
 * TWO filters are needed. WCFE's custom sections do not render from the array
 * `woocommerce_checkout_fields` produces; they call woocommerce_form_field() directly with args
 * from their own stored config, and core applies `woocommerce_form_field_args` there. So that
 * hook is the one that reaches the HTML. `woocommerce_checkout_fields` is kept at priority 1100
 * (WCFE hooks itself there at 1000, so this must run after it) because WCFE's required-field
 * validation and admin/e-mail display read `WC()->checkout->checkout_fields` back later.
 *
 * blueline_wc_checkout_field_guidance_for_key() works on one field at a time and calls only __()
 * and the `blueline_core_checkout_field_guidance` filter, so it is unit-testable without WordPress.
 */
add_filter( 'woocommerce_form_field_args', 'blueline_wc_checkout_form_field_guidance', 20, 2 );
add_filter( 'woocommerce_checkout_fields', 'blueline_wc_checkout_field_guidance', 1100 );

/**
 * `woocommerce_form_field_args` callback: the filter that reaches the rendered HTML (see the
 * comment above the add_filter() calls).
 *
 * @param array  $args WooCommerce form-field args, as accepted by woocommerce_form_field().
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @return array $args, with only a matched field's autocomplete, placeholder and/or description changed.
 */
function blueline_wc_checkout_form_field_guidance( $args, $key ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	return blueline_wc_checkout_field_guidance_for_key( (string) $key, $args );
}

/**
 * `woocommerce_checkout_fields` callback: keeps `WC()->checkout->checkout_fields` (validation,
 * admin/e-mail display) in sync with the same correction.
 *
 * @param array $fields WooCommerce checkout fields, keyed by section then field id.
 * @return array The same shape, with only matched fields' args changed.
 */
function blueline_wc_checkout_field_guidance( array $fields ): array {
	foreach ( $fields as $section => $section_fields ) {
		if ( ! is_array( $section_fields ) ) {
			continue;
		}

		foreach ( $section_fields as $key => $field_args ) {
			if ( is_array( $field_args ) ) {
				$fields[ $section ][ $key ] = blueline_wc_checkout_field_guidance_for_key( (string) $key, $field_args );
			}
		}
	}

	return $fields;
}

/**
 * The site-specific checkout field keys and copy blueline_wc_checkout_field_guidance_for_key()
 * acts on, one entry per Checkout Field Editor Pro field key.
 *
 * Each entry is an array with:
 * - `mode`        `set` fills the placeholder/description when each is empty or still this
 *                 entry's own default text (an admin-edited value is left alone); `move`
 *                 replaces the placeholder and appends the description, but only when the
 *                 field's existing placeholder contains `match`.
 * - `match`       (`move` only) substring of the existing placeholder that identifies the
 *                 field to rewrite.
 * - `placeholder` The replacement placeholder.
 * - `description` The persistent description (set, or appended for `move`).
 *
 * @return array<string, array<string, string>>
 */
function blueline_wc_checkout_field_guidance_map(): array {
	$partner = array(
		'mode'        => 'move',
		'match'       => 'Please enter only 1 name',
		'placeholder' => __( "Person's full name", 'blueline-core' ),
		'description' => __( 'Enter only 1 name -- do not use this field for captains or team names.', 'blueline-core' ),
	);

	$map = array(
		'arl_division' => array(
			'mode'        => 'set',
			'placeholder' => __( 'Select skill level(s)', 'blueline-core' ),
			'description' => __(
				"This decides which numbered division you're placed in -- select every skill level you'd be comfortable playing at (see /standings for this season's actual divisions).",
				'blueline-core'
			),
		),
		'arl_team'     => array(
			'mode'        => 'move',
			'match'       => 'Remember',
			'placeholder' => __( "Team or captain's name", 'blueline-core' ),
			'description' => __( 'Remember: this is only a request, not a guarantee.', 'blueline-core' ),
		),
		'arl_request'  => $partner,
		'arl_request2' => $partner,
		'arl_request3' => $partner,
	);

	/**
	 * Filters the checkout field guidance map: the field keys and copy the plugin rewrites on
	 * the checkout form. Add, change or remove entries to adapt it to another site's fields.
	 * Entries that are not arrays of the shape documented on
	 * blueline_wc_checkout_field_guidance_map() are ignored.
	 *
	 * @param array<string, array<string, string>> $map Guidance entries keyed by field key.
	 */
	$filtered = apply_filters( 'blueline_core_checkout_field_guidance', $map );

	return is_array( $filtered ) ? $filtered : $map;
}

/**
 * Apply the autocomplete token and the guidance copy to one field's args. The field keys and copy
 * come from blueline_wc_checkout_field_guidance_map() (filterable); matching is guarded by the
 * field's existing content rather than its key alone (see the comment above the add_filter() calls).
 *
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @param array  $args WooCommerce form-field args for this one field.
 * @return array $args, unchanged unless this exact field matched.
 */
function blueline_wc_checkout_field_guidance_for_key( string $key, array $args ): array {
	$autocomplete = blueline_wc_checkout_field_autocomplete( $key );

	if ( null !== $autocomplete ) {
		$args['autocomplete'] = $autocomplete;
	}

	$entry = blueline_wc_checkout_field_guidance_map()[ $key ] ?? null;

	if (
		! is_array( $entry )
		|| ! is_string( $entry['placeholder'] ?? null )
		|| ! is_string( $entry['description'] ?? null )
	) {
		return $args;
	}

	$placeholder = (string) ( $args['placeholder'] ?? '' );
	$description = (string) ( $args['description'] ?? '' );

	if ( 'set' === ( $entry['mode'] ?? '' ) ) {
		// Only fill what is empty or is already this copy: a placeholder or description an admin
		// edited in Checkout Field Editor Pro must not be silently overwritten on every render.
		if ( '' === $placeholder || $placeholder === $entry['placeholder'] ) {
			$args['placeholder'] = $entry['placeholder'];
		}

		if ( '' === $description || $description === $entry['description'] ) {
			$args['description'] = $entry['description'];
		}

		return $args;
	}

	$needle = $entry['match'] ?? '';

	if ( 'move' === ( $entry['mode'] ?? '' ) && is_string( $needle ) && '' !== $needle && false !== strpos( $placeholder, $needle ) ) {
		$args['placeholder'] = $entry['placeholder'];
		$args['description'] = trim( $description . ' ' . $entry['description'] );
	}

	return $args;
}

/**
 * A standard WHATWG autofill token for one of WooCommerce's default billing/shipping field keys,
 * or null for anything else (the custom arl_* fields have no fitting token; none is invented).
 *
 * Why: WCFE's field renderer sets `autocomplete="off"` on every checkout field whose autocomplete
 * is not configured in its admin screen (`$autocomplete = $autocomplete ? $autocomplete : 'off'`),
 * and none is configured here, so browser/mobile autofill is blocked site-wide. Both callers run
 * before WooCommerce hands $args to that renderer, so a non-empty value here never falls through
 * to 'off'. Done by filter, since a hand-edit of the plugin would be lost on its next update.
 *
 * @param string $key The field's id/name (e.g. 'billing_email').
 * @return string|null
 */
function blueline_wc_checkout_field_autocomplete( string $key ): ?string {
	$map = array(
		'billing_first_name'  => 'given-name',
		'shipping_first_name' => 'given-name',
		'billing_last_name'   => 'family-name',
		'shipping_last_name'  => 'family-name',
		'billing_company'     => 'organization',
		'shipping_company'    => 'organization',
		'billing_email'       => 'email',
		'billing_phone'       => 'tel',
		'billing_address_1'   => 'address-line1',
		'shipping_address_1'  => 'address-line1',
		'billing_address_2'   => 'address-line2',
		'shipping_address_2'  => 'address-line2',
		'billing_city'        => 'address-level2',
		'shipping_city'       => 'address-level2',
		'billing_state'       => 'address-level1',
		'shipping_state'      => 'address-level1',
		'billing_postcode'    => 'postal-code',
		'shipping_postcode'   => 'postal-code',
		'billing_country'     => 'country',
		'shipping_country'    => 'country',
	);

	return $map[ $key ] ?? null;
}

add_filter( 'woocommerce_form_field_checkbox', 'blueline_wc_required_checkbox_attributes', 20, 3 );
/**
 * Add `required` and `aria-required` to the <input> of a checkout checkbox that WCFE's config
 * marks required (the waiver and trade-clause acknowledgements). WCFE renders only a visible
 * `<abbr class="required">*</abbr>`, so screen-reader users get no hint until a failed submit and
 * the browser's native required validation never fires. WCFE's own checkbox renderer is hooked to
 * this same filter at priority 10; this runs after it and patches the HTML string.
 *
 * @param string $field The rendered field HTML.
 * @param string $key   The field's id/name.
 * @param array  $args  WooCommerce form-field args for this field.
 * @return string
 */
function blueline_wc_required_checkbox_attributes( string $field, string $key, array $args ): string {
	if ( empty( $args['required'] ) ) {
		return $field;
	}

	// Match within the <input> tag, not $field as a whole: every required field's wrapper/marker
	// already contains the word "required", so a whole-string check would always match. The
	// lookbehind keeps a `data-name="key"` attribute from being mistaken for name.
	$name_attr = '(?<![\w-])name="' . preg_quote( $key, '/' ) . '"';

	if ( ! preg_match( '/<input\b[^>]*' . $name_attr . '[^>]*>/', $field, $match, PREG_OFFSET_CAPTURE ) ) {
		return $field;
	}

	list( $tag, $offset ) = $match[0];

	// Already a real `required` attribute? Quoted values are dropped first so a `validate-required`
	// class is not mistaken for one; the lookarounds reject `data-required`/`aria-required` names.
	$attribute_names = preg_replace( '/="[^"]*"|=\'[^\']*\'/', '', $tag );

	if ( preg_match( '/(?<![\w-])required(?![\w-])/', (string) $attribute_names ) ) {
		return $field; // Already has the real attribute; nothing to add.
	}

	$patched = preg_replace(
		'/(' . $name_attr . ')/',
		'$1 required aria-required="true"',
		$tag,
		1
	);

	// Replace only the matched occurrence, not every identical <input> in $field.
	return substr_replace( $field, (string) $patched, (int) $offset, strlen( $tag ) );
}
