<?php
/**
 * The site-wide announcement banner: one line of copy an admin can put
 * above every page for a bounded stretch of time.
 *
 * SITE-WIDE, not homepage-only. Most arrivals on this site are deep links
 * -- a schedule page or a single game shared into a team chat -- so an
 * announcement that only rendered on the homepage would miss most of the
 * people it is written for.
 *
 * This file also owns blueline_site_timestamp(), the one place the theme
 * turns an admin-entered wall-clock date into an instant. It lives here
 * because the announcement window is its first and main consumer; the
 * season-state override's expiry (inc/season-state.php) reads it too.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * The severity values the stylesheet has a treatment for. Anything else an
 * admin (or an import) manages to store resolves to the first entry --
 * blueline_announcement_severity() is the only read path, and it clamps.
 */
const BLUELINE_ANNOUNCEMENT_SEVERITIES = array( 'info', 'urgent' );

/**
 * Resolve a wall-clock datetime string to a Unix timestamp in the SITE's
 * own timezone, or null when the string cannot be parsed.
 *
 * Site time, not UTC, because every date in this panel is typed by a
 * volunteer thinking in local terms: "up until the 30th" means the 30th
 * where the league plays. Resolving those against UTC would move both ends
 * of every window by the site's own offset -- four or five hours here --
 * which is enough to take a banner down mid-evening on its last day.
 *
 * The empty string is rejected explicitly rather than left to the parser:
 * PHP's date parser reads '' (and a whitespace-only string) as "now", so an
 * unguarded empty bound would silently resolve to the current instant
 * instead of meaning "no bound at all", which is exactly what an empty
 * field in the panel does mean.
 *
 * @param string $datetime A wall-clock datetime, e.g. '2026-09-30 23:59:59'.
 * @return int|null Unix timestamp, or null if $datetime is empty or unparseable.
 */
function blueline_site_timestamp( string $datetime ): ?int {
	if ( '' === trim( $datetime ) ) {
		return null;
	}

	try {
		$moment = new DateTimeImmutable( $datetime, wp_timezone() );
	} catch ( Exception $unparseable ) {
		return null;
	}

	return $moment->getTimestamp();
}

/**
 * The announcement's severity, clamped to a value the stylesheet knows.
 *
 * The clamp is not cosmetic: this value is interpolated into the banner's
 * own class attribute as `bl-announce--{severity}`, so leaving it open
 * would put an arbitrary stored string into markup. Clamping at the single
 * read path means the render site cannot be handed anything unexpected in
 * the first place, independently of what the sanitizer accepted at save
 * time or what an import wrote straight to the option.
 *
 * @return string One of BLUELINE_ANNOUNCEMENT_SEVERITIES.
 */
function blueline_announcement_severity(): string {
	$stored = (string) blueline_settings( 'announcement_severity' );

	return in_array( $stored, BLUELINE_ANNOUNCEMENT_SEVERITIES, true )
		? $stored
		: BLUELINE_ANNOUNCEMENT_SEVERITIES[0];
}

/**
 * A short fingerprint of the announcement's own text, emitted as a data
 * attribute so assets/src/js/announcement.js can remember a dismissal
 * without needing a hashing implementation of its own.
 *
 * The point of keying on the text is that editing the announcement changes
 * the fingerprint, so the new one reappears for everyone who dismissed the
 * previous one -- a dismissal is "I have read THIS", never "stop showing me
 * announcements". md5 here is a content fingerprint, not a security
 * measure: nothing is authenticated by it, and a collision would only mean
 * one reader missing one banner.
 *
 * @param string $text The announcement text.
 * @return string Eight hex characters.
 */
