<?php
/**
 * Homepage: the hero band's photographs. The shipped band shots, the
 * configured photo sources, the resting-photo CSS and the rotation
 * script printed in <head>.
 *
 * Loaded by inc/homepage-modules.php.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The league photographs available as band texture.
 *
 * Six of Michael Durrant's league photographs, chosen for how they read at
 * ~16% opacity rather than how they read as photographs: open ice, legible
 * silhouettes, the subject off-centre so a headline is not sitting on top of
 * it. The portraits and tight group shots in the same set are deliberately
 * absent, because a face reads as a person even at that opacity, which is both
 * worse design here and a larger ask of the player in it.
 *
 * Re-encoded to 720px WebP (assets/images/bands, ~156KB for all six) because
 * nothing above that survives the treatment. Credit is rendered in the footer:
 * the photographer's watermark is illegible once desaturated to this level, so
 * the attribution has to live somewhere the treatment cannot destroy.
 *
 * @return string[] Slugs, each matching assets/images/bands/{slug}.webp.
 */
function blueline_band_shots(): array {
	return array( 'save', 'shot', 'skater', 'race', 'faceoff', 'breakaway' );
}

/**
 * Every photograph available to the hero band, as { url, position } pairs.
 *
 * The control panel's list wins when it has anything in it; an empty list
 * means the photographs that ship with the theme, which is what makes the
 * setting an override rather than a switch (see blueline_settings_schema()).
 *
 * Admin-chosen photographs are rendered at the `blueline-band` size, never at
 * their uploaded original; see inc/setup.php for why that matters. A chosen
 * attachment that has since been deleted simply drops out here rather than
 * emitting a url() pointing at nothing.
 *
 * @return array<int,array{url:string,position:string}> Possibly empty.
 */
function blueline_band_photo_sources(): array {
	$alignments = blueline_band_photo_alignments();

	$configured = blueline_settings( 'hero_photos' );
	$sources    = array();

	if ( is_array( $configured ) && $configured ) {
		foreach ( $configured as $row ) {
			$id = absint( $row['id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $id, 'blueline-band' );

			if ( ! $url ) {
				continue;
			}

			$align = (string) ( $row['align'] ?? 'center-center' );

			$sources[] = array(
				'url'      => (string) $url,
				'position' => $alignments[ $align ] ?? 'center 40%',
			);
		}
	}

	if ( $sources ) {
		return $sources;
	}

	foreach ( blueline_band_shots() as $slug ) {
		$sources[] = array(
			'url'      => BLUELINE_URI . '/assets/images/bands/' . $slug . '.webp',
			'position' => 'center 40%',
		);
	}

	return $sources;
}

/**
 * The declarations that set the hero band's resting photograph on :root.
 *
 * Its own function so the CSS has one author whichever way it reaches the page:
 * printed beside the band today, or handed to wp_add_inline_style() once the
 * homepage stylesheet's enqueue can say whether a band renders.
 *
 * @param array{url: string, position: string} $source One entry of blueline_band_photo_sources().
 * @return string CSS, already escaped for a <style> context.
 */
function blueline_band_photo_resting_css( array $source ): string {
	return sprintf(
		':root{--bl-band-photo:url("%1$s");--bl-band-photo-position:%2$s}',
		esc_url( $source['url'] ),
		esc_html( $source['position'] )
	);
}

/**
 * Print the hero band's photograph, and the rotation that picks it.
 *
 * WHY THIS IS INLINE AND WHY IT IS HERE. The site sits behind an nginx srcache
 * page cache in production (staging has none, see DESIGN.md), so a photograph
 * chosen in PHP is chosen once per cache fill, not once per visitor, so server-
 * side rotation would look perfect on staging and quietly never rotate in
 * production, which is precisely the class of divergence DESIGN.md already
 * warns about for the cache purge. Choosing in the browser is the only way
 * "different on each page load" can be true of a cached page.
 *
 * Printed immediately before the band itself rather than from wp_head: the
 * <style> establishes the resting value and the <script> overwrites it, both
 * parsed before the section that reads them, so there is no flash of the first
 * photograph being replaced. It also means no page that lacks a hero pays for
 * any of it.
 *
 * With JavaScript unavailable the <style> alone is a complete answer: a real
 * photograph, correctly aligned, chosen deterministically.
 *
 * Why neither block uses the enqueue APIs: the <script> must run before the
 * band paints, and an enqueued or wp_add_inline_script() script runs after
 * its handle, which is after first paint when deferred. The <script> goes
 * through wp_print_inline_script_tag() so CSP nonce plugins still see it.
 * WordPress has no print equivalent for a <style>, and
 * wp_add_inline_style() only reaches a handle that has not printed yet, so
 * the <style> stays a literal tag until the stylesheet enqueue can decide
 * early whether a band will render (see blueline_band_photo_resting_css()).
 *
 * @return void
 */
function blueline_render_band_photo_head(): void {
	$sources = blueline_band_photo_sources();

	if ( ! $sources ) {
		return;
	}

	$rotate = (bool) blueline_settings( 'hero_photo_rotate' );

	// The resting choice is deterministic rather than the first in the list, so
	// a no-JS visitor and a cache fill do not both always land on the same one.
	$resting = $sources[ crc32( 'hero' ) % count( $sources ) ];

	echo '<style id="bl-band-photo">' . blueline_band_photo_resting_css( $resting ) . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside blueline_band_photo_resting_css().

	if ( ! $rotate || count( $sources ) < 2 ) {
		return;
	}

	/*
	 * wp_json_encode() and not manual quoting: these strings become JavaScript
	 * source, where esc_url()/esc_attr() are the wrong escaping entirely, because a
	 * quote or backslash in a filename would end the string literal and
	 * everything after it becomes code.
	 */
	$payload = wp_json_encode( array_values( $sources ) );

	if ( ! $payload ) {
		return;
	}

	wp_print_inline_script_tag(
		'(function(){try{var s=' . $payload . ',p=s[Math.floor(Math.random()*s.length)],r=document.documentElement.style;r.setProperty("--bl-band-photo","url(\'"+p.url+"\')");r.setProperty("--bl-band-photo-position",p.position);}catch(e){}})();',
		array( 'id' => 'bl-band-photo-rotate' )
	);
}
