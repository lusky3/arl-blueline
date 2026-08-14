<?php
/**
 * Appearance -> Blueline: the Settings-API admin page every earlier task in
 * this plan has been building toward. This is the first task that produces
 * something a human can actually open and use.
 *
 * Deliberately plain WordPress Settings API, no React, no REST endpoint, no
 * webpack entry -- the theme has zero React anywhere else, and the Settings
 * API already supplies, for free, exactly what this page needs: a nonce
 * (settings_fields()), a capability check on the actual save request
 * (options.php itself refuses a non-manage_options user before this file's
 * code ever runs), and the sanitize/merge pipeline Tasks 2-6 already built
 * (blueline_settings_sanitize_callback() below wires onto
 * sanitize_option_{$option} UNCONDITIONALLY, at file scope -- see "Every
 * write path is validated" below for why that is not the same thing as
 * register_setting()'s own sanitize_callback wiring -- and
 * inc/settings/store.php's blueline_settings_merge() already lives on
 * pre_update_option_{$option}).
 *
 * ## Every write path is validated, not just wp-admin's
 *
 * `admin_init` -- the hook register_setting() runs on -- never fires for
 * WP-CLI or for a script calling update_option() directly. If
 * `sanitize_option_{$option}` were wired ONLY via register_setting()'s
 * `sanitize_callback` argument (i.e. only inside an admin_init-hooked
 * function), every write reachable outside wp-admin would bypass
 * blueline_sanitize_field() entirely -- including the placeholder contract
 * inc/settings/sanitize.php exists to enforce, silently reintroducing the
 * exact sprintf()-format-string fatal that file was built to prevent, the
 * moment a `wp option update`/import script carries a stray "%". This
 * theme's own blueline_settings_merge() (inc/settings/store.php) is
 * already registered unconditionally at file scope for exactly this
 * reason; the line below mirrors that pattern for validation. This is why
 * blueline_settings_register() (still admin_init-hooked, purely for the
 * Settings API's own UI/whitelist wiring) deliberately does NOT pass a
 * `sanitize_callback` in its register_setting() call -- the filter below
 * is the only place that argument would ever be wired from, and wiring it
 * twice would be redundant at best.
 *
 * Tabs are plain links (?page=blueline&tab=content), NOT an ARIA tab
 * widget: a real page load per tab is simpler, linkable, back-button
 * correct, and does not need any JavaScript at all. Each tab's <form>
 * posts only its own fields to options.php, exactly the shape
 * blueline_settings_merge() was built to receive.
 *
 * ## Escaping -- load-bearing, not a nicety
 *
 * blueline_sanitize_field() (inc/settings/sanitize.php) returns a WP_Error
 * whose message INTERPOLATES the admin's own input: the offending
 * conversion spec extracted from the submitted value, and a corrected
 * string built from it. WordPress' own add_settings_error() documents that
 * whatever renders a settings error echoes `$message` WITHOUT escaping --
 * escaping is the caller's job. Every single WP_Error message this file
 * hands to add_settings_error() is therefore run through esc_html() at the
 * exact point it is handed over (blueline_settings_sanitize_callback()
 * below), never later, and never left to whatever eventually reads it back.
 * Skipping that step is stored XSS: any admin who can reach this page could
 * submit a value whose rejection message reflects HTML-significant
 * characters straight into another admin's browser session.
 *
 * Every render function in this file that echoes a message already stored
 * via add_settings_error() (blueline_settings_render_page(),
 * blueline_settings_render_field()) echoes it RAW, with a line-level
 * phpcs:ignore explaining why: escaping again at that point would
 * double-encode entities the sanitize callback already escaped once.
 *
 * ## `_posted_fields` -- required, not optional, and tab-scoped
 *
 * blueline_settings_merge() treats a field absent from a submission as
 * "belongs to another tab, carry it forward" UNLESS that field's key is
 * named in the submission's reserved `_posted_fields` array, in which case
 * absence means "this tab owns this field and the user cleared it" (a
 * deliberate delete). Every tab rendered here therefore emits one hidden
 * `_posted_fields[]` input per field it owns (blueline_settings_render_page()),
 * regardless of whether that specific field currently has a value to clear
 * -- omitting this array does not merely miss an edge case, it makes
 * clearing ANY of this tab's fields silently revert on the very next save,
 * from ANY tab, forever.
 *
 * `_posted_fields` alone is not enough, though: every tab shares one
 * settings_fields() nonce group, so the nonce does not bind a submission
 * to any particular tab. Without a further check, a request merely SHAPED
 * like the Content tab's form -- but naming a Links-tab field (e.g.
 * `page_faqs`) in `_posted_fields` without posting that field's own value
 * -- would make the merge read that absence as "owned but omitted --
 * delete", clearing a field the submission never rendered and does not
 * own. Every tab's form therefore also emits a hidden `_tab` input naming
 * the tab actually being submitted, and blueline_settings_sanitize_callback()
 * drops any `_posted_fields` entry whose OWN schema `tab` does not match
 * it -- so naming a foreign field only ever fails silently, never deletes
 * it.
 *
 * ## Never an autoload argument
 *
 * Nothing in this file calls update_option()/register_setting() with an
 * autoload argument. `'auto'` is not a valid update_option() input -- it is
 * an internal DB state, and passing the literal string would likely coerce
 * to `true`. The Settings API's own options.php handler already calls
 * update_option( $option, $value ) with no third argument, which is exactly
 * what register_setting() below relies on to get this for free.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Menu slug this page is registered under (Appearance -> Blueline).
 */
