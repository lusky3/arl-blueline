<?php
/**
 * Appearance -> Blueline: the page itself. Tab slugs, labels and URLs, the
 * field-error lookups, and blueline_settings_render_page().
 *
 * Loaded by inc/settings/page.php, whose docblock holds the constraints
 * this part follows.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The distinct `tab` values the schema declares, in first-seen order,
 * deliberately derived from blueline_settings_schema() rather than a
 * hardcoded list, so adding a new tab (e.g. a Sections tab) does not
 * also require editing this file.
 *
 * @return string[]
 */
function blueline_settings_tab_slugs(): array {
	$slugs = array();
	foreach ( blueline_settings_schema() as $field ) {
		$tab = $field['tab'] ?? '';
		if ( '' !== $tab && ! in_array( $tab, $slugs, true ) ) {
			$slugs[] = $tab;
		}
	}

	// One explicit, named exception:
	// `occasions` has zero schema fields of its own; it is a reserved
	// settings key (BLUELINE_SETTINGS_RESERVED_KEYS), not a
	// `type => 'occasions'` schema entry. Every other tab above is still
	// 100% schema-derived; this is the one deliberate exception, not a
	// general "custom tabs" registration point nobody else needs.
	$slugs[] = 'occasions';

	return $slugs;
}

/**
 * Human-readable label for a tab slug. Known slugs get a proper label;
 * anything else (a future tab this file was not updated for) falls back to
 * a readable guess rather than rendering the raw slug or nothing at all.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return string
 */
function blueline_settings_tab_label( string $tab_slug ): string {
	$labels = array(
		'content'    => __( 'Content', 'blueline' ),
		'links'      => __( 'Links', 'blueline' ),
		'appearance' => __( 'Appearance', 'blueline' ),
		'sections'   => __( 'Sections', 'blueline' ),
		'commerce'   => __( 'Commerce', 'blueline' ),
		'occasions'  => __( 'Occasions', 'blueline' ),
	);

	return $labels[ $tab_slug ] ?? ucwords( str_replace( array( '-', '_' ), ' ', $tab_slug ) );
}

/**
 * Every schema field belonging to one tab, in schema order.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return array<string, array<string, mixed>>
 */
function blueline_settings_fields_for_tab( string $tab_slug ): array {
	return array_filter(
		blueline_settings_schema(),
		static fn( $field ) => ( $field['tab'] ?? '' ) === $tab_slug
	);
}

/**
 * The tab the current request should display: the requested `tab` query
 * var if it names a real tab, otherwise the first tab the schema declares.
 * Never trusts an unrecognised value, because there is no field-rendering
 * branch for an unknown tab, so falling through to it would render an empty
 * page rather than something confusing.
 *
 * @return string
 */
function blueline_settings_current_tab(): string {
	$tabs = blueline_settings_tab_slugs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector (which fields to display), not a state-changing request; validated against the known tab list below regardless.
	$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	return in_array( $requested, $tabs, true ) ? $requested : ( $tabs[0] ?? '' );
}

/**
 * The URL for one tab's link in the tab nav.
 *
 * @param string $tab_slug A value from blueline_settings_tab_slugs().
 * @return string
 */
function blueline_settings_tab_url( string $tab_slug ): string {
	return admin_url( 'themes.php?page=' . rawurlencode( BLUELINE_SETTINGS_PAGE_SLUG ) . '&tab=' . rawurlencode( $tab_slug ) );
}

/**
 * The HTML id of a field's input/select, shared between
 * blueline_settings_render_field() (which sets it) and the error summary
 * (which links to it), so the two can never drift apart into two different
 * ids for what is supposed to be the same element.
 *
 * @param string $field_key A blueline_settings_schema() key.
 * @return string
 */
function blueline_settings_field_input_id( string $field_key ): string {
	return 'blueline-field-' . $field_key;
}

/**
 * Whether one queued settings error belongs to a specific FIELD, i.e.
 * whether blueline_settings_field_errors() should claim it and the error
 * summary should link to it.
 *
 * Two conditions, and the second is the easy one to miss: the entry
 * must be an `error` (a success/info entry like "Settings saved." belongs
 * to no field), AND its code must actually name a schema field. The
 * summary renders a code as the field's label and links it to
 * `#blueline-field-{code}`, so an error whose code is NOT a field, a
 * failed snapshot restore for one, rendered a raw internal key as its
 * label and linked to an element that does not exist on the page. Those
 * entries belong in the page-level notice list instead, which
 * blueline_settings_non_field_messages() takes as its complement.
 *
 * @param array<string, mixed> $error One get_settings_errors() entry.
 * @return bool
 */
