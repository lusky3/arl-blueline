<?php
/**
 * Appearance -> Blueline: the field renderers. The generic table-row field,
 * its aria wiring, and the page, term, date, brand-colour and
 * hero-photograph inputs.
 *
 * Loaded by inc/settings/page.php, whose docblock holds the constraints
 * this part follows.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Echo `aria-invalid`/`aria-describedby` for a field's input: invalid when
 * (and only when) that field failed the last save, and described by that
 * error first, then by the field's own help text. This is the exact markup
 * blueline_settings_render_field() and its per-type renderers each need,
 * extracted once rather than repeated at every one of those sites.
 *
 * @param bool   $has_error    Whether this field failed the last save.
 * @param string $error_id     The id of the `<p>` that error's text is
 *                              rendered into.
 * @param string $described_by Ids of the field's help paragraphs, space-separated ('' for none).
 * @return void
 */
function blueline_settings_render_field_aria_attrs( bool $has_error, string $error_id, string $described_by = '' ): void {
	$ids = trim( ( $has_error ? $error_id : '' ) . ' ' . $described_by );

	if ( $has_error ) {
		echo 'aria-invalid="true" ';
	}

	if ( '' !== $ids ) {
		echo 'aria-describedby="' . esc_attr( $ids ) . '"';
	}
}

/**
 * Ids of the help paragraphs blueline_settings_render_field() prints for a
 * field, for its input's aria-describedby: the `help` text, then a section
 * toggle's widget warning.
 *
 * @param string $input_id       The field's input id.
 * @param array  $field          Schema entry.
 * @param string $widget_warning blueline_section_widget_warning() for this field, or ''.
 * @return string Space-separated ids, '' for none.
 */
function blueline_settings_field_described_by( string $input_id, array $field, string $widget_warning ): string {
	$ids = array();

	if ( ! empty( $field['help'] ) ) {
		$ids[] = $input_id . '-description';
	}

	if ( '' !== $widget_warning ) {
		$ids[] = $input_id . '-warning';
	}

	return implode( ' ', $ids );
}

/**
 * Render one field's table row: a real `<label for>`, the input itself
 * (a wp_dropdown_pages()-style select for `page_id` fields, because an admin
 * picks "FAQs" and never types a raw post ID; a wp_dropdown_categories()-style
 * select for a `term_id` field that declares a `taxonomy`, because an admin picks
 * "Registration" and never types a raw term ID; a plain number input for a
 * `term_id` field that does NOT declare a taxonomy (no such field exists
 * today, but the branch stays available for one that has no taxonomy to
 * pick from); a `<select>` for any field declaring `choices`, checked ahead
 * of every type branch except `band_photos` because it describes the
 * control rather than the storage shape;
 * an `<input type="date">` for a `date` field (Task 6);
 * an `<input type="color">` (swatch + hex text) for a `color` field (Task 3);
 * without it a `date` or `color` would fall through to the plain text input, since an
 * unrecognised type does not error here, it simply takes the last branch;
 * text/email otherwise), and, when this field failed the last
 * save, `aria-invalid`, `aria-describedby` and a visible error paragraph
 * that does not rely on colour alone (an explicit "Error:" prefix plus
 * text, in addition to the `bl-settings-field--error` class a stylesheet
 * may use for a colour treatment).
 *
 * `page_id`/`term_id` fields never fail validation: blueline_sanitize_field()
 * sanitizes both with absint(), which cannot return a WP_Error, so
 * neither branch below needs to handle an error state. A `date` field CAN
 * (a malformed date is a WP_Error, not a coercion), a `color` field CAN
 * (invalid hex is a WP_Error), and so can a `choices`
 * field (a value off the list is refused rather than dropped, reachable
 * from a hand-built POST or WP-CLI even though the select cannot produce
 * one), so all three branches carry the same aria wiring the text input does.
 *
 * A field's `help` string is printed once, after whichever branch ran, for
 * every type. It used to be printed inside the `bool`/`section` branch
 * alone, which silently dropped `help` on any other type.
 *
 * @param string      $field_key     A blueline_settings_schema() key.
 * @param array       $field         That key's schema entry.
 * @param string|null $error_message Already-escaped error message for this
 *                                    field from the last save, or null.
 * @return void
 */