const BLUELINE_SETTINGS_PAGE_SLUG = 'blueline';

/**
 * Settings-API group name passed to register_setting()/settings_fields().
 * Arbitrary but must match between the two -- see blueline_settings_register()
 * and the <form> settings_fields() call in blueline_settings_render_page().
 */
const BLUELINE_SETTINGS_OPTION_GROUP = 'blueline_settings_group';

add_action( 'admin_menu', 'blueline_settings_add_page' );
/**
 * Register "Appearance -> Blueline". `manage_options` here is the
 * menu-level gate (WordPress hides the submenu item entirely for anyone
 * without it); blueline_settings_render_page() carries its own copy of the
 * same check, so a direct hit on the URL cannot bypass it even if this
 * registration were ever changed to a looser capability by mistake.
 *
 * @return void
 */
function blueline_settings_add_page(): void {
	add_theme_page(
		__( 'Blueline', 'blueline' ),
		__( 'Blueline', 'blueline' ),
		'manage_options',
		BLUELINE_SETTINGS_PAGE_SLUG,
		'blueline_settings_render_page'
	);
}

add_action( 'admin_init', 'blueline_settings_register' );
/**
 * Wire BLUELINE_SETTINGS_OPTION into the Settings API's UI/whitelist
 * machinery -- what makes options.php (WordPress core, not this theme)
 * accept a POST to this option at all from a settings_fields()-rendered
 * form. Deliberately does NOT pass a `sanitize_callback`: that would wire
 * blueline_settings_sanitize_callback() onto `sanitize_option_{$option}`
 * only while `admin_init` has fired, which WP-CLI and a direct
 * update_option() call from a script never do. The unconditional,
 * file-scope add_filter() a few lines below this function is what actually
 * wires validation, on every path -- see this file's own docblock ("Every
 * write path is validated") for why that distinction matters.
 *
 * @return void
 */
function blueline_settings_register(): void {
	register_setting(
		BLUELINE_SETTINGS_OPTION_GROUP,
		BLUELINE_SETTINGS_OPTION,
		array(
			'type'    => 'array',
			'default' => blueline_settings_defaults(),
		)
	);
}

add_filter( 'sanitize_option_' . BLUELINE_SETTINGS_OPTION, 'blueline_settings_sanitize_callback' );
/**
 * The sanitize_option_{$option} callback for BLUELINE_SETTINGS_OPTION --
 * registered UNCONDITIONALLY above, at file scope, exactly the way
 * inc/settings/store.php registers blueline_settings_merge() on
 * `pre_update_option_{$option}` -- so this runs on every write reachable
 * through update_option(), not only ones that pass through wp-admin's
 * `admin_init`. This is the single choke point every save passes through,
 * immediately before blueline_settings_merge() runs.
 *
 * Three responsibilities, each described in this file's own docblock in
 * more depth:
 *
 * 1. Validate every posted field with blueline_sanitize_field(). A field
 *    that fails keeps its EXISTING stored value rather than the rejected
 *    one -- one bad field cannot corrupt the option, and every other field
 *    in the same submission still saves normally.
 * 2. Escape every WP_Error message with esc_html() before it is handed to
 *    add_settings_error() -- see this file's docblock's Escaping section.
 * 3. Forward `_posted_fields` (filtered to known schema keys AND to keys
 *    whose OWN schema `tab` matches the submission's `_tab`) so
 *    blueline_settings_merge() can tell "this tab cleared a field it
 *    owns" from "this field belongs to an untouched tab". A submission
 *    naming a foreign tab's field is not honoured for that field -- see
 *    this file's docblock's `_posted_fields` section.
 *
 * @param mixed $input Raw value from $_POST[BLUELINE_SETTINGS_OPTION], as
 *                      WordPress' sanitize_option_{$option} filter hands it
 *                      to us -- only the keys THIS submission posted.
 * @return array<string, mixed> The value to store, before
 *                               blueline_settings_merge() carries forward
 *                               whatever belongs to other tabs.
 */
