<?php
/**
 * Team colours.
 *
 * SportsPress stores a per-team palette in `sp_colors` post meta on `sp_team`
 * (142 teams on this site have one). The league entered these by hand over a
 * decade, and a great many of them cannot be used as stored:
 *
 *   Oilers      heading #ffffff on background #f4f4f4   -> invisible
 *   Sharks      heading #ffffff on background #fcfcfc   -> invisible
 *   Outlaws     heading #fad765 on background #f4f4f4   -> 1.4:1
 *   Canadiens   primary #ffffff                          -> no contrast anywhere
 *   Boomers     only `primary` and `link` exist          -> keys are not guaranteed
 *
 * So this is deliberately NOT a pass-through of SportsPress's palette, and the
 * `sportspress_enable_frontend_css` option stays off: that option is global
 * rather than per-page, so it could not be scoped to team pages even if we
 * wanted it, and it would import SportsPress's entire stylesheet site-wide
 * against DESIGN.md.
 *
 * What we take from SportsPress is one thing: `primary`, the colour that
 * identifies the team. Everything painted with it gets a foreground computed
 * here against the real WCAG contrast formula, never the stored `text` /
 * `heading` values. The theme's own tokens keep owning body copy, surfaces
 * and structure; the team colour is an accent that says whose page this is.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors of the twelve theme tokens this module needs at PHP time.
 *
 * These duplicate the brand-palette tokens in style.css because contrast
 * has to be computed server-side, and CSS custom properties are not readable
 * from PHP. tools/check-contrast.mjs asserts these constants still match
 * style.css for INK and PAPER, the same way it already guards editor.css's
 * duplicated tokens -- so drift fails the build rather than silently
 * producing wrong maths. A future consistency-check expansion to cover all
 * twelve is not part of this task (YAGNI: nothing in this feature depends
 * on that script also checking the other ten).
 */
const BLUELINE_TOKEN_INK         = '#132343';
const BLUELINE_TOKEN_INK_DEEP    = '#0D1729';
const BLUELINE_TOKEN_INK_MID     = '#2E4A74';
const BLUELINE_TOKEN_ACCENT_TEXT = '#3F6E9D';
const BLUELINE_TOKEN_STEEL       = '#5188B7';
const BLUELINE_TOKEN_ICE         = '#74C0E1';
const BLUELINE_TOKEN_PALE        = '#9ACDE7';
const BLUELINE_TOKEN_PAPER       = '#F7FBFC';
const BLUELINE_TOKEN_WHITE       = '#FFFFFF';
const BLUELINE_TOKEN_SUCCESS     = '#1F7A4D';
const BLUELINE_TOKEN_WARNING     = '#8A5A00';
const BLUELINE_TOKEN_DANGER      = '#A32C1B';

/**
 * Every brand-palette token this feature exposes as admin-tunable, keyed
 * by a short settings/token key (e.g. 'ice' for --bl-ice). This is the
 * one place the set of 12 is spelled out; everything else in this
 * feature iterates it rather than repeating the list.
 *
 * @return array<string, array{css_var: string, default_hex: string, label: string}>
 */
