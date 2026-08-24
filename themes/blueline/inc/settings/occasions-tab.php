<?php
/**
 * The Occasions tab's repeater UI: the tab renderer, its per-row renderer,
 * and the small label helpers those two use to turn a stored Occasion's
 * raw `type`/`motif`/`mode` values into copy an admin can read.
 *
 * Split out of inc/settings/page.php, which otherwise mixed this repeater's
 * markup in among routing, sanitisation and every other settings-tab concern.
 * blueline_settings_render_page() (inc/settings/page.php) still owns dispatching
 * to blueline_settings_render_occasions_tab() for the Occasions tab, the same
 * way it dispatches to the generic per-field loop for every other tab.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the entire Occasions tab: an "add from preset"/"add blank"
 * toolbar, one row per stored occasion, and the `<template>` a JS-added
 * row is cloned from.
 *
 * Design spec §5.1's third ruling: this is the bespoke renderer
 * blueline_settings_render_page() dispatches to for the Occasions tab
 * INSTEAD of the generic per-field `<table>` loop -- `occasions` has
 * zero schema fields of its own (it is a reserved settings key, not a
 * schema field: see BLUELINE_SETTINGS_RESERVED_KEYS's own docblock),
 * so the generic loop has nothing to render for this tab at all.
 *
 * ## The `__none__` marker row
 *
 * One hidden `[occasions][__none__][label]` field is rendered OUTSIDE
 * the repeater `<ul>`, so it survives every "Remove" click. It exists
 * for exactly one case: an admin deleting EVERY row and saving. An HTML
 * form cannot post an array field with zero entries -- with no real row
 * left, no `blueline_settings[occasions][...]` key would appear in the
 * request at all, blueline_settings_sanitize_callback()'s per-key loop
 * would never reach its `occasions` branch, `$output['occasions']` would
 * never be set, and blueline_settings_merge() would then carry the OLD
 * stored map straight back: the admin sees "Settings saved" and the
 * occasion (and its AA acknowledgement) is still there. Deleting one row
 * out of several was always fine; only deleting down to zero was
 * silently a no-op.
 *
 * The marker's own row never survives processing:
 * blueline_occasions_assign_unique_ids() (inc/occasions.php) drops any
 * row whose label yields an empty `sanitize_title()`, so this row is
 * gone before blueline_sanitize_occasions() ever sees it. Its only job
 * is to make the `occasions` key PRESENT, so the branch runs, computes
 * an empty map, and the acknowledgement orphan-cleanup runs with it.
 *
 * @return void
 */