function blueline_announcement_hash( string $text ): string {
	return substr( md5( $text ), 0, 8 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5 -- a content fingerprint for a dismissal key, not obfuscation or authentication; see this function's docblock.
}

/**
 * Whether the banner should render right now.
 *
 * Empty text is the off switch -- there is no separate "enabled" toggle to
 * fall out of step with the copy.
 *
 * Both bounds are optional and both are INCLUSIVE whole days in site time:
 * `announcement_from` opens at 00:00:00 on that date and `announcement_to`
 * closes at 23:59:59 on that one, so a volunteer typing "1 September to 30
 * September" gets both of those days in full.
 *
 * A bound that blueline_site_timestamp() cannot parse is treated as ABSENT
 * -- that end of the window stays open -- rather than as a reason to hide
 * the banner. The `date` sanitizer (inc/settings/sanitize.php) is the real
 * guard here: it refuses to store anything that is not a strict Y-m-d, so
 * an unparseable bound can only arrive by a direct database edit or an
 * import that bypassed the panel. Given that, the two failure modes are "a
 * banner the admin believes is live silently never appears" and "a banner
 * stays up past a date nobody can read anyway"; the second is visible and
 * fixable from the panel, the first is not.
 *
 * @param int|null $now Unix timestamp to evaluate against; defaults to the
 *                      current time. Tests pass this so an assertion about
 *                      a window keeps meaning the same thing later.
 * @return bool
 */
function blueline_announcement_visible( ?int $now = null ): bool {
	if ( '' === trim( (string) blueline_settings( 'announcement_text' ) ) ) {
		return false;
	}

	$now  = $now ?? time();
	$from = trim( (string) blueline_settings( 'announcement_from' ) );
	$to   = trim( (string) blueline_settings( 'announcement_to' ) );

	if ( '' !== $from ) {
		$opens = blueline_site_timestamp( $from . ' 00:00:00' );

		if ( null !== $opens && $now < $opens ) {
			return false;
		}
	}

	if ( '' !== $to ) {
		$closes = blueline_site_timestamp( $to . ' 23:59:59' );

		if ( null !== $closes && $now > $closes ) {
			return false;
		}
	}

	return true;
}

/**
 * The URL the announcement links to, or '' when it links nowhere.
 *
 * Deliberately NOT blueline_resolve_link(): that resolver always returns a
 * URL, falling back to the schema's built-in path and ultimately to the
 * site root. An announcement has no natural destination to fall back to --
 * "no page chosen" has to mean "plain text", not "link to the homepage" --
 * so this does its own resolution.
 *
 * It does repeat that resolver's actual safety check, and for the same
 * reason: get_permalink() does not consult post_status, so a trashed or
 * drafted page still yields a plausible URL that 404s on click (see
 * inc/settings/links.php's own docblock). Only a published page is linked.
 *
 * @return string Absolute URL, or '' for no link.
 */
function blueline_announcement_url(): string {
	$page_id = (int) blueline_settings( 'announcement_link' );

	if ( $page_id <= 0 || 'publish' !== get_post_status( $page_id ) ) {
		return '';
	}

	$url = get_permalink( $page_id );

	return false === $url ? '' : (string) $url;
}

/**
 * Print the announcement banner, or nothing at all.
 *
 * A `<section>`, never a `<div>`: tests/NoticeDivGuardTest.php forbids any
 * theme-emitted `<div>` whose class contains "notice", "error", "warning",
 * "info" or "updated" as a substring -- and the default severity modifier
 * here is `bl-announce--info`. That guard is deliberately unconditional and
 * un-scoped by directory, front end included, so this front-end banner is
 * subject to it exactly like an admin notice is.
 *
 * The coloured strip runs full bleed and only the inner element opts into
 * `.bl-container`, matching the hero's own pattern (`.bl-main` sets no
 * max-width of its own), so the banner's text lines up with the rest of the
 * page while the colour reaches both edges.
 *
 * @return void
 */
function blueline_render_announcement(): void {
	if ( ! blueline_announcement_visible() ) {
		return;
	}

	$text     = trim( (string) blueline_settings( 'announcement_text' ) );
	$severity = blueline_announcement_severity();
	$url      = blueline_announcement_url();
	?>
	<section
		class="bl-announce bl-announce--<?php echo esc_attr( $severity ); ?>"
		data-bl-announce="<?php echo esc_attr( blueline_announcement_hash( $text ) ); ?>"
		aria-label="<?php esc_attr_e( 'Announcement', 'blueline' ); ?>"
	>
		<div class="bl-container bl-announce__inner">
			<p class="bl-announce__text">
				<?php if ( '' !== $url ) : ?>
					<a class="bl-announce__link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $text ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $text ); ?>
				<?php endif; ?>
			</p>
			<?php
			/*
			 * A real <button>, not a styled link: it performs an action on
			 * this page rather than navigating, so it has to be reachable
			 * and operable by keyboard as a button. The glyph is hidden
			 * from assistive technology and the accessible name comes from
			 * aria-label, since "x" read aloud names nothing.
			 *
			 * It is rendered unconditionally, including with JavaScript
			 * unavailable, where clicking it does nothing. That is the
			 * lesser of the two: hiding it until JS runs would mean the
			 * control appearing after first paint on every page load for
			 * everyone else.
			 */
			?>
			<button
				type="button"
				class="bl-announce__dismiss"
				data-bl-announce-dismiss
				aria-label="<?php esc_attr_e( 'Dismiss this announcement', 'blueline' ); ?>"
			><span aria-hidden="true">&times;</span></button>
		</div>
	</section>
	<?php
}