function blueline_brand_color_tokens(): array {
	return array(
		'ink'         => array(
			'css_var'     => '--bl-ink',
			'default_hex' => BLUELINE_TOKEN_INK,
			'label'       => 'Ink (body text & headings)',
		),
		'ink_deep'    => array(
			'css_var'     => '--bl-ink-deep',
			'default_hex' => BLUELINE_TOKEN_INK_DEEP,
			'label'       => 'Ink, deep (darkest shade)',
		),
		'ink_mid'     => array(
			'css_var'     => '--bl-ink-mid',
			'default_hex' => BLUELINE_TOKEN_INK_MID,
			'label'       => 'Ink, mid (secondary text)',
		),
		'accent_text' => array(
			'css_var'     => '--bl-accent-text',
			'default_hex' => BLUELINE_TOKEN_ACCENT_TEXT,
			'label'       => 'Accent (links & buttons)',
		),
		'steel'       => array(
			'css_var'     => '--bl-steel',
			'default_hex' => BLUELINE_TOKEN_STEEL,
			'label'       => 'Steel (borders, large text/strokes only)',
		),
		'ice'         => array(
			'css_var'     => '--bl-ice',
			'default_hex' => BLUELINE_TOKEN_ICE,
			'label'       => 'Ice (fill only — never text on light)',
		),
		'pale'        => array(
			'css_var'     => '--bl-pale',
			'default_hex' => BLUELINE_TOKEN_PALE,
			'label'       => 'Pale (text on dark surfaces only)',
		),
		'paper'       => array(
			'css_var'     => '--bl-paper',
			'default_hex' => BLUELINE_TOKEN_PAPER,
			'label'       => 'Paper (page background)',
		),
		'white'       => array(
			'css_var'     => '--bl-white',
			'default_hex' => BLUELINE_TOKEN_WHITE,
			'label'       => 'White (card surfaces)',
		),
		'success'     => array(
			'css_var'     => '--bl-success',
			'default_hex' => BLUELINE_TOKEN_SUCCESS,
			'label'       => 'Success',
		),
		'warning'     => array(
			'css_var'     => '--bl-warning',
			'default_hex' => BLUELINE_TOKEN_WARNING,
			'label'       => 'Warning',
		),
		'danger'      => array(
			'css_var'     => '--bl-danger',
			'default_hex' => BLUELINE_TOKEN_DANGER,
			'label'       => 'Danger',
		),
	);
}

/**
 * The real, currently-effective hex for one brand-palette token: the
 * admin's stored override (inc/settings/defaults.php's
 * `brand_color_{$token_key}` keys) when one is set, else that token's
 * style.css default. `''` for a `$token_key` blueline_brand_color_tokens()
 * does not declare.
 *
 * The stored override is run through blueline_sanitize_hex_color() before
 * use, not returned verbatim: nothing revalidates a stored settings value
 * once it has bypassed update_option()'s own sanitize_option_* pipeline --
 * inc/settings/store.php's docblock (around the option-store internals)
 * spells this out -- so a hand-edited DB row, a restored SQL dump, or a
 * migration script could otherwise hand a malformed value straight to
 * every one of this function's callers, including blueline_wc_email_option_
 * overrides() (inc/woocommerce.php, which drops it unescaped into email
 * HTML) and this file's own blueline_contrast_ratio() (via hexdec()).
 * A malformed override therefore now behaves exactly like an unset one:
 * silently falling back to the token's default.
 *
 * @param string $token_key e.g. 'ice'.
 * @return string
 */
function blueline_resolved_brand_color( string $token_key ): string {
	$tokens = blueline_brand_color_tokens();

	if ( ! isset( $tokens[ $token_key ] ) ) {
		return '';
	}

	$override = blueline_sanitize_hex_color( blueline_settings( "brand_color_{$token_key}" ) );

	if ( '' !== $override ) {
		return $override;
	}

	return $tokens[ $token_key ]['default_hex'];
}

/**
 * Log, at most once per call site, that tools/contrast-rules.json could not
 * be used as the source of truth for AA thresholds and PHP fell back to the
 * hard-coded 4.5 / 3.0 defaults.
 *
 * This runs on the public front end (blueline_team_color_set() is called
 * while rendering team pages for anonymous visitors), so it deliberately
 * does NOT use _doing_it_wrong(): that function is meant for admin-facing
 * "you called this API wrong" notices and, depending on WP_DEBUG_DISPLAY,
 * can print its message straight into the page source -- acceptable for a
 * developer mistake, not for an operational fault visible to every visitor
 * of a broken install. error_log() writes to the server log only, costs
 * nothing when logging is off, and is the standard place to look for a
 * silently-degraded fallback like this one.
 *
 * The caller (blueline_contrast_threshold()) already memoises its result
 * for the lifetime of the request, so in practice this fires at most once
 * per page load; the local static guard here is a second, independent
 * backstop for any future caller that does not share that cache.
 *
 * @param string $path   The contrast-rules.json path that could not be used.
 * @param string $reason Human-readable reason, for the log line.
 * @return void
 */
function blueline_contrast_rules_read_failure( string $path, string $reason ): void {
	static $logged = false;

	if ( $logged ) {
		return;
	}
	$logged = true;

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deliberate: see the docblock above for why this is the one place we want a silent fallback to leave a server-log trace instead of staying invisible.
	error_log(
		sprintf(
			'Blueline: contrast-rules.json unusable (%s) at %s -- falling back to the hard-coded 4.5/3.0 AA thresholds.',
			$reason,
			$path
		)
	);
}