function blueline_settings_render_occasions_tab(): void {
	$occasions        = blueline_settings( 'occasions' );
	$occasions        = is_array( $occasions ) ? $occasions : array();
	$inputs_hash      = blueline_settings_inputs_hash();
	$acknowledgements = blueline_stored_acknowledgements();
	$name             = BLUELINE_SETTINGS_OPTION . '[occasions]';
	?>
	<div class="bl-occasions" data-bl-occasions data-bl-occasions-name="<?php echo esc_attr( $name ); ?>">
		<?php // Always-present marker row -- see this function's docblock. Deliberately outside the <ul>, so removing every real row cannot remove it too. ?>
		<input
			type="hidden"
			name="<?php echo esc_attr( $name . '[__none__][label]' ); ?>"
			value=""
			data-bl-occasions-marker
		>

		<details class="bl-occasions__help">
			<summary><?php esc_html_e( 'How Occasions work', 'blueline' ); ?></summary>
			<p class="description">
				<?php
				echo esc_html(
					__( 'Occasions add a temporary accent colour, a small motif, and an optional line of copy for a set window of the calendar year. Nothing here activates until its Mode is set to something other than "Always off", or its window includes today.', 'blueline' )
				);
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					__( 'Add one from the preset list below, or start with a blank occasion. Each row has its own Mode, which decides when it can activate: "Automatic, during its window" lets its date range decide, "Always on (preview now)" turns it on right now no matter what the calendar says, and "Always off" disables it no matter what the window says.', 'blueline' )
				);
				?>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					__( 'Saving always succeeds. An occasion whose accent colour fails the AA contrast check against ink text simply will not activate unless its row acknowledgement checkbox is ticked. Acknowledgement is re-checked on every page load, not just when settings are saved, so if the accent changes or ever stops matching what was acknowledged, the occasion stops activating rather than showing a colour that fails the check.', 'blueline' )
				);
				?>
			</p>
		</details>

		<ul class="bl-occasions__list" data-bl-occasions-list>
			<?php if ( array() === $occasions ) : ?>
				<li class="bl-occasions__empty" data-bl-occasions-empty>
					<?php esc_html_e( 'No occasions configured yet.', 'blueline' ); ?>
				</li>
			<?php endif; ?>
			<?php foreach ( $occasions as $id => $occasion ) : ?>
				<?php
				if ( is_array( $occasion ) ) {
					blueline_settings_render_occasion_row( $name, (string) $id, $occasion, $inputs_hash, $acknowledgements );
				}
				?>
			<?php endforeach; ?>
		</ul>

		<p class="bl-occasions__toolbar">
			<label for="bl-occasions-preset-select"><?php esc_html_e( 'Add from preset', 'blueline' ); ?></label>
			<select id="bl-occasions-preset-select" data-bl-occasions-preset-select>
				<option value=""><?php esc_html_e( 'Choose a preset', 'blueline' ); ?></option>
				<?php foreach ( blueline_occasion_presets() as $preset_id => $preset ) : ?>
					<option
						value="<?php echo esc_attr( $preset_id ); ?>"
						data-bl-occasion-preset="<?php echo esc_attr( wp_json_encode( $preset ) ); ?>"
					>
						<?php echo esc_html( $preset['label'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" data-bl-occasions-add-preset>
				<?php esc_html_e( 'Add', 'blueline' ); ?>
			</button>
			<button type="button" class="button" data-bl-occasions-add-blank>
				<?php esc_html_e( 'Add a blank occasion', 'blueline' ); ?>
			</button>
		</p>

		<template data-bl-occasions-template>
			<?php
			blueline_settings_render_occasion_row(
				$name,
				'__TEMPLATE__',
				array(
					'id'     => '',
					'label'  => '',
					'type'   => 'decorative',
					'window' => array(
						'start_md' => '',
						'end_md'   => '',
					),
					'accent' => '',
					'motif'  => 'none',
					'line'   => '',
					'mode'   => 'auto',
				),
				$inputs_hash,
				array()
			);
			?>
		</template>
	</div>
	<?php
}

/**
 * Render one occasion's row: every Task-1-shaped field, the AA-override
 * checkbox+notice (design spec §5.1's fifth ruling), and a
 * server-computed contrast readout that is already correct even with
 * no JS at all.
 *
 * @param string                              $name             The `occasions` field's base POST name, e.g. `blueline_settings[occasions]`.
 * @param string                              $row_key          This row's per-request array key: a real occasion's own id for an existing row, or `__TEMPLATE__` for the `<template>` a later task's JS clones.
 * @param array<string, mixed>                $occasion         A Task-1-shaped Occasion (or the blank template shape above).
 * @param string                              $inputs_hash      blueline_settings_inputs_hash()'s current value.
 * @param array<string, array<string, mixed>> $acknowledgements blueline_stored_acknowledgements()'s current value.
 * @return void
 */
function blueline_settings_render_occasion_row( string $name, string $row_key, array $occasion, string $inputs_hash, array $acknowledgements ): void {
	$id     = (string) ( $occasion['id'] ?? '' );
	$label  = (string) ( $occasion['label'] ?? '' );
	$type   = (string) ( $occasion['type'] ?? 'decorative' );
	$start  = (string) ( $occasion['window']['start_md'] ?? '' );
	$end    = (string) ( $occasion['window']['end_md'] ?? '' );
	$accent = (string) ( $occasion['accent'] ?? '' );
	$motif  = (string) ( $occasion['motif'] ?? 'none' );
	$line   = (string) ( $occasion['line'] ?? '' );
	$mode   = (string) ( $occasion['mode'] ?? 'auto' );

	$resolved_accent = '' !== $accent ? blueline_sanitize_hex_color( $accent ) : blueline_occasion_accent_default();
	$swatch_accent   = '' !== $resolved_accent ? $resolved_accent : BLUELINE_TOKEN_INK;

	$ratio  = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $swatch_accent );
	$passes = $ratio >= blueline_contrast_threshold( 'body' );

	$already_acknowledged = '' !== $id && blueline_acknowledgement_covers(
		$acknowledgements,
		'occasion:' . $id,
		'ink-on-occasion-accent',
		$swatch_accent,
		$inputs_hash
	);

	$base    = $name . '[' . $row_key . ']';
	$row_uid = 'bl-occasion-' . sanitize_html_class( '' !== $row_key ? $row_key : 'row' );
	?>
	<li class="bl-occasions__row" data-bl-occasion-row>
		<input
			type="hidden"
			name="<?php echo esc_attr( $base . '[_original_id]' ); ?>" value="<?php echo esc_attr( $id ); ?>"
			data-bl-occasion-original-id
		>

		<p class="bl-occasions__slug">
			<?php esc_html_e( 'ID:', 'blueline' ); ?>
			<code data-bl-occasion-slug-preview><?php echo esc_html( '' !== $id ? $id : __( '(new, named from its label)', 'blueline' ) ); ?></code>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-label' ); ?>"><?php esc_html_e( 'Label', 'blueline' ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $row_uid . '-label' ); ?>"
				name="<?php echo esc_attr( $base . '[label]' ); ?>"
				value="<?php echo esc_attr( $label ); ?>"
				data-bl-occasion-label
			>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-type' ); ?>"><?php esc_html_e( 'Type', 'blueline' ); ?></label>
			<select id="<?php echo esc_attr( $row_uid . '-type' ); ?>" name="<?php echo esc_attr( $base . '[type]' ); ?>" data-bl-occasion-type>
				<?php foreach ( blueline_occasion_types() as $type_choice ) : ?>
					<option value="<?php echo esc_attr( $type_choice ); ?>" <?php selected( $type, $type_choice ); ?>>
						<?php echo esc_html( blueline_occasion_type_label( $type_choice ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-start' ); ?>"><?php esc_html_e( 'Start (MM-DD)', 'blueline' ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $row_uid . '-start' ); ?>"
				name="<?php echo esc_attr( $base . '[window][start_md]' ); ?>"
				value="<?php echo esc_attr( $start ); ?>"
				pattern="\d{2}-\d{2}"
				placeholder="MM-DD"
				data-bl-occasion-window-start
			>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-end' ); ?>"><?php esc_html_e( 'End (MM-DD)', 'blueline' ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $row_uid . '-end' ); ?>"
				name="<?php echo esc_attr( $base . '[window][end_md]' ); ?>"
				value="<?php echo esc_attr( $end ); ?>"
				pattern="\d{2}-\d{2}"
				placeholder="MM-DD"
				data-bl-occasion-window-end
			>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-accent' ); ?>"><?php esc_html_e( 'Accent colour (hex, blank for the theme default)', 'blueline' ); ?></label>
			<input type="color" value="<?php echo esc_attr( $swatch_accent ); ?>" data-bl-occasion-color tabindex="-1" aria-hidden="true">
			<input
				type="text"
				id="<?php echo esc_attr( $row_uid . '-accent' ); ?>"
				name="<?php echo esc_attr( $base . '[accent]' ); ?>"
				value="<?php echo esc_attr( $accent ); ?>"
				placeholder="#rrggbb"
				data-bl-occasion-accent
			>
		</p>

		<p class="bl-occasions__contrast" data-bl-occasion-contrast aria-live="polite">
			<?php
			printf(
				/* translators: 1: a contrast ratio like "4.5:1", 2: "passes AA" or "fails AA". */
				esc_html__( 'Contrast against body text: %1$s (%2$s)', 'blueline' ),
				esc_html( number_format( $ratio, 1 ) . ':1' ),
				esc_html( $passes ? __( 'passes AA', 'blueline' ) : __( 'fails AA', 'blueline' ) )
			);
			?>
		</p>

		<section class="notice notice-warning bl-occasions__aa-notice" data-bl-occasion-aa-notice<?php echo $passes ? ' hidden' : ''; ?>>
			<p>
				<?php
				echo esc_html(
					__( 'This accent does not meet the AA contrast requirement against body text. Checking the box below ships it anyway. Leaving it unchecked means this occasion will not activate until the colour passes, or this box is checked and saved.', 'blueline' )
				);
				?>
			</p>
			<label>
				<input
					type="checkbox"
					name="<?php echo esc_attr( $base . '[override_aa]' ); ?>"
					value="1"
					data-bl-occasion-override
					<?php checked( $already_acknowledged ); ?>
				>
				<?php esc_html_e( 'Yes, ship this colour despite the failing contrast', 'blueline' ); ?>
			</label>
		</section>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-motif' ); ?>"><?php esc_html_e( 'Motif', 'blueline' ); ?></label>
			<select id="<?php echo esc_attr( $row_uid . '-motif' ); ?>" name="<?php echo esc_attr( $base . '[motif]' ); ?>" data-bl-occasion-motif>
				<?php foreach ( blueline_occasion_motifs() as $motif_choice ) : ?>
					<option value="<?php echo esc_attr( $motif_choice ); ?>" <?php selected( $motif, $motif_choice ); ?>>
						<?php echo esc_html( blueline_occasion_motif_label( $motif_choice ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-line' ); ?>"><?php esc_html_e( 'Optional line of copy', 'blueline' ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $row_uid . '-line' ); ?>"
				name="<?php echo esc_attr( $base . '[line]' ); ?>"
				value="<?php echo esc_attr( $line ); ?>"
				data-bl-occasion-line
			>
		</p>

		<p>
			<label for="<?php echo esc_attr( $row_uid . '-mode' ); ?>"><?php esc_html_e( 'Mode', 'blueline' ); ?></label>
			<select id="<?php echo esc_attr( $row_uid . '-mode' ); ?>" name="<?php echo esc_attr( $base . '[mode]' ); ?>" data-bl-occasion-mode>
				<?php foreach ( blueline_occasion_modes() as $mode_choice ) : ?>
					<option value="<?php echo esc_attr( $mode_choice ); ?>" <?php selected( $mode, $mode_choice ); ?>>
						<?php echo esc_html( blueline_occasion_mode_label( $mode_choice ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<button type="button" class="button-link bl-occasions__remove" data-bl-occasion-remove>
			<?php esc_html_e( 'Remove', 'blueline' ); ?>
		</button>
	</li>
	<?php
}

/**
 * Human-readable label for an occasion `type` value.
 *
 * @param string $type A blueline_occasion_types() value.
 * @return string
 */
function blueline_occasion_type_label( string $type ): string {
	$labels = array(
		'decorative'    => __( 'Decorative', 'blueline' ),
		'commemorative' => __( 'Commemorative', 'blueline' ),
	);

	return $labels[ $type ] ?? $type;
}

/**
 * Human-readable label for an occasion `motif` value.
 *
 * @param string $motif A blueline_occasion_motifs() value.
 * @return string
 */
function blueline_occasion_motif_label( string $motif ): string {
	$labels = array(
		'none'       => __( 'None', 'blueline' ),
		'maple-leaf' => __( 'Maple leaf', 'blueline' ),
		'poppy'      => __( 'Poppy', 'blueline' ),
		'snowflake'  => __( 'Snowflake', 'blueline' ),
		'sparkle'    => __( 'Sparkle', 'blueline' ),
	);

	return $labels[ $motif ] ?? $motif;
}

/**
 * Human-readable label for an occasion `mode` value.
 *
 * @param string $mode A blueline_occasion_modes() value.
 * @return string
 */
function blueline_occasion_mode_label( string $mode ): string {
	$labels = array(
		'auto'      => __( 'Automatic, during its window', 'blueline' ),
		'force_on'  => __( 'Always on (preview now)', 'blueline' ),
		'force_off' => __( 'Always off', 'blueline' ),
	);

	return $labels[ $mode ] ?? $mode;
}
