<?php
/**
 * Link resolution: turns a Links-tab schema key into the URL a visitor
 * should actually be sent to.
 *
 * The trap this file exists to close: get_permalink() does NOT check
 * post_status. Passed the ID of a TRASHED page, it still returns a
 * plausible, well-formed, non-`false` URL -- one that 404s the moment a
 * visitor clicks it, because the page it points at is gone from public
 * view. A naive resolver ("if get_permalink() didn't return false, use it")
 * would treat that plausible URL as good and ship exactly the bug class
 * this control panel exists to prevent -- reintroduced through the panel
 * itself. The theme already shipped one live 404 this way (`/contact-us`,
 * fixed in af381c3, see tests/ContactUrlTest.php).
 *
 * The fix is to check publish status explicitly, not merely "does
 * get_permalink() return something falsy": `get_post_status( $id )` returns
 * `false` for an ID that is gone entirely, and a non-`publish` string
 * (`trash`, `draft`, `pending`, `private`, `future`, ...) for a page that
 * exists but is not visitor-visible. Only `'publish' === get_post_status()`
 * is safe to link to; every other outcome -- unset, deleted, or any
 * non-published status -- falls back to the schema's own built-in path.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve a Links-tab schema key to the URL a visitor should be sent to:
 * the configured page's real permalink if (and only if) that page exists
 * and is published, otherwise the schema's built-in fallback path.
 *
 * @param string $key Schema key of a `page_id` field (e.g. `page_schedule`).
 * @return string Absolute URL.
 */
function blueline_resolve_link( string $key ): string {
	$schema = blueline_settings_schema();
	$field  = $schema[ $key ] ?? array();
	$id     = (int) blueline_settings( $key );

	if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
		$url = get_permalink( $id );
		if ( false !== $url ) {
			return $url;
		}
	}

	return home_url( $field['fallback'] ?? '/' );
}