/**
 * Derive {body, large} thresholds from a decoded contrast-rules.json.
 *
 * Reads the explicit top-level `thresholds` object -- the single declared
 * source of truth -- rather than inferring anything from `rules[].min`.
 * Previously this scanned every rule's `min` and took body = max(mins),
 * large = min(mins): a heuristic over data that a future rule could silently
 * break. Adding an AAA-strength rule with `"min": 7.0` would have raised the
 * body floor used by blueline_readable_foreground() and
 * blueline_team_color_set() for every one of this site's 142 team pages,
 * pushing AA-passing team colours into the "no usable colour" fallback with
 * no code change anywhere near this file. Reading a declared key cannot be
 * moved by an unrelated rule addition, by construction.
 *
 * Split out from blueline_contrast_threshold() so it is unit-testable
 * against arbitrary decoded JSON without touching the filesystem.
 *
 * @param mixed  $json     Decoded contrast-rules.json (or anything, if the
 *                         file was missing/unreadable/malformed).
 * @param array  $fallback array{body:float,large:float} used for any value
 *                         that cannot be read.
 * @param string $path     Source path, for the failure trace only.
 * @return array{body:float,large:float}
 */
function blueline_contrast_thresholds_from_json( $json, array $fallback, string $path = 'contrast-rules.json' ): array {
	if ( ! is_array( $json ) || empty( $json['thresholds'] ) || ! is_array( $json['thresholds'] ) ) {
		blueline_contrast_rules_read_failure( $path, 'missing or non-object top-level "thresholds"' );
		return $fallback;
	}

	$thresholds = $json['thresholds'];
	$body       = isset( $thresholds['body'] ) && is_numeric( $thresholds['body'] ) ? (float) $thresholds['body'] : null;
	$large      = isset( $thresholds['large'] ) && is_numeric( $thresholds['large'] ) ? (float) $thresholds['large'] : null;

	if ( null === $body || null === $large ) {
		blueline_contrast_rules_read_failure( $path, '"thresholds.body" and/or "thresholds.large" missing or non-numeric' );
	}

	return array(
		'body'  => $body ?? $fallback['body'],
		'large' => $large ?? $fallback['large'],
	);
}

/**
 * Load {body, large} thresholds from tools/contrast-rules.json on disk,
 * falling back to sane hard-coded defaults -- never fatally -- if the file
 * is missing, unreadable, or malformed.
 *
 * @param string|null $path_override Explicit path, for tests. Defaults to
 *                                   the real tools/contrast-rules.json next
 *                                   to this theme.
 * @return array{body:float,large:float}
 */
function blueline_load_contrast_thresholds( ?string $path_override = null ): array {
	$fallback = array(
		'body'  => 4.5,
		'large' => 3.0,
	);

	if ( null !== $path_override ) {
		$path = $path_override;
	} else {
		$dir  = defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__ );
		$path = $dir . '/tools/contrast-rules.json';
	}

	if ( ! is_readable( $path ) ) {
		blueline_contrast_rules_read_failure( $path, 'file is missing or unreadable' );
		return $fallback;
	}

	$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL; wp_remote_get() is for HTTP requests.
	$json = json_decode( (string) $raw, true );

	if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
		blueline_contrast_rules_read_failure( $path, 'invalid JSON (' . json_last_error_msg() . ')' );
		return $fallback;
	}

	return blueline_contrast_thresholds_from_json( $json, $fallback, $path );
}

/**
 * A contrast threshold, read from the shared rule table so PHP and the build
 * guard cannot disagree about what "AA" means.
 *
 * The tools/contrast-rules.json file is the single contract; this file used
 * to duplicate 4.5 and 3.0 as constants, which made it a fourth place the
 * numbers could drift.
 *
 * @param string $which 'body' (4.5) or 'large' (3.0, also non-text/UI).
 * @return float
 */
function blueline_contrast_threshold( string $which ): float {
	static $cache = null;

	if ( null === $cache ) {
		$cache = blueline_load_contrast_thresholds();
	}

	return $cache[ $which ] ?? 4.5;
}

