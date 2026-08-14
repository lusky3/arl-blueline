<?php
/**
 * Site Health: a `blueline` section in Tools -> Site Health -> Info, via
 * core's `debug_information` filter.
 *
 * Registered unconditionally at file scope, exactly like every other filter
 * this settings module wires (inc/settings/page.php's sanitize_option_
 * callback, inc/settings/store.php's pre_update_option_ merge) -- it costs
 * nothing when nothing calls WP_Debug_Data::debug_data(), and doing so
 * means this section appears both on the interactive admin screen AND in
 * the "Copy site info to clipboard" export a volunteer might paste into a
 * support request, which is exactly why the one field below shaped like
 * contact information is marked `private => true`: core's own Site Health
 * screen renders `private` fields on-screen for the logged-in admin but
 * excludes them from that copy-paste export, so a support paste can never
 * leak it.
 *
 * What's reported, and why each is useful for support/debugging rather than
 * just decorative:
 *
 * - Schema version: the option's actual stored `_schema` next to the
 *   version this code understands -- a mismatch (stored newer than code)
 *   is exactly the forward-only-migration refusal
 *   inc/settings/store.php's blueline_settings_migrate() guards against,
 *   and is otherwise invisible without reading the database directly.
 * - How many fields differ from their default -- a quick "has anyone
 *   actually configured this panel" signal, without dumping every value.
 * - Which Links-tab fields are configured vs falling back to their
 *   built-in path -- the exact distinction inc/settings/links.php's
 *   blueline_resolve_link() makes at render time, surfaced here so a
 *   support request can be answered without a database query.
 * - Whether the guarded Redis/nginx srcache purge (inc/settings/cache.php)
 *   is switched on, and whether a manual purge is currently pending --
 *   the two facts that file's own docblock says are otherwise easy to lose
 *   track of on a volunteer-run site.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'debug_information', 'blueline_site_health_debug_information' );
/**
 * Add a `blueline` section to Site Health's `debug_information` data.
 *
 * @param array<string, array<string, mixed>> $info Existing Site Health sections.
 * @return array<string, array<string, mixed>>
 */
function blueline_site_health_debug_information( array $info ): array {
	$schema   = blueline_settings_schema();
	$defaults = blueline_settings_defaults();
	$current  = blueline_settings();

	$stored        = get_option( BLUELINE_SETTINGS_OPTION, array() );
	$stored        = is_array( $stored ) ? $stored : array();
	$stored_schema = isset( $stored['_schema'] ) ? (int) $stored['_schema'] : 0;

	$fields = array(
		'schema_version'        => array(
			'label' => __( 'Settings schema version', 'blueline' ),
			'value' => sprintf(
				/* translators: 1: the stored schema version, 2: the schema version this theme's code understands. */
				__( '%1$d (theme code understands %2$d)', 'blueline' ),
				$stored_schema,
				BLUELINE_SETTINGS_SCHEMA_VERSION
			),
		),
		'fields_overridden'     => array(
			'label' => __( 'Fields overriding a default', 'blueline' ),
			'value' => sprintf(
				/* translators: 1: number of fields whose stored value differs from its default, 2: total number of schema fields. */
				__( '%1$d of %2$d', 'blueline' ),
				blueline_site_health_count_overridden_fields( $current, $defaults ),
				count( $defaults )
			),
		),
		'srcache_purge_enabled' => array(
			'label' => __( 'Automatic page-cache purge on save', 'blueline' ),
			'value' => BLUELINE_SRCACHE_PURGE
				? __( 'Enabled', 'blueline' )
				: __( 'Disabled (admins see a manual-purge notice instead)', 'blueline' ),
		),
		'manual_purge_pending'  => array(
			'label' => __( 'Manual cache purge pending', 'blueline' ),
			'value' => blueline_cache_purge_needed() ? __( 'Yes', 'blueline' ) : __( 'No', 'blueline' ),
		),
	);

	foreach ( $schema as $key => $field ) {
		if ( 'links' !== ( $field['tab'] ?? '' ) ) {
			continue;
		}

		$fields[ 'link_' . $key ] = blueline_site_health_link_field( $key, $field, $current );
	}

	// contact_email is the one field shaped like personal/contact
	// information -- marked private so it stays out of a pasted support
	// dump (see this file's own docblock) while still showing on-screen to
	// the logged-in admin who is looking directly at Site Health.
	if ( isset( $current['contact_email'] ) ) {
		$fields['contact_email'] = array(
			'label'   => __( 'Contact email', 'blueline' ),
			'value'   => (string) $current['contact_email'],
			'private' => true,
		);
	}

	$info['blueline'] = array(
		'label'  => __( 'Blueline', 'blueline' ),
		'fields' => $fields,
	);

	return $info;
}

/**
 * How many schema fields' stored value differs from its default. Pure: no
 * WordPress calls, so it's trivially unit testable.
 *
 * @param array<string, mixed> $current  blueline_settings() -- current values.
 * @param array<string, mixed> $defaults blueline_settings_defaults().
 * @return int
 */
function blueline_site_health_count_overridden_fields( array $current, array $defaults ): int {
	$count = 0;

	foreach ( $defaults as $key => $default_value ) {
		if ( ( $current[ $key ] ?? null ) !== $default_value ) {
			++$count;
		}
	}

	return $count;
}

/**
 * One Site Health field row for a single Links-tab schema key: whether the
 * configured page is actually in play, or the schema's own built-in
 * fallback path is being used instead -- the identical distinction
 * inc/settings/links.php's blueline_resolve_link() makes when a template
 * asks for the real URL, just reported here instead of rendered.
 *
 * @param string               $key     Schema key (e.g. `page_schedule`).
 * @param array<string, mixed> $field   The field's own schema entry.
 * @param array<string, mixed> $current blueline_settings() -- current values.
 * @return array{label: string, value: string}
 */
function blueline_site_health_link_field( string $key, array $field, array $current ): array {
	$id          = (int) ( $current[ $key ] ?? 0 );
	$is_fallback = ! ( $id > 0 && 'publish' === get_post_status( $id ) );

	return array(
		'label' => $field['label'] ?? $key,
		'value' => $is_fallback
			? sprintf(
				/* translators: %s: the built-in fallback path (e.g. "/schedule"). */
				__( 'Falling back to %s (no page configured, or the configured page is missing/unpublished)', 'blueline' ),
				$field['fallback'] ?? '/'
			)
			: sprintf(
				/* translators: %d: the configured page's post ID. */
				__( 'Configured (page #%d)', 'blueline' ),
				$id
			),
	);
}