function blueline_settings_error_is_field_scoped( array $error ): bool {
	if ( 'error' !== ( $error['type'] ?? '' ) ) {
		return false;
	}

	return isset( blueline_settings_schema()[ $error['code'] ?? '' ] );
}

/**
 * Field-level error messages from the last save, keyed by field key; see
 * blueline_settings_error_is_field_scoped() for exactly which entries
 * qualify. Every message returned here was already run through esc_html()
 * at the point blueline_settings_sanitize_callback() called
 * add_settings_error() (see inc/settings/page.php's docblock); it is safe to echo
 * directly, never re-escape it.
 *
 * @return array<string, string> Field key => already-escaped message.
 */
function blueline_settings_field_errors(): array {
	$errors = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( blueline_settings_error_is_field_scoped( $error ) ) {
			$errors[ $error['code'] ] = $error['message'];
		}
	}
	return $errors;
}

/**
 * Every queued message that is NOT field-scoped: the generic "Settings
 * saved." success blueline_settings_maybe_flag_saved() queues, and any
 * page-level ERROR whose code names no field (a failed restore). The exact
 * complement of blueline_settings_field_errors(), so every queued entry is
 * rendered exactly once, by exactly one of the two. Already-escaped, same
 * guarantee as blueline_settings_field_errors().
 *
 * @return array<int, array{type:string, message:string}>
 */
function blueline_settings_non_field_messages(): array {
	$messages = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( ! blueline_settings_error_is_field_scoped( $error ) ) {
			$messages[] = array(
				'type'    => $error['type'] ?? 'updated',
				'message' => $error['message'],
			);
		}
	}
	return $messages;
}

/**
 * The page callback registered with add_theme_page(). Renders the tab nav,
 * an accessible error summary (moved into focus when present; see
 * below), any success/error notices, and the current tab's own <form>.
 *
 * Capability is checked again here (see blueline_settings_add_page()'s
 * docblock for why this is not redundant): a request that somehow reaches
 * this callback without `manage_options` is refused outright rather than
 * shown anything.
 *
 * @return void
 */