/**
 * Normalise a user-entered colour to `#rrggbb`, or '' if it is not one.
 *
 * Accepts 3- and 6-digit hex with or without the leading '#', since the
 * SportsPress colour fields have accepted all of those over the years.
 *
 * @param mixed $value Raw stored value.
 * @return string `#rrggbb` (lowercase) or ''.
 */
function blueline_sanitize_hex_color( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = strtolower( trim( $value ) );
	$value = ltrim( $value, '#' );

	if ( preg_match( '/^[0-9a-f]{3}$/', $value ) ) {
		$value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
	}

	if ( ! preg_match( '/^[0-9a-f]{6}$/', $value ) ) {
		return '';
	}

	return '#' . $value;
}

/**
 * Relative luminance per WCAG 2.x, from an `#rrggbb` string.
 *
 * @param string $hex Validated `#rrggbb`.
 * @return float 0.0-1.0.
 */
function blueline_relative_luminance( string $hex ): float {
	$channels = array(
		hexdec( substr( $hex, 1, 2 ) ),
		hexdec( substr( $hex, 3, 2 ) ),
		hexdec( substr( $hex, 5, 2 ) ),
	);

	$linear = array();
	foreach ( $channels as $channel ) {
		$c        = $channel / 255;
		$linear[] = $c <= 0.04045 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
	}

	return ( 0.2126 * $linear[0] ) + ( 0.7152 * $linear[1] ) + ( 0.0722 * $linear[2] );
}

/**
 * WCAG contrast ratio between two validated `#rrggbb` colours.
 *
 * @param string $a First colour.
 * @param string $b Second colour.
 * @return float 1.0-21.0.
 */
function blueline_contrast_ratio( string $a, string $b ): float {
	$la = blueline_relative_luminance( $a );
	$lb = blueline_relative_luminance( $b );

	$light = max( $la, $lb );
	$dark  = min( $la, $lb );

	return ( $light + 0.05 ) / ( $dark + 0.05 );
}

/**
 * The more readable of ink/paper on the given background.
 *
 * Returns a colour even when NEITHER option reaches the body threshold --
 * something must render -- but now reports that via $passes so the caller can
 * fall back to theme tokens instead of shipping unreadable text. Previously
 * this failure was silent, which is the opposite of what this module exists
 * to do.
 *
 * @param string    $background Validated `#rrggbb`.
 * @param bool|null $passes     Out: whether the returned colour clears AA.
 * @return string Validated `#rrggbb`.
 */
function blueline_readable_foreground( string $background, ?bool &$passes = null ): string {
	$on_ink   = blueline_contrast_ratio( BLUELINE_TOKEN_INK, $background );
	$on_paper = blueline_contrast_ratio( BLUELINE_TOKEN_PAPER, $background );

	$best   = max( $on_ink, $on_paper );
	$passes = $best >= blueline_contrast_threshold( 'body' );

	return $on_ink >= $on_paper ? BLUELINE_TOKEN_INK : BLUELINE_TOKEN_PAPER;
}

/**
 * Saturation of a colour, 0.0-1.0, using the HSL definition.
 *
 * Used only to recognise achromatic colours (white, black, greys), whose hue
 * cannot survive being darkened -- see blueline_team_color_set().
 *
 * @param string $hex Validated `#rrggbb`.
 * @return float
 */
function blueline_color_saturation( string $hex ): float {
	$r = hexdec( substr( $hex, 1, 2 ) ) / 255;
	$g = hexdec( substr( $hex, 3, 2 ) ) / 255;
	$b = hexdec( substr( $hex, 5, 2 ) ) / 255;

	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );

	if ( $max === $min ) {
		return 0.0;
	}

	$l = ( $max + $min ) / 2;
	$d = $max - $min;

	return $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
}

/**
 * Darken a colour toward black, preserving its hue, until it clears a
 * contrast threshold against a background.
 *
 * Scaling all three channels by the same factor keeps the ratios between them
 * -- and therefore the hue -- while lowering luminance. This is what lets a
 * team's actual colour survive into readable text: Sharks' `#55bfd2` is only
 * 2.0:1 on paper, but darkening it produces a teal that clears 4.5:1 and is
 * still recognisably theirs, which a fallback to the generic theme accent
 * would not be.
 *
 * @param string $hex        Validated `#rrggbb` to darken.
 * @param string $background Validated `#rrggbb` to test against.
 * @param float  $min        Minimum acceptable ratio.
 * @return string Validated `#rrggbb`. Returns black if even that fails,
 *                which cannot happen for any background lighter than black.
 */