function blueline_settings_sanitize_callback( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$schema  = blueline_settings_schema();
	$current = blueline_settings();

	$submitted_tab = isset( $input['_tab'] ) ? (string) $input['_tab'] : '';

	$posted_fields = array();
	if ( isset( $input['_posted_fields'] ) && is_array( $input['_posted_fields'] ) ) {
		foreach ( $input['_posted_fields'] as $posted_key ) {
			$posted_key = (string) $posted_key;
			if ( ! isset( $schema[ $posted_key ] ) ) {
				continue; // Not a real field at all.
			}
			if ( ( $schema[ $posted_key ]['tab'] ?? '' ) !== $submitted_tab ) {
				// Named by a submission that does not own it -- a request
				// merely SHAPED like another tab's form (or a tampered
				// one) naming a foreign field here must never be able to
				// delete it. Silently dropped, not honoured.
				continue;
			}
			$posted_fields[] = $posted_key;
		}
	}

	$output = array();

	foreach ( $input as $key => $value ) {
		if ( '_posted_fields' === $key || '_tab' === $key ) {
			// Reserved bookkeeping, both already consumed above -- neither
			// is ever persisted verbatim (`_tab` is request-scoped only;
			// `_posted_fields` is rebuilt, filtered, below).
			continue;
		}

		if ( ! isset( $schema[ $key ] ) ) {
			// Not a field this callback knows how to validate -- most
			// notably inc/settings/store.php's own `_schema` migration
			// bookkeeping key, which is deliberately NOT part of the
			// schema (see blueline_settings()'s own docblock) and must
			// still survive a write untouched. This function's job is to
			// validate the fields it DOES recognise, not to decide the
			// fate of data it doesn't -- silently dropping an unrecognised
			// key here would have broken blueline_settings_migrate()'s own
			// update_option() call the moment this callback started
			// running unconditionally (see this file's "Every write path
			// is validated" docblock section).
			$output[ $key ] = $value;
			continue;
		}

		$result = blueline_sanitize_field( $value, $schema[ $key ] );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				BLUELINE_SETTINGS_OPTION,
				$key,
				esc_html( $result->get_error_message() ),
				'error'
			);
			// Keep what is already stored -- never write the rejected value.
			$output[ $key ] = $current[ $key ] ?? null;
			continue;
		}

		$output[ $key ] = $result;
	}

	$output['_posted_fields'] = $posted_fields;

	return $output;
}

/**
 * If this is the redirect after a successful options.php save, queue a
 * generic "Settings saved." success message alongside any per-field errors
 * blueline_settings_sanitize_callback() may also have queued in the same
 * request -- a submission can partially succeed (some fields saved, one
 * rejected), and an admin needs to see both outcomes, not just one.
 *
 * @return void
 */
function blueline_settings_maybe_flag_saved(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag from options.php's own post-save redirect (that save was itself nonce-verified via settings_fields()), not a state-changing request of its own.
	if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
		add_settings_error(
			BLUELINE_SETTINGS_OPTION,
			'blueline_settings_saved',
			esc_html__( 'Settings saved.', 'blueline' ),
			'success'
		);
	}
}

/**
 * The distinct `tab` values the schema declares, in first-seen order --
 * deliberately derived from blueline_settings_schema() rather than a
 * hardcoded list, so a future task that adds a new tab (e.g. a Sections
 * tab) does not also have to remember to edit this file.
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
		'content'  => __( 'Content', 'blueline' ),
		'links'    => __( 'Links', 'blueline' ),
		'commerce' => __( 'Commerce', 'blueline' ),
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
 * Never trusts an unrecognised value -- there is no field-rendering branch
 * for an unknown tab, so falling through to it would render an empty page
 * rather than something confusing.
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
 * The HTML id of a field's input/select -- shared between
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
 * Field-level error messages from the last save, keyed by field key -- only
 * `error`-type entries, since success/info entries (e.g. "Settings saved.")
 * are not associated with any one field. Every message returned here was
 * already run through esc_html() at the point
 * blueline_settings_sanitize_callback() called add_settings_error() (see
 * this file's docblock); it is safe to echo directly, never re-escape it.
 *
 * @return array<string, string> Field key => already-escaped message.
 */
function blueline_settings_field_errors(): array {
	$errors = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( 'error' === ( $error['type'] ?? '' ) ) {
			$errors[ $error['code'] ] = $error['message'];
		}
	}
	return $errors;
}