function blueline_settings_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'blueline' ) );
	}

	// Before anything is read for rendering: a restore rewrites the very
	// values the fields below are about to be filled from.
	blueline_settings_maybe_restore();

	// Same reason, same ordering requirement: an APPLIED import rewrites
	// them too. Its return value is the preview to render further down:
	// null when this request was not an import, or was one that applied
	// cleanly and has already queued its own message.
	$import_preview = blueline_settings_maybe_handle_import();

	blueline_settings_maybe_flag_saved();

	$tabs         = blueline_settings_tab_slugs();
	$current_tab  = blueline_settings_current_tab();
	$field_errors = blueline_settings_field_errors();
	$notices      = blueline_settings_non_field_messages();
	?>
	<div class="wrap bl-settings">
		<h1><?php echo esc_html( __( 'Blueline', 'blueline' ) ); ?></h1>

		<?php
		/*
		 * <section>, deliberately NOT a <div>. See inc/settings/page.php's
		 * docblock's "Never a <div> for the error summary" section: the
		 * same third-party plugin (Capabilities Pro's admin-notices
		 * "declutter" module) that swept the error summary from the DOM
		 * also removes any <div> whose class contains "notice", and a
		 * genuinely successful save's own "Settings saved." notice carries
		 * exactly that class. As a <div>, a real successful save rendered no
		 * feedback at all.
		 */
		?>
		<?php foreach ( $notices as $notice ) : ?>
			<section class="notice notice-<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : $notice['type'] ); ?>">
				<p><?php echo $notice['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_maybe_flag_saved() at the point add_settings_error() was called; re-escaping here would double-encode entities. ?></p>
			</section>
		<?php endforeach; ?>

		<?php
		/*
		 * tabindex="-1" makes this a valid focus target without adding it
		 * to the normal tab order; blueline_settings_maybe_enqueue_focus_script()
		 * actually moves focus here on page load, via an explicit .focus()
		 * call; see inc/settings/page.php's docblock and
		 * blueline_settings_focus_summary_script()'s docblock for why a
		 * plain HTML attribute alone was tried first and does not work in
		 * the target browser. This explanation is a PHP comment, not an
		 * HTML one, so it is never sent to the browser at all.
		 */
		?>
		<?php if ( ! empty( $field_errors ) ) : ?>
			<?php
			/*
			 * A <section>, deliberately NOT a <div>. See inc/settings/page.php's
			 * docblock's "Never a <div> for the error summary" section for
			 * why: a THIRD-PARTY plugin active on this install (Capabilities Pro's
			 * admin-notices module) removes every <div> whose class
			 * attribute contains "notice", "error", "warning", "info" or
			 * "updated" as a SUBSTRING, anywhere on any wp-admin screen, as
			 * part of its own notice-decluttering feature. This element's
			 * classes (kept for their free WP-admin `.notice`/`.notice-error`
			 * styling, itself a plain class selector with no tag
			 * qualifier) match that pattern exactly, so as a <div> it was
			 * removed from the DOM by that plugin on every real page load,
			 * despite this file's own PHP emitting it correctly every
			 * time; confirmed by inspecting the raw HTTP response body of
			 * an actual failed save, which DID contain it. A <section>
			 * (with the same classes, so identical styling) is never
			 * selected by that plugin's `div[...]` jQuery selector, and
			 * `role="alert"` below already overrides its implicit ARIA
			 * role, so nothing about the accessible semantics changes.
			 */
			?>
			<section
				id="blueline-settings-error-summary"
				class="notice notice-error bl-settings-error-summary"
				tabindex="-1"
				role="alert"
			>
				<h2><?php echo esc_html( __( 'There is a problem', 'blueline' ) ); ?></h2>
				<ul>
					<?php foreach ( $field_errors as $field_key => $message ) : ?>
						<?php $field_label = blueline_settings_schema()[ $field_key ]['label'] ?? $field_key; ?>
						<li>
							<a href="#<?php echo esc_attr( blueline_settings_field_input_id( $field_key ) ); ?>">
								<?php echo esc_html( $field_label ); ?>:
								<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_sanitize_callback() at the point add_settings_error() was called (see this file's docblock); re-escaping here would double-encode entities such as turning "&lt;" into "&amp;lt;". ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Blueline settings', 'blueline' ); ?>">
			<?php foreach ( $tabs as $tab_slug ) : ?>
				<a
					href="<?php echo esc_url( blueline_settings_tab_url( $tab_slug ) ); ?>"
					class="nav-tab<?php echo esc_attr( $tab_slug === $current_tab ? ' nav-tab-active' : '' ); ?>"
					<?php
					if ( $tab_slug === $current_tab ) :
						?>
						aria-current="page"<?php endif; ?>
				>
					<?php echo esc_html( blueline_settings_tab_label( $tab_slug ) ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( BLUELINE_SETTINGS_OPTION_GROUP ); ?>

			<!--
				`_tab` names which tab owns this submission. Every tab
				shares one settings_fields() nonce group, so the nonce
				alone cannot tell the sanitize callback that. Without it, a
				`_posted_fields` entry naming a field from a DIFFERENT tab
				could delete that field (see this file's own docblock).
			-->
			<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_tab]' ); ?>" value="<?php echo esc_attr( $current_tab ); ?>">

			<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
				<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_posted_fields][]' ); ?>" value="<?php echo esc_attr( $field_key ); ?>">
			<?php endforeach; ?>

			<?php if ( 'occasions' === $current_tab ) : ?>
				<?php
				/*
				 * One explicit, named
				 * exception in the page renderer, not a general "custom
				 * tabs" mechanism. blueline_settings_fields_for_tab(
				 * 'occasions' ) is always empty (no schema field ever
				 * declares tab => 'occasions'), so the generic loop below
				 * would render nothing useful for this tab anyway; this
				 * branch swaps it for a bespoke renderer instead.
				 */
				?>
				<?php blueline_settings_render_occasions_tab(); ?>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
							<?php blueline_settings_render_field( $field_key, $field, $field_errors[ $field_key ] ?? null ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php submit_button(); ?>
		</form>

		<?php blueline_settings_render_snapshots(); ?>

		<?php blueline_settings_render_data_tools( $import_preview ); ?>
	</div>
	<?php
}