function blueline_darken_to_contrast( string $hex, string $background, float $min ): string {
	if ( blueline_contrast_ratio( $hex, $background ) >= $min ) {
		return $hex;
	}

	$r = hexdec( substr( $hex, 1, 2 ) );
	$g = hexdec( substr( $hex, 3, 2 ) );
	$b = hexdec( substr( $hex, 5, 2 ) );

	// 40 steps of 2.5% is fine-grained enough that the darkened result is
	// never visibly darker than it needs to be, and terminates at black.
	for ( $step = 1; $step <= 40; $step++ ) {
		$factor    = 1 - ( $step * 0.025 );
		$candidate = sprintf(
			'#%02x%02x%02x',
			(int) round( $r * $factor ),
			(int) round( $g * $factor ),
			(int) round( $b * $factor )
		);

		if ( blueline_contrast_ratio( $candidate, $background ) >= $min ) {
			return $candidate;
		}
	}

	return '#000000';
}

/**
 * The usable colour set for one team, derived from its `sp_colors` meta.
 *
 * @param int $team_id `sp_team` post ID.
 * @return array{primary:string,on_primary:string,accent:string}|array Empty
 *               array when the team has no usable colour, in which case the
 *               page renders exactly as it did before this feature existed.
 */
function blueline_team_color_set( $team_id ): array {
	$team_id = absint( $team_id );

	if ( ! $team_id ) {
		return array();
	}

	$stored = get_post_meta( $team_id, 'sp_colors', true );

	// Missing meta, a failed unserialize (WordPress hands back the raw
	// string), or a shape we do not recognise: all mean "no colour".
	if ( ! is_array( $stored ) || empty( $stored['primary'] ) ) {
		return array();
	}

	$primary = blueline_sanitize_hex_color( $stored['primary'] );

	if ( '' === $primary ) {
		return array();
	}

	$on_primary = blueline_readable_foreground( $primary, $passes );

	// Neither ink nor paper reaches AA on this primary: there is no readable
	// foreground to pair with it, so treat it the same as "no usable colour"
	// rather than shipping the least-bad option silently.
	if ( ! $passes ) {
		return array();
	}

	/*
	 * The accent is the team's colour used as *text* on the paper ground.
	 *
	 * An achromatic primary (Canadiens' #ffffff, Outlaws' #adadad) has no
	 * hue to preserve, so darkening it yields grey -- which is not the
	 * team's identity, just a dark neutral. Those fall back to the theme's
	 * own accent for text while still using the real colour as a fill, so
	 * the page keeps the team's identity where it reads and stays legible
	 * where it matters.
	 */
	$is_achromatic = blueline_color_saturation( $primary ) < 0.15;

	if ( $is_achromatic ) {
		$accent = '';
	} else {
		$accent = blueline_darken_to_contrast(
			$primary,
			BLUELINE_TOKEN_PAPER,
			blueline_contrast_threshold( 'body' )
		);
	}

	return array(
		'primary'    => $primary,
		'on_primary' => $on_primary,
		'accent'     => $accent,
	);
}

/**
 * The `style` attribute carrying a team's colours as scoped custom properties,
 * ready to print. Returns '' when the team has no usable colour, so the
 * attribute is simply absent and every rule falls back to its theme token.
 *
 * Custom properties are the sanctioned form of the theme's "no inline styles
 * from PHP" rule: the value is a token definition, not a declaration, and
 * every actual rule lives in assets/src/css/sportspress.css.
 *
 * @param int $team_id `sp_team` post ID.
 * @return string Escaped ` style="..."`, or ''.
 */
function blueline_team_color_style_attr( $team_id ): string {
	$colors = blueline_team_color_set( $team_id );

	if ( empty( $colors ) ) {
		return '';
	}

	$declarations = array(
		'--bl-team-primary: ' . $colors['primary'],
		'--bl-team-on-primary: ' . $colors['on_primary'],
	);

	if ( '' !== $colors['accent'] ) {
		$declarations[] = '--bl-team-accent: ' . $colors['accent'];
	}

	return ' style="' . esc_attr( implode( '; ', $declarations ) . ';' ) . '"';
}

