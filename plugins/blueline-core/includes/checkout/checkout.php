<?php
/**
 * Checkout field fixes for Checkout Field Editor Pro: autofill tokens, division/team/partner
 * guidance copy, and the required-checkbox a11y patch. Moved from
 * themes/blueline/inc/woocommerce.php.
 *
 * @package blueline-core
 */

defined( 'ABSPATH' ) || exit;

/*
 * Live-site UX review findings 1 & 2 -- checkout field guidance.
 *
 * "Preferred Division" (arl_division) and "Requested Team"/"Requested
 * Partner" (arl_team/arl_request/arl_request2/arl_request3) are four of the
 * ~16 custom fields the "WooCommerce Checkout Field Editor Pro" plugin (a
 * third-party plugin, not part of this repo) renders inside #customer_details on a live registration checkout. Neither
 * field's problem is a markup bug this theme can edit directly.
 *
 * Field keys and current content were read live off staging
 * (wp_options.thwcfe_sections, decoded via a one-off wp eval-file script
 * against the actual site, not guessed):
 *
 * - arl_division ("Preferred Division", section "player_profile"): a
 *   REQUIRED multiselect of skill levels ("5 - Beginner" .. "1 - Advanced"),
 *   not free text as first suspected from the live review alone -- but it
 *   had no placeholder and no description, so nothing on the page explains
 *   that this choice is what decides which numbered division (see
 *   /standings -- Division 1 through Division 5 on this site) a player is
 *   placed into.
 * - arl_team ("Requested Team"): placeholder was "Team or Captain's name.
 *   Remember: this is only a *request* and not a *guarantee*" -- the actual
 *   caveat that matters, sitting in a placeholder that truncates visually
 *   and disappears entirely once the field has a value.
 * - arl_request / arl_request2 / arl_request3 ("Requested Partner" /
 *   "Additional Partner" x2): all three share the identical placeholder
 *   "Please enter only 1 name... Do not use this field for captains or team
 *   names." -- same mechanism, same fix, applied to all three rather than
 *   just the first for consistency.
 *
 * Each fix is guarded by the EXISTING placeholder text (not just the field
 * key) before rewriting it -- the "Preferred Division" fix applies only to an
 * empty value or to its own default copy, the team/partner fixes only to the
 * known long placeholder -- because the very same field keys are
 * reused, with different and already-fine short placeholders ("Team Name",
 * "Person's Name"), by a separate "waitlist" section on a different
 * product's checkout -- confirmed live in the same thwcfe_sections dump.
 * Matching on content rather than trusting the key alone means this can
 * never touch that other section's fields, even if this site's field
 * config changes which section name is used for which product in the
 * future.
 *
 * TWO filters, not one, are needed to actually change what renders --
 * confirmed live (deployed the `woocommerce_checkout_fields` filter alone
 * first; the checkout page's HTML did not change at all). WooCommerce
 * Checkout Field Editor Pro's custom sections (like "player_profile") are
 * NOT rendered from the array that filter produces: they render via
 * THWCFE_Public_Checkout::output_custom_section_single(), which reads the
 * field's args straight from its own stored section config and calls
 * WooCommerce core's woocommerce_form_field( $name, $field, $value )
 * directly -- and core's woocommerce_form_field() is what applies the
 * `woocommerce_form_field_args` filter, on every field it renders,
 * regardless of where its args array came from. That is the hook that
 * actually reaches the rendered HTML.
 *
 * `woocommerce_checkout_fields` is kept as well, at priority 1100 (WCFE
 * Pro hooks its own callback there at priority 1000 by default -- see
 * THWCFE_Public_Checkout::define_public_hooks() -- so this must run after
 * it, not before it finds anything to fix), because
 * `WC()->checkout->checkout_fields` -- populated via that same filter --
 * is what WCFE Pro's own required-field validation
 * (woo_checkout_fields_validation()) and admin/e-mail field display read
 * back later, and those should see the same corrected copy.
 *
 * blueline_wc_checkout_field_guidance_for_key() is deliberately a pure,
 * one-field-at-a-time function (its only WordPress calls are __() and the
 * `blueline_core_checkout_field_guidance` filter) so it can be unit tested
 * with real WooCommerce/WCFE array shapes in, asserted array shapes out, and no
 * WordPress install required. Both filters below are thin wrappers around it.
 */
add_filter( 'woocommerce_form_field_args', 'blueline_wc_checkout_form_field_guidance', 20, 2 );
add_filter( 'woocommerce_checkout_fields', 'blueline_wc_checkout_field_guidance', 1100 );

/**
 * `woocommerce_form_field_args` wrapper -- the filter that actually reaches
 * the rendered checkout HTML. See the registration comment above for why
 * this is required in addition to (not instead of) the
 * `woocommerce_checkout_fields` filter below.
 *
 * @param array  $args WooCommerce form-field args, as accepted by
 *                      woocommerce_form_field() -- notably 'placeholder'
 *                      and 'description'.
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @return array $args, with only a matched field's 'placeholder' and/or
 *               'description' entries changed.
 */