function blueline_settings_render_field( string $field_key, array $field, ?string $error_message ): void {
	$type      = $field['type'] ?? 'text';
	$label     = $field['label'] ?? $field_key;
	$value     = blueline_settings( $field_key );
	$input_id  = blueline_settings_field_input_id( $field_key );
	$error_id  = $input_id . '-error';
	$name      = BLUELINE_SETTINGS_OPTION . '[' . $field_key . ']';
	$has_error = null !== $error_message;

	/*
	 * Task 5 (P1b-panel-completion): a `section` field mapped to a
	 * populated widget area (today, the four `chrome_footer_widgets_N`
	 * keys; see blueline_section_widget_warning()'s own docblock
	 * for the real per-area mapping) gets a second, distinct notice
	 * naming the live widget count, so switching it off doesn't read
	 * as "delete my widgets" to whoever's holding the mouse. '' for
	 * every non-`section` field, and for any `section` field with no
	 * widget area behind it or an empty one.
	 */
	$widget_warning = 'section' === $type ? blueline_section_widget_warning( $field_key ) : '';
	$described_by   = blueline_settings_field_described_by( $input_id, $field, $widget_warning );
	?>
	<tr class="bl-settings-field<?php echo esc_attr( $has_error ? ' bl-settings-field--error' : '' ); ?>">
		<th scope="row">
			<label for="<?php echo esc_attr( $input_id ); ?>"<?php echo 'band_photos' === $type ? ' id="' . esc_attr( $input_id . '-label' ) . '"' : ''; ?>><?php echo esc_html( $label ); ?></label>
		</th>
		<td>
			<?php if ( 'band_photos' === $type ) : ?>
				<?php blueline_settings_render_band_photos( $field_key, $field, $name, $input_id, (array) $value, $described_by ); ?>
			<?php elseif ( ! empty( $field['choices'] ) ) : ?>
				<?php
				/*
				 * Task 7 fix round: `choices` is checked ahead of every
				 * `type` branch below because it is orthogonal to type: it
				 * says "this field's value comes off a fixed list", which is
				 * a statement about the control, not the storage shape.
				 *
				 * `band_photos` above it is the one exception, and stays
				 * first deliberately: it is a repeater with its own renderer
				 * and no single scalar value, so a `<select>` could not
				 * express it even if someone added `choices` to it.
				 * SettingsDefaultsTest forbids that combination outright.
				 *
				 * A dropdown rather than a validated text box, deliberately:
				 * rejecting a typo at save time tells an admin they were
				 * wrong, but a list of five options means they cannot be
				 * wrong in the first place. That matters most for
				 * `season_state_override`, which is used under pressure.
				 * blueline_sanitize_field()'s own `choices` branch is still
				 * the guard behind this, for a hand-built POST or a WP-CLI
				 * write that never renders this select at all.
				 *
				 * No hidden companion input is needed here (unlike the
				 * checkbox branch below): a `<select>` always posts exactly
				 * one value, including its empty option.
				 */
				?>
				<select
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					<?php blueline_settings_render_field_aria_attrs( $has_error, $error_id, $described_by ); ?>
				>
					<?php foreach ( (array) $field['choices'] as $choice_value => $choice_label ) : ?>
						<option
							value="<?php echo esc_attr( (string) $choice_value ); ?>"
							<?php selected( (string) $choice_value, (string) $value ); ?>
						><?php echo esc_html( (string) $choice_label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'bool' === $type || 'section' === $type ) : ?>
				<?php
				/*
				 * The hidden input before the checkbox is what makes UNCHECKING
				 * work: an unchecked box posts nothing at all, so without a
				 * companion the sanitizer would never see the field and the
				 * stored `true` would survive the save. The hidden 0 is always
				 * posted; a checked box overwrites it, because a later value
				 * wins for the same key.
				 *
				 * A `section` field (inc/settings/sections.php's presence
				 * toggles) renders with this exact same markup as `bool`,
				 * the same companion and the same bare checkbox, since it sanitizes
				 * identically (inc/settings/sanitize.php); the distinct type
				 * name is conceptual, not a rendering difference.
				 */
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
				<?php
				/*
				 * No wrapping <label> here: the row header already renders one
				 * with `for` pointing at this input, so a second would print
				 * the same sentence twice on screen and announce it twice to a
				 * screen reader.
				 */
				?>
				<input
					type="checkbox"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( (bool) $value ); ?>
					<?php blueline_settings_render_field_aria_attrs( false, '', $described_by ); ?>
				>
			<?php elseif ( 'page_id' === $type ) : ?>
				<?php blueline_settings_render_page_id_field( $field_key, $field, $name, $input_id, $value, $described_by ); ?>
			<?php elseif ( 'term_id' === $type && ! empty( $field['taxonomy'] ) ) : ?>
				<?php blueline_settings_render_term_id_field( $field_key, $field, $name, $input_id, $value, $described_by ); ?>
			<?php elseif ( 'date' === $type ) : ?>
				<?php blueline_settings_render_date_field( $field_key, $field, $name, $input_id, $value, $has_error, $error_id, $described_by ); ?>
			<?php elseif ( 'term_id' === $type ) : ?>
				<input
					type="number"
					min="0"
					step="1"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="small-text"
					<?php blueline_settings_render_field_aria_attrs( false, '', $described_by ); ?>
				>
			<?php elseif ( 'color' === $type ) : ?>
				<?php blueline_settings_render_color_field( $field_key, $field, $name, $input_id, (string) $value, $has_error, $error_id, $described_by ); ?>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( 'email' === $type ? 'email' : 'text' ); ?>"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="regular-text"
					<?php blueline_settings_render_field_aria_attrs( $has_error, $error_id, $described_by ); ?>
				>
			<?php endif; ?>

			<?php
			/*
			 * A field's `help` string renders once, here, for EVERY type,
			 * not inside each branch. It used to live inside the
			 * `bool`/`section` branch alone, which meant a `help` string on
			 * any other type was accepted by the schema, listed in its
			 * docblock, and then silently dropped at render: the panel would
			 * have promised an explanation it never printed. Task 6's
			 * announcement fields are the first `text`/`page_id`/`date`
			 * fields to carry one.
			 */
			?>
			<?php if ( ! empty( $field['help'] ) ) : ?>
				<p class="description" id="<?php echo esc_attr( $input_id . '-description' ); ?>"><?php echo esc_html( (string) $field['help'] ); ?></p>
			<?php endif; ?>

			<?php if ( '' !== $widget_warning ) : ?>
				<p class="description" id="<?php echo esc_attr( $input_id . '-warning' ); ?>"><?php echo esc_html( $widget_warning ); ?></p>
			<?php endif; ?>

			<?php if ( $has_error ) : ?>
				<p id="<?php echo esc_attr( $error_id ); ?>" class="bl-settings-field__error">
					<strong><?php echo esc_html( __( 'Error:', 'blueline' ) ); ?></strong>
					<?php echo $error_message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_sanitize_callback() at the point add_settings_error() was called (see this file's docblock); re-escaping here would double-encode entities such as turning "&lt;" into "&amp;lt;". ?>
				</p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
}

/**
 * Render a `page_id` field: a dropdown of every published page, plus a
 * "use the built-in default" option at 0.
 *
 * @param string $field_key Schema key (unused directly here, but kept for
 *                           the same call shape as this file's other
 *                           per-type field renderers).
 * @param array  $field     Schema entry (likewise unused; kept for shape).
 * @param string $name      The `name` attribute for this field's control.
 * @param string $input_id  The field's input id.
 * @param mixed  $value     The currently stored (or posted-back) value.
 * @param string $described_by Ids of the field's help paragraphs, '' for none.
 * @return void
 */
function blueline_settings_render_page_id_field( string $field_key, array $field, string $name, string $input_id, $value, string $described_by = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $field_key and $field are unused here but kept for call-shape parity with this file's other per-type field renderers (blueline_settings_render_band_photos(), blueline_settings_render_term_id_field(), blueline_settings_render_date_field()).
	$select = wp_dropdown_pages(
		array(
			'name'              => esc_attr( $name ),
			'id'                => esc_attr( $input_id ),
			'selected'          => (int) $value,
			'show_option_none'  => esc_html__( '— Use built-in page —', 'blueline' ),
			'option_none_value' => 0,
			'echo'              => 0,
		)
	);

	echo blueline_settings_select_described_by( (string) $select, $described_by ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built <select>, plus an esc_attr()'d attribute.
}

/**
 * Add aria-describedby to a <select> core built (wp_dropdown_pages(),
 * wp_dropdown_categories()), which take no attribute arguments of their own.
 *
 * @param string $select       The <select> markup.
 * @param string $described_by Space-separated ids, '' for none.
 * @return string
 */
function blueline_settings_select_described_by( string $select, string $described_by ): string {
	if ( '' === $described_by ) {
		return $select;
	}

	return (string) preg_replace( '/<select\b/', '<select aria-describedby="' . esc_attr( $described_by ) . '"', $select, 1 );
}

/**
 * Render a `term_id` field whose schema names a `taxonomy`: a dropdown of
 * every term in that taxonomy, plus a "use the built-in default" option at 0.
 *
 * A `term_id` field with no `taxonomy` set falls through to a plain number
 * input instead (see blueline_settings_render_field()'s own `term_id`
 * branch below this one), so this function is never called for that case.
 *
 * @param string $field_key Schema key (unused directly here; kept for shape).
 * @param array  $field     Schema entry, read for its `taxonomy`.
 * @param string $name      The `name` attribute for this field's control.
 * @param string $input_id  The field's input id.
 * @param mixed  $value     The currently stored (or posted-back) value.
 * @param string $described_by Ids of the field's help paragraphs, '' for none.
 * @return void
 */
function blueline_settings_render_term_id_field( string $field_key, array $field, string $name, string $input_id, $value, string $described_by = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $field_key is unused here but kept for call-shape parity, as above.
	$select = wp_dropdown_categories(
		array(
			'taxonomy'          => $field['taxonomy'],
			'name'              => esc_attr( $name ),
			'id'                => esc_attr( $input_id ),
			'selected'          => (int) $value,
			'show_option_none'  => esc_html__( '— Use built-in category —', 'blueline' ),
			'option_none_value' => 0,
			'hide_empty'        => false,
			'echo'              => 0,
		)
	);

	echo blueline_settings_select_described_by( (string) $select, $described_by ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built <select>, plus an esc_attr()'d attribute.
}

/**
 * Render a `date` field as a real `<input type="date">`, rather than letting
 * it fall through to the plain text input every other unrecognised type
 * gets.
 *
 * Task 6: without this, a `date` field falls through to the plain text
 * input below: no error, just a text box an admin has to know to type
 * YYYY-MM-DD into, against a sanitizer that rejects anything else.
 * `<input type="date">` is what makes the stored format and the entered
 * format the same thing.
 *
 * Unlike `page_id`/`term_id`, a `date` CAN fail validation
 * (blueline_sanitize_field() returns a WP_Error for a malformed value), so
 * this branch carries the same aria-invalid/aria-describedby wiring the
 * text input does.
 *
 * @param string $field_key Schema key (unused directly here; kept for shape).
 * @param array  $field     Schema entry (likewise unused; kept for shape).
 * @param string $name      The `name` attribute for this field's control.
 * @param string $input_id  The field's input id.
 * @param mixed  $value     The currently stored (or posted-back) value.
 * @param bool   $has_error Whether blueline_settings_render_field()'s caller
 *                           found a validation error for this field.
 * @param string $error_id  The id of the `<p>` that error's text is
 *                           rendered into, for aria-describedby.
 * @param string $described_by Ids of the field's help paragraphs, '' for none.
 * @return void
 */
function blueline_settings_render_date_field( string $field_key, array $field, string $name, string $input_id, $value, bool $has_error, string $error_id, string $described_by = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $field_key and $field are unused here but kept for call-shape parity, as above.
	?>
	<input
		type="date"
		id="<?php echo esc_attr( $input_id ); ?>"
		name="<?php echo esc_attr( $name ); ?>"
		value="<?php echo esc_attr( (string) $value ); ?>"
		<?php blueline_settings_render_field_aria_attrs( $has_error, $error_id, $described_by ); ?>
	>
	<?php
}

/**
 * Render one brand-color override field: a native colour-picker swatch
 * paired with a hex text input (kept in sync by
 * assets/src/js/settings-brand-colors.js), plus a live, advisory contrast
 * readout against every tools/contrast-rules.json rule that names this
 * token (blueline_brand_color_contrast_report()). Never blocks
 * submission — see this feature's design spec §1.
 *
 * @param string $field_key Schema key, e.g. 'brand_color_ice'.
 * @param array  $field     Schema entry; must carry 'token_key'.
 * @param string $name      Input name attribute.
 * @param string $input_id  Input id attribute.
 * @param string $value     Current stored value ('' if unset).
 * @param bool   $has_error True if this field failed the last save.
 * @param string $error_id  The id attribute for the error message element.
 * @param string $described_by Ids of the field's help paragraphs, '' for none.
 * @return void
 */
function blueline_settings_render_color_field( string $field_key, array $field, string $name, string $input_id, $value, bool $has_error, string $error_id, string $described_by = '' ): void {
	$token_key = (string) ( $field['token_key'] ?? '' );
	$tokens    = blueline_brand_color_tokens();
	$default   = $tokens[ $token_key ]['default_hex'] ?? '';
	$candidate = '' !== $value ? blueline_sanitize_hex_color( $value ) : '';
	$candidate = '' !== $candidate ? $candidate : $default;
	?>
	<input
		type="color"
		value="<?php echo esc_attr( $candidate ); ?>"
		data-bl-brand-color-picker
		tabindex="-1"
		aria-hidden="true"
	>
	<input
		type="text"
		id="<?php echo esc_attr( $input_id ); ?>"
		name="<?php echo esc_attr( $name ); ?>"
		value="<?php echo esc_attr( $value ); ?>"
		class="regular-text"
		placeholder="<?php echo esc_attr( $default ); ?>"
		data-bl-brand-color-hex
		data-bl-brand-color-token="<?php echo esc_attr( $token_key ); ?>"
		<?php blueline_settings_render_field_aria_attrs( $has_error, $error_id, trim( $input_id . '-contrast ' . $described_by ) ); ?>
	>
	<?php
	/*
	 * Polite live region, like the Occasions readout: settings-brand-colors.js
	 * rewrites it as the admin types, and it describes the hex input it reports on.
	 */
	?>
	<p class="description" id="<?php echo esc_attr( $input_id . '-contrast' ); ?>" data-bl-brand-color-contrast data-bl-brand-color-contrast-for="<?php echo esc_attr( $token_key ); ?>" aria-live="polite">
		<?php foreach ( blueline_brand_color_contrast_report( $token_key, $candidate ) as $row ) : ?>
			<span class="<?php echo esc_attr( $row['passes'] ? 'bl-contrast-pass' : 'bl-contrast-fail' ); ?>">
				<?php
				printf(
					/* translators: 1: "Pass" or "Fail" status label (not color alone, per WCAG 1.4.1), 2: rule description, 3: computed contrast ratio. */
					esc_html__( '%1$s — %2$s: %3$s:1', 'blueline' ),
					esc_html( $row['passes'] ? __( 'Pass', 'blueline' ) : __( 'Fail', 'blueline' ) ),
					esc_html( $row['description'] ),
					esc_html( number_format_i18n( $row['ratio'], 2 ) )
				);
				?>
			</span><br>
		<?php endforeach; ?>
	</p>
	<?php
}

/**
 * Render the repeatable hero-photograph field: a row per photograph carrying
 * its thumbnail, its own alignment, and a remove control, plus the button that
 * opens the media library.
 *
 * Every row is rendered server-side, including for photographs added in this
 * session: assets/src/js/settings-photos.js clones the empty template below
 * rather than assembling markup of its own, so there is exactly one definition
 * of what a row looks like and the no-JS state and the JS state cannot drift.
 *
 * With JavaScript unavailable the picker button does nothing, but the existing
 * rows still render, still submit, and their alignment selects still work,
 * so an admin without JS can reorder priorities and fix alignment even if they
 * cannot add a new photograph.
 *
 * @param string $field_key Schema key.
 * @param array  $field     Schema entry.
 * @param string $name      The `name` attribute base for this field.
 * @param string $input_id  The field's input id.
 * @param array  $rows      Stored rows: each { id, align }.
 * @param string $described_by Ids of the field's help paragraphs, '' for none.
 * @return void
 */
function blueline_settings_render_band_photos( string $field_key, array $field, string $name, string $input_id, array $rows, string $described_by = '' ): void {
	$alignments = blueline_band_photo_alignments();
	$max        = (int) ( $field['max'] ?? 12 );

	/*
	 * A named group: the row's <label for> points at this <div>, which a
	 * label cannot name, so the group takes its name from that label by id.
	 */
	?>
	<div
		class="bl-photos"
		id="<?php echo esc_attr( $input_id ); ?>"
		role="group"
		aria-labelledby="<?php echo esc_attr( $input_id . '-label' ); ?>"
		<?php blueline_settings_render_field_aria_attrs( false, '', $described_by ); ?>
		data-bl-photos
		data-bl-photos-name="<?php echo esc_attr( $name ); ?>"
		data-bl-photos-max="<?php echo esc_attr( (string) $max ); ?>"
	>
		<ul class="bl-photos__list" data-bl-photos-list>
			<?php foreach ( $rows as $index => $row ) : ?>
				<?php
				$photo_id = absint( $row['id'] ?? 0 );

				if ( ! $photo_id ) {
					continue;
				}

				$align = (string) ( $row['align'] ?? 'center-center' );
				?>
				<?php $photo_title = (string) get_the_title( $photo_id ); ?>
				<li class="bl-photos__item" data-bl-photos-item>
					<img
						class="bl-photos__thumb"
						src="<?php echo esc_url( (string) wp_get_attachment_image_url( $photo_id, 'thumbnail' ) ); ?>"
						alt=""
						width="60"
						height="60"
					>
					<input
						type="hidden"
						name="<?php echo esc_attr( $name . '[' . $index . '][id]' ); ?>"
						value="<?php echo esc_attr( (string) $photo_id ); ?>"
						data-bl-photos-id
					>
					<label class="bl-photos__align">
						<span class="screen-reader-text">
							<?php
							printf(
								/* translators: %s: the photograph's file name. */
								esc_html__( 'Alignment for %s', 'blueline' ),
								esc_html( $photo_title )
							);
							?>
						</span>
						<select name="<?php echo esc_attr( $name . '[' . $index . '][align]' ); ?>" data-bl-photos-align>
							<?php foreach ( $alignments as $key => $unused ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $align, $key ); ?>>
									<?php echo esc_html( blueline_settings_alignment_label( $key ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="button" class="button-link bl-photos__remove" data-bl-photos-remove>
						<?php esc_html_e( 'Remove', 'blueline' ); ?><span class="screen-reader-text"> <?php echo esc_html( $photo_title ); ?></span>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="bl-photos__empty" data-bl-photos-empty <?php echo $rows ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Using the photographs that ship with the theme.', 'blueline' ); ?>
		</p>

		<p>
			<button type="button" class="button" data-bl-photos-add>
				<?php esc_html_e( 'Add photographs', 'blueline' ); ?>
			</button>
		</p>

		<?php
		/*
		 * This field's `help` string is NOT printed here: since Task 6,
		 * blueline_settings_render_field() prints it once for every field
		 * type, immediately after whichever input branch ran. Printing it
		 * again here would render it twice for this one field.
		 */
		?>
		<template data-bl-photos-template>
			<li class="bl-photos__item" data-bl-photos-item>
				<img class="bl-photos__thumb" src="" alt="" width="60" height="60">
				<input type="hidden" name="" value="" data-bl-photos-id>
				<label class="bl-photos__align">
					<span class="screen-reader-text" data-bl-photos-align-name><?php esc_html_e( 'Alignment', 'blueline' ); ?></span>
					<select name="" data-bl-photos-align>
						<?php foreach ( $alignments as $key => $unused ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'center-center', $key ); ?>>
								<?php echo esc_html( blueline_settings_alignment_label( $key ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button-link bl-photos__remove" data-bl-photos-remove>
					<?php esc_html_e( 'Remove', 'blueline' ); ?><span class="screen-reader-text" data-bl-photos-remove-name></span>
				</button>
			</li>
		</template>
	</div>
	<?php
}

/**
 * Human-readable label for an alignment key.
 *
 * Kept out of blueline_band_photo_alignments() so that map stays a pure
 * key => CSS-value lookup usable on the front end, where these admin-facing
 * strings have no business being loaded.
 *
 * @param string $key An alignment key.
 * @return string
 */
function blueline_settings_alignment_label( string $key ): string {
	$labels = array(
		'left-top'      => __( 'Left top', 'blueline' ),
		'center-top'    => __( 'Centre top', 'blueline' ),
		'right-top'     => __( 'Right top', 'blueline' ),
		'left-center'   => __( 'Left middle', 'blueline' ),
		'center-center' => __( 'Centre', 'blueline' ),
		'right-center'  => __( 'Right middle', 'blueline' ),
		'left-bottom'   => __( 'Left bottom', 'blueline' ),
		'center-bottom' => __( 'Centre bottom', 'blueline' ),
		'right-bottom'  => __( 'Right bottom', 'blueline' ),
	);

	return $labels[ $key ] ?? $key;
}