/**
 * Non-field-specific messages from the last save (currently only the
 * generic "Settings saved." success message blueline_settings_maybe_flag_saved()
 * queues). Already-escaped, same guarantee as blueline_settings_field_errors().
 *
 * @return array<int, array{type:string, message:string}>
 */
function blueline_settings_non_field_messages(): array {
	$messages = array();
	foreach ( get_settings_errors( BLUELINE_SETTINGS_OPTION ) as $error ) {
		if ( 'error' !== ( $error['type'] ?? '' ) ) {
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
 * an accessible error summary (moved into focus when present -- see
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

	blueline_settings_maybe_flag_saved();

	$tabs         = blueline_settings_tab_slugs();
	$current_tab  = blueline_settings_current_tab();
	$field_errors = blueline_settings_field_errors();
	$notices      = blueline_settings_non_field_messages();
	?>
	<div class="wrap bl-settings">
		<h1><?php echo esc_html( __( 'Blueline', 'blueline' ) ); ?></h1>

		<?php foreach ( $notices as $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( 'success' === $notice['type'] ? 'success' : $notice['type'] ); ?>">
				<p><?php echo $notice['message']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_html()'d in blueline_settings_maybe_flag_saved() at the point add_settings_error() was called; re-escaping here would double-encode entities. ?></p>
			</div>
		<?php endforeach; ?>

		<?php if ( ! empty( $field_errors ) ) : ?>
			<!--
				tabindex="-1" + a global `autofocus` attribute is the
				zero-JavaScript way to move focus to this summary on the
				page load that follows a failed save (spec 6.5's "a save
				that fails must move focus to an error summary"): `autofocus`
				is a global HTML attribute, valid on any focusable element,
				not only form controls, and this attribute is only ever
				rendered when there is something to focus (never on an
				ordinary page load), so it cannot steal focus at any other
				time.
			-->
			<div
				id="blueline-settings-error-summary"
				class="notice notice-error bl-settings-error-summary"
				tabindex="-1"
				autofocus
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
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
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
		</h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php settings_fields( BLUELINE_SETTINGS_OPTION_GROUP ); ?>

			<!--
				`_tab` names which tab owns this submission -- every tab
				shares one settings_fields() nonce group, so the nonce
				alone cannot tell the sanitize callback that. Without it, a
				`_posted_fields` entry naming a field from a DIFFERENT tab
				could delete that field (see this file's own docblock).
			-->
			<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_tab]' ); ?>" value="<?php echo esc_attr( $current_tab ); ?>">

			<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
				<input type="hidden" name="<?php echo esc_attr( BLUELINE_SETTINGS_OPTION . '[_posted_fields][]' ); ?>" value="<?php echo esc_attr( $field_key ); ?>">
			<?php endforeach; ?>

			<table class="form-table" role="presentation">
				<tbody>
					<?php foreach ( blueline_settings_fields_for_tab( $current_tab ) as $field_key => $field ) : ?>
						<?php blueline_settings_render_field( $field_key, $field, $field_errors[ $field_key ] ?? null ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render one field's table row: a real `<label for>`, the input itself
 * (a wp_dropdown_pages()-style select for `page_id` fields -- an admin
 * picks "FAQs", never types a raw post ID -- a number input for `term_id`,
 * text/email otherwise), and, when this field failed the last save,
 * `aria-invalid`, `aria-describedby` and a visible error paragraph that
 * does not rely on colour alone (an explicit "Error:" prefix plus text, in
 * addition to the `bl-settings-field--error` class a stylesheet may use for
 * a colour treatment).
 *
 * `page_id`/`term_id` fields never fail validation -- blueline_sanitize_field()
 * sanitizes both with absint(), which cannot return a WP_Error -- so
 * neither branch below needs to handle an error state.
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
	?>
	<tr class="bl-settings-field<?php echo esc_attr( $has_error ? ' bl-settings-field--error' : '' ); ?>">
		<th scope="row">
			<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $label ); ?></label>
		</th>
		<td>
			<?php if ( 'page_id' === $type ) : ?>
				<?php
				wp_dropdown_pages(
					array(
						'name'              => esc_attr( $name ),
						'id'                => esc_attr( $input_id ),
						'selected'          => (int) $value,
						'show_option_none'  => esc_html__( '— Use built-in page —', 'blueline' ),
						'option_none_value' => 0,
					)
				);
				?>
			<?php elseif ( 'term_id' === $type ) : ?>
				<input
					type="number"
					min="0"
					step="1"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="small-text"
				>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( 'email' === $type ? 'email' : 'text' ); ?>"
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( (string) $value ); ?>"
					class="regular-text"
					<?php
					if ( $has_error ) :
						?>
						aria-invalid="true" aria-describedby="<?php echo esc_attr( $error_id ); ?>"<?php endif; ?>
				>
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