/**
 * Every tools/contrast-rules.json rule whose fg or bg is exactly
 * $css_var, from an already-decoded manifest. Excludes any rule where
 * either side is a computed `{"mix": [...]}` tint (no runtime
 * colour-mixing exists in this codebase — see this plan's Global
 * Constraints), references `--bl-occasion-accent` (its live value
 * depends on which occasion is active, unrelated to this settings page),
 * or references any `--bl-content-*` token (those are light/dark-toggle
 * aliases with no single static value).
 *
 * Split out from blueline_contrast_rules_for_token() so it is
 * unit-testable against arbitrary decoded JSON without touching the
 * filesystem — same reasoning as blueline_contrast_thresholds_from_json().
 *
 * @param mixed  $json    Decoded contrast-rules.json.
 * @param string $css_var e.g. '--bl-ice'.
 * @return array<int, array{id: string, description: string, fg: string, bg: string, min: ?float, max: ?float}>
 */
function blueline_contrast_rules_for_token_from_json( $json, string $css_var ): array {
	if ( ! is_array( $json ) || empty( $json['rules'] ) || ! is_array( $json['rules'] ) ) {
		return array();
	}

	$matches = array();

	foreach ( $json['rules'] as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}

		$fg = $rule['fg'] ?? null;
		$bg = $rule['bg'] ?? null;

		if ( ! is_string( $fg ) || ! is_string( $bg ) ) {
			continue; // A computed {"mix": [...]} side — not resolvable here.
		}

		$excluded = false;
		foreach ( array( $fg, $bg ) as $side ) {
			if ( '--bl-occasion-accent' === $side || 0 === strpos( $side, '--bl-content-' ) ) {
				$excluded = true;
				break;
			}
		}
		if ( $excluded ) {
			continue;
		}

		if ( $css_var !== $fg && $css_var !== $bg ) {
			continue;
		}

		$matches[] = array(
			'id'          => (string) ( $rule['id'] ?? '' ),
			'description' => (string) ( $rule['description'] ?? '' ),
			'fg'          => $fg,
			'bg'          => $bg,
			'min'         => isset( $rule['min'] ) && is_numeric( $rule['min'] ) ? (float) $rule['min'] : null,
			'max'         => isset( $rule['max'] ) && is_numeric( $rule['max'] ) ? (float) $rule['max'] : null,
		);
	}

	return $matches;
}

/**
 * Load every tools/contrast-rules.json rule referencing $css_var from disk.
 *
 * @param string      $css_var       e.g. '--bl-ice'.
 * @param string|null $path_override Explicit path, for tests.
 * @return array<int, array{id: string, description: string, fg: string, bg: string, min: ?float, max: ?float}>
 */
function blueline_contrast_rules_for_token( string $css_var, ?string $path_override = null ): array {
	if ( null !== $path_override ) {
		$path = $path_override;
	} else {
		$dir  = defined( 'BLUELINE_DIR' ) ? BLUELINE_DIR : dirname( __DIR__ );
		$path = $dir . '/tools/contrast-rules.json';
	}

	if ( ! is_readable( $path ) ) {
		blueline_contrast_rules_read_failure( $path, 'file is missing or unreadable' );
		return array();
	}

	$raw  = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local repo file, not a remote URL.
	$json = json_decode( (string) $raw, true );

	if ( ! is_array( $json ) ) {
		blueline_contrast_rules_read_failure( $path, 'invalid JSON' );
		return array();
	}

	return blueline_contrast_rules_for_token_from_json( $json, $css_var );
}

/**
 * The fixed hex for a small, closed set of non-brand tokens that show up
 * as the OTHER side of a brand-token contrast rule (focus ring/halo,
 * dividers) — never admin-tunable, so this is a plain literal lookup, not
 * a settings read. Values copied verbatim from style.css's `:root` block.
 *
 * @param string $css_var e.g. '--bl-focus-color'.
 * @return string Hex, or '' if $css_var is not one of this fixed set.
 */
function blueline_static_token_hex( string $css_var ): string {
	$map = array(
		'--bl-focus-color'   => '#0D1729',
		'--bl-focus-halo'    => '#FFFFFF',
		'--bl-border'        => '#DBE7F0',
		'--bl-border-strong' => '#7C93A8',
	);

	return $map[ $css_var ] ?? '';
}