function blueline_wc_checkout_form_field_guidance( $args, $key ) {
	if ( ! is_array( $args ) ) {
		return $args;
	}

	return blueline_wc_checkout_field_guidance_for_key( (string) $key, $args );
}

/**
 * `woocommerce_checkout_fields` wrapper -- keeps
 * `WC()->checkout->checkout_fields` (validation, admin/e-mail field
 * display) in sync with the same correction. See the registration comment
 * above for why this alone does not fix the rendered checkout HTML.
 *
 * @param array $fields WooCommerce checkout fields, keyed by section then
 *                       field id (each field id => array of args accepted
 *                       by woocommerce_form_field()).
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
 * Move truncating/disappearing checkout-field placeholder caveats into a
 * persistent description, and give the "Preferred Division" field the
 * example text it never had. The field keys and copy come from
 * blueline_wc_checkout_field_guidance_map() (filterable through
 * `blueline_core_checkout_field_guidance`). See the registration comment
 * above blueline_wc_checkout_form_field_guidance() for the live-audited
 * field keys/content this acts on, and why matching is guarded by the
 * field's EXISTING content rather than its key alone.
 *
 * @param string $key  The field's id/name (e.g. 'arl_division').
 * @param array  $args WooCommerce form-field args for this one field.
 * @return array $args, unchanged unless this exact field matched.
 */
function blueline_wc_checkout_field_guidance_for_key( string $key, array $args ): array {
	$blueline_autocomplete = blueline_wc_checkout_field_autocomplete( $key );

	if ( null !== $blueline_autocomplete ) {
		$args['autocomplete'] = $blueline_autocomplete;
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
 * A standard WHATWG autofill token for one of WooCommerce's own default
 * billing/shipping field keys, or null for anything else (this theme's own
 * arl_* custom fields included -- none has an established token that fits,
 * and this deliberately does not invent one).
 *
 * Why this exists: WooCommerce Checkout Field Editor Pro's own field
 * renderer (class-thwcfe-public.php, woo_form_field()) sets
 * `autocomplete="off"` on EVERY checkout field whose autocomplete is not
 * explicitly configured in its own admin screen -- confirmed live,
 * 2026-09-04 UX audit, and confirmed in that plugin's own source:
 * `$autocomplete = $autocomplete ? $autocomplete : 'off';` unconditionally
 * defaults an empty value to 'off' rather than leaving it unset. Checked
 * this site's own thwcfe_sections config (wp option get thwcfe_sections):
 * every field's stored autocomplete value is blank -- none of this was a
 * deliberate admin choice, every checkout field site-wide silently blocks
 * browser/mobile autofill, on a form a rushed parent is filling out on
 * their phone.
 *
 * Populating $args['autocomplete'] here (both callers above run before
 * WooCommerce hands $args to that plugin's renderer) gives it a real,
 * non-empty value for that plugin's own `$autocomplete ? $autocomplete :
 * 'off'` check to find, so it never falls through to 'off' at all -- no
 * plugin file touched, since a hand-edit there would be silently discarded
 * on the plugin's next update anyway.
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
 * A checkout checkbox field WooCommerce Checkout Field Editor Pro's own
 * admin config marks required (this site has several: agreeing to the
 * COVID waiver terms, the trade-clause acknowledgement) renders with a
 * visible `<abbr class="required" title="required">*</abbr>` marker but no
 * `required` or `aria-required` on the `<input>` itself -- confirmed live,
 * 2026-09-04 UX audit, and reproduced directly: `woocommerce_form_field(
 * 'arl_tradeclause', ['type' => 'checkbox', 'required' => true], '' )`
 * returns `<input type="checkbox" ...>` with neither attribute. That
 * plugin's own checkbox renderer (class-thwcfe-public.php, hooked to this
 * SAME filter at priority 10) is the one building that HTML string; this
 * runs after it and patches the string rather than editing the plugin
 * file, which a plugin update would silently discard.
 *
 * Screen-reader users get no indication the field is required until an
 * error appears after a failed submit; sighted users relying on the
 * visible asterisk still don't get the browser's own native "please fill
 * out this field" validation before submitting, since HTML5's required
 * validation reads the attribute, not a decorative abbr.
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

	// Scoped to the <input> TAG specifically, not $field as a whole -- every
	// required field's own wrapper/marker already contains the word
	// "required" (`class="form-row validate-required ..."`, `<abbr
	// class="required" ...>`), so a plain strpos() over the whole string
	// would always find a match and this filter would never actually patch
	// anything. `(?<![\w-])` keeps a `data-name="key"` attribute from being mistaken for name.
	$name_attr = '(?<![\w-])name="' . preg_quote( $key, '/' ) . '"';

	if ( ! preg_match( '/<input\b[^>]*' . $name_attr . '[^>]*>/', $field, $match, PREG_OFFSET_CAPTURE ) ) {
		return $field;
	}

	list( $tag, $offset ) = $match[0];

	// Is there already a real `required` attribute? Quoted attribute values are dropped first so
	// a `validate-required` class (or any value containing the word) is not mistaken for one, and
	// the lookarounds then reject `data-required`/`aria-required`-style attribute names.
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