/**
 * The current effective hex for any --bl-* var this feature knows how to
 * resolve: one of the 12 brand tokens (through its live admin override,
 * blueline_resolved_brand_color()) or one of blueline_static_token_hex()'s
 * fixed non-brand set.
 *
 * @param string $css_var e.g. '--bl-ice'.
 * @return string Hex, or '' if neither resolver recognises $css_var.
 */
function blueline_hex_for_css_var( string $css_var ): string {
	foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
		if ( $token['css_var'] === $css_var ) {
			return blueline_resolved_brand_color( $token_key );
		}
	}

	return blueline_static_token_hex( $css_var );
}

/**
 * Every applicable contrast rule for one brand token, evaluated against
 * $candidate_hex and the CURRENT resolved value of whichever token each
 * rule's other side names (so an admin's override on token A is reflected
 * live in token B's report, when a rule pairs A and B).
 *
 * @param string $token_key     e.g. 'ice'.
 * @param string $candidate_hex The value to check — not necessarily the
 *                               currently-saved one, so a not-yet-saved
 *                               edit can be checked before submit.
 * @return array<int, array{id: string, description: string, ratio: float, passes: bool}>
 */
function blueline_brand_color_contrast_report( string $token_key, string $candidate_hex ): array {
	$tokens = blueline_brand_color_tokens();

	if ( ! isset( $tokens[ $token_key ] ) ) {
		return array();
	}

	$css_var = $tokens[ $token_key ]['css_var'];
	$report  = array();

	foreach ( blueline_contrast_rules_for_token( $css_var ) as $rule ) {
		$other_var = $css_var === $rule['fg'] ? $rule['bg'] : $rule['fg'];
		$other_hex = blueline_hex_for_css_var( $other_var );

		$ratio = blueline_contrast_ratio( $candidate_hex, $other_hex );

		$passes = true;
		if ( null !== $rule['min'] ) {
			$passes = $ratio >= $rule['min'];
		} elseif ( null !== $rule['max'] ) {
			$passes = $ratio <= $rule['max'];
		}

		$report[] = array(
			'id'          => $rule['id'],
			'description' => $rule['description'],
			'ratio'       => $ratio,
			'passes'      => $passes,
		);
	}

	return $report;
}

/**
 * The per-token contrast-rule data assets/src/js/settings-brand-colors.js
 * needs to recompute a live readout in the browser, without the JS having
 * to read tools/contrast-rules.json itself.
 *
 * Kept in this file, alongside blueline_brand_color_contrast_report(), so
 * the token-key-to-css-var lookup isn't duplicated across two files.
 *
 * @return array<string, array<int, array{description: string, min: ?float, max: ?float, otherTokenKey: ?string, otherDefaultHex: string}>>
 */
function blueline_brand_color_js_rules(): array {
	$tokens = blueline_brand_color_tokens();
	$data   = array();

	foreach ( $tokens as $token_key => $token ) {
		$rows = array();

		foreach ( blueline_contrast_rules_for_token( $token['css_var'] ) as $rule ) {
			$other_var = $token['css_var'] === $rule['fg'] ? $rule['bg'] : $rule['fg'];
			$other_key = null;

			foreach ( $tokens as $candidate_key => $candidate_token ) {
				if ( $candidate_token['css_var'] === $other_var ) {
					$other_key = $candidate_key;
					break;
				}
			}

			$rows[] = array(
				'description'     => $rule['description'],
				'min'             => $rule['min'],
				'max'             => $rule['max'],
				'otherTokenKey'   => $other_key,
				'otherDefaultHex' => null !== $other_key
					? $tokens[ $other_key ]['default_hex']
					: blueline_static_token_hex( $other_var ),
			);
		}

		$data[ $token_key ] = $rows;
	}

	return $data;
}

/**
 * The `--bl-token:hex;` CSS declarations for every brand-palette token an
 * admin has actually overridden away from its style.css default, shared by
 * blueline_brand_color_front_end_styles() and
 * blueline_brand_color_editor_styles() so the "is this token actually
 * overridden, and what's its live hex" loop exists in exactly one place
 * instead of being duplicated across both emitters.
 *
 * A token counts as "overridden" here by comparing
 * blueline_resolved_brand_color()'s return against that same token's own
 * `default_hex` -- not by re-reading and re-validating the raw stored
 * option. Because blueline_resolved_brand_color() itself now sanitises the
 * stored value (falling back to the default for anything malformed), a
 * token whose stored override is malformed resolves right back to its
 * default and is correctly excluded here too: no separate regex re-guard
 * is needed in this loop, since the resolver is now the single source of
 * truth for "is this a usable hex".
 *
 * @return string e.g. '--bl-ice:#123456;--bl-paper:#abcdef;', or '' if
 *                nothing is overridden.
 */
function blueline_brand_color_override_declarations(): string {
	$declarations = '';

	foreach ( blueline_brand_color_tokens() as $token_key => $token ) {
		$resolved = blueline_resolved_brand_color( $token_key );

		if ( $resolved === $token['default_hex'] ) {
			continue; // Not overridden (or the override was malformed and fell back to the default).
		}

		$declarations .= "{$token['css_var']}:{$resolved};";
	}

	return $declarations;
}

/**
 * Emit every admin-overridden brand-palette colour as an inline override
 * on the blueline-tokens handle (inc/enqueue.php), same handle/hook/
 * priority as blueline_occasion_front_end_styles() (inc/occasions.php) —
 * both append to the same handle safely, since wp_add_inline_style()
 * appends rather than replaces.
 *
 * Three of the twelve brand tokens (--bl-success, --bl-warning,
 * --bl-danger) are redefined in style.css's own dark-mode block
 * (`@media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) {...} }`)
 * and its `:root[data-theme="dark"]` explicit-toggle twin — both more
 * specific than a bare `:root` rule. An override emitted only into a bare
 * `:root` would therefore be silently beaten by style.css's own dark-mode
 * default under dark mode. Every override here is instead repeated,
 * unchanged, into all three selector shapes, so the admin's choice applies
 * the same regardless of theme — this deliberately drops style.css's own
 * per-theme tuning for any token an admin chooses to override.
 *
 * Known, accepted limitation: style.css's own `@media print { :root { ... } }`
 * block sets `--bl-success`/`--bl-warning`/`--bl-danger` with `!important`,
 * which always beats this function's non-`!important` override selectors
 * when printing. So an override of any of those three tokens does not apply
 * to printed pages. This was a deliberate call, not an oversight left
 * unnoticed -- a 4th, `!important` print-media selector emission was
 * considered and deferred as out of scope for the fix that documented it.
 *
 * @return void
 */
function blueline_brand_color_front_end_styles(): void {
	$declarations = blueline_brand_color_override_declarations();

	if ( '' === $declarations ) {
		return; // Nothing overridden: style.css's own :root already covers every case.
	}

	$css  = ':root{' . $declarations . '}';
	$css .= '@media (prefers-color-scheme: dark){:root:not([data-theme="light"]){' . $declarations . '}}';
	$css .= ':root[data-theme="dark"]{' . $declarations . '}';

	wp_add_inline_style( 'blueline-tokens', $css );
}
add_action( 'wp_enqueue_scripts', 'blueline_brand_color_front_end_styles', 20 );

/**
 * Block-editor parity for admin-overridden brand colours, alongside
 * blueline_occasion_editor_styles()'s identical treatment of
 * --bl-occasion-accent (inc/occasions.php) — both hook
 * block_editor_settings_all and each appends its own `$settings['styles']`
 * entry, so neither has to know about the other.
 *
 * Unlike blueline_brand_color_front_end_styles(), this does not repeat
 * the declarations into a dark-mode media query: the block editor canvas
 * does not toggle between the site's light/dark states the same way the
 * front end does (occasions' own editor-parity filter has the same
 * single-mode scope).
 *
 * @param array<string, mixed> $settings Block editor settings.
 * @return array<string, mixed>
 */
function blueline_brand_color_editor_styles( array $settings ): array {
	$declarations = blueline_brand_color_override_declarations();

	if ( '' === $declarations ) {
		return $settings;
	}

	$styles   = isset( $settings['styles'] ) && is_array( $settings['styles'] ) ? $settings['styles'] : array();
	$styles[] = array(
		'css'            => ':root, .editor-styles-wrapper { ' . $declarations . ' }',
		'__unstableType' => 'theme',
	);

	$settings['styles'] = $styles;

	return $settings;
}
add_filter( 'block_editor_settings_all', 'blueline_brand_color_editor_styles' );
