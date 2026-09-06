<?php
/**
 * Settings schema and defaults — the field registry every later part of the
 * Appearance → Blueline control panel reads.
 *
 * This file is pure data plus accessors: it declares what fields exist and
 * what they default to. It writes no options, registers no UI, and adds no
 * filters — those arrive in later tasks that build on this contract.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option name the whole settings panel is stored under, as a single array
 * rather than one option per field, so the panel's own save round-trip is
 * one get_option()/update_option() pair.
 */
const BLUELINE_SETTINGS_OPTION = 'blueline_settings';

/**
 * Schema version of the stored option. Bump this whenever the schema shape
 * changes in a way a migration needs to know about; later tasks read it,
 * this one only defines it.
 */
const BLUELINE_SETTINGS_SCHEMA_VERSION = 1;

/**
 * The field registry: every setting the panel exposes, keyed by option
 * array key, each declaring at minimum `type`, `tab`, and `label`.
 *
 * Field types: `text`, `email`, `page_id`, `term_id`, `bool`, `textarea`,
 * `section`, `date`.
 *
 * A field may also declare `choices` — an ordered map of stored value =>
 * admin-facing label. It is orthogonal to `type` (only `text` honours it
 * today, which SettingsDefaultsTest enforces): the sanitizer refuses any
 * value not on the list, and the panel renders the field as a `<select>`
 * rather than a text box, so the invalid state is unreachable through the
 * UI rather than merely rejected by it.
 *
 * A `date` field stores a strict `Y-m-d` string, or `''` for "not set".
 * Anything else is refused at save time with a WP_Error rather than
 * silently coerced -- see inc/settings/sanitize.php's `date` branch for
 * why, and inc/settings/page.php for the `<input type="date">` it renders
 * as.
 *
 * `section`-typed fields are not written by hand in the array literal below:
 * they are generated at the end of this function, one per entry in
 * blueline_section_definitions() (inc/settings/sections.php) -- that list is
 * the single source of truth for which sections exist, their labels and
 * their groups, so a new section needs no second edit here. A `section`
 * field sanitizes identically to `bool` (inc/settings/sanitize.php); the
 * distinct type name exists only so blueline_section_enabled() and this
 * schema stay conceptually separate from the panel's other boolean toggles,
 * not because the sanitizer treats them differently.
 *
 * `page_id`/`term_id` fields carry a `fallback` — the built-in path or term
 * ID the theme uses today when the stored value is `0` ("use the theme
 * default" rather than "point at this specific post/term"). A `term_id`
 * field additionally carries `taxonomy` when it should render as a real term
 * picker (wp_dropdown_categories()) rather than a raw number box — see
 * inc/settings/page.php's blueline_settings_render_field() and
 * inc/settings/commerce.php's blueline_resolve_registration_term(), which
 * reads this same key to know which taxonomy to validate the stored/fallback
 * ID against.
 *
 * Every `text`/`textarea` field MUST carry a `placeholders` array naming the
 * exact sprintf() conversion specs (e.g. `%s`) its value is required to
 * contain — `array()` for a field whose value never reaches a sprintf()
 * call site. This is enforced, not merely documented:
 * SettingsDefaultsTest::test_every_text_and_textarea_field_declares_a_placeholders_key()
 * fails the build if any `text`/`textarea` field omits the key, and
 * blueline_settings_defaults() must supply a default containing every spec
 * a field declares here (a separate assertion in the same test file). Every
 * field below that never reaches a sprintf() call site declares
 * `placeholders => array()` — not "no contract yet", but "the contract IS
 * zero placeholders" — so a stray "%" in its value is rejected by
 * inc/settings/sanitize.php's blueline_sanitize_field() exactly like any
 * other violation. The seven `hero_*` fields below (Task 8) are the first to
 * carry a real, non-empty contract, added one field at a time against the
 * validator Task 3 built and verified against the actual sprintf()/printf()
 * call site each replaces — see inc/homepage-modules.php and the Task 8
 * report for the call-site-by-call-site verification.
 *
 * @return array<string, array<string, mixed>>
 */
function blueline_settings_schema(): array {
	$schema = array(
		// Content tab.
		'contact_email'                 => array(
			'type'  => 'email',
			'tab'   => 'content',
			'label' => 'Contact email',
		),
		'footer_heading'                => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer column heading',
			'placeholders' => array(),
		),
		'footer_location'               => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer location line',
			'placeholders' => array(),
		),
		'hero_offseason_cta'            => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Off-season CTA label',
			'placeholders' => array(),
		),
		// Task 8 (fix round 2): the plan's remaining four hardcoded copy
		// strings -- the "Never played? Perfect." module's heading and CTA
		// label, and two account-dashboard empty-state lines -- none of
		// which ever carries a sprintf() placeholder, hence `array()` on all
		// four. "The plan's" because the theme has plenty of other hardcoded
		// strings outside this plan's scope; these four are simply the ones
		// this plan's own task list named.
		'module_new_here_heading'       => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Homepage "new here" module heading',
			'placeholders' => array(),
		),
		'module_new_here_cta'           => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Homepage "new here" module CTA label',
			'placeholders' => array(),
		),
		'account_empty_next_game'       => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'My Account — "next game" empty-state line',
			'placeholders' => array(),
		),
		'account_empty_stats'           => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'My Account — "season stats" empty-state line',
			'placeholders' => array(),
		),
		// Hero copy carrying a live sprintf() placeholder contract (Task 8).
		// Each label spells out what the placeholder becomes so a volunteer
		// editing the field cannot omit or reorder it without understanding
		// why -- see inc/settings/sanitize.php's blueline_sanitize_field()
		// for what happens if they do anyway.
		'hero_registration_headline'    => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — registration open (%s becomes the highlighted word, e.g. "beginner")',
			'placeholders' => array( '%s' ),
		),
		'hero_registration_eyebrow'     => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero eyebrow — registration open (%s becomes the current season label, e.g. "Winter 2026-27")',
			'placeholders' => array( '%s' ),
		),
		'hero_registration_cta'         => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero CTA — registration open, priced (%s becomes the formatted price, e.g. "$550.00")',
			'placeholders' => array( '%s' ),
		),
		'hero_preseason_headline'       => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — preseason (%s becomes the highlighted season-start date, e.g. "September 6")',
			'placeholders' => array( '%s' ),
		),
		'hero_in_season_headline'       => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — in season (%1$s becomes the highlighted game count; %2$s becomes "game" or "games")',
			'placeholders' => array( '%1$s', '%2$s' ),
		),
		'hero_playoffs_eyebrow'         => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero eyebrow — playoffs (%s becomes the current season label)',
			'placeholders' => array( '%s' ),
		),
		'hero_offseason_headline'       => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — off-season (%s becomes the highlighted word, e.g. "soon")',
			'placeholders' => array( '%s' ),
		),
		// Announcement banner (Task 6). `announcement_text` is the on
		// switch as well as the copy: empty means no banner, so there is
		// no separate enabled flag that could fall out of step with it.
		// `announcement_link` deliberately carries NO `fallback`, unlike
		// every Links-tab page_id field: 0 here means "plain text, no
		// link", not "use a built-in path", so there is nothing to fall
		// back to (see blueline_announcement_url()).
		'announcement_text'             => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Announcement banner text',
			'help'         => 'Shown above every page on the site. Leave empty for no banner.',
			'placeholders' => array(),
		),
		'announcement_link'             => array(
			'type'  => 'page_id',
			'tab'   => 'content',
			'label' => 'Announcement banner link',
			'help'  => 'Optional. The banner text becomes a link to this page.',
		),
		'announcement_from'             => array(
			'type'  => 'date',
			'tab'   => 'content',
			'label' => 'Announcement banner — first day shown',
			'help'  => 'Optional. Leave empty to start showing it immediately.',
		),
		'announcement_to'               => array(
			'type'  => 'date',
			'tab'   => 'content',
			'label' => 'Announcement banner — last day shown',
			'help'  => 'Optional. Leave empty to keep showing it until the text is cleared. The banner stays up for the whole of this day.',
		),
		'announcement_severity'         => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Announcement banner tone',
			'help'         => 'Quiet by default. "Urgent" makes the banner louder — worth keeping for things that actually are.',
			'placeholders' => array(),
			// Keys pinned against BLUELINE_ANNOUNCEMENT_SEVERITIES by
			// AnnouncementTest, so this list and the read-time clamp cannot
			// drift apart. Deliberately no empty option: the tone always has
			// a value, and 'info' is the default.
			'choices'      => array(
				'info'   => 'Info (quiet)',
				'urgent' => 'Urgent (loud)',
			),
		),
		// Season-state break-glass (Task 7). Failure recovery, not routine
		// configuration -- see blueline_season_state_override()
		// (inc/season-state.php) for the three separate ways an override is
		// ignored, and why the expiry is mandatory rather than optional.
		'season_state_override'         => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Force the season state (break-glass)',
			'help'         => 'Leave this alone unless the site is showing the wrong season. Whatever you pick is ignored until you also set an end date below.',
			'placeholders' => array(),
			// A dropdown, not a text box: this control gets used under
			// pressure, and a typo used to save cleanly, change nothing, and
			// raise no notice (fix round I2). The five non-empty keys are
			// pinned against BLUELINE_SEASON_STATES by
			// SeasonStateOverrideTest.
			'choices'      => array(
				''                  => '— No override (use the computed state) —',
				'registration_open' => 'Registration open',
				'preseason'         => 'Preseason',
				'in_season'         => 'In season',
				'playoffs'          => 'Playoffs',
				'offseason'         => 'Off-season',
			),
		),
		'season_state_override_until'   => array(
			'type'  => 'date',
			'tab'   => 'content',
			'label' => 'Force the season state — until',
			'help'  => 'Required for the override above to do anything, and it lifts itself at the end of this day. An override nobody remembers setting becomes the site\'s permanent state.',
		),
		// Links tab — every value is a page ID; 0 means "use the built-in path".
		'page_schedule'                 => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Schedule page',
			'fallback' => '/schedule',
		),
		'page_standings'                => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Standings page',
			'fallback' => '/standings',
		),
		'page_register'                 => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Register page',
			'fallback' => '/register',
		),
		'page_faqs'                     => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'FAQs page',
			'fallback' => '/faqs',
		),
		'page_news'                     => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'News page',
			'fallback' => '/news',
		),
		'page_legal'                    => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Legal page',
			'fallback' => '/legal',
		),
		'page_contact'                  => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Contact page',
			'fallback' => '/arl-league-info/contact-us',
		),
		'page_equipment'                => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Equipment page',
			'fallback' => '/arl-league-info/equipment',
		),
		// Appearance tab.
		'hero_photos'                   => array(
			'type'  => 'band_photos',
			'tab'   => 'appearance',
			'label' => 'Hero photographs',
			// Empty means "use the photographs that ship with the theme"
			// (blueline_band_shots()), NOT "show no photograph". That is what
			// makes this setting an override rather than a switch: the hero
			// works before anyone opens this panel, emptying the list restores
			// the shipped set instead of blanking the band, and no migration
			// has to invent attachments for images that are theme files.
			'help'  => 'Leave empty to use the photographs that ship with the theme. Each photograph keeps its own alignment, so the subject can be kept clear of the headline.',
			'max'   => 12,
		),
		'hero_photo_rotate'             => array(
			'type'  => 'bool',
			'tab'   => 'appearance',
			'label' => 'Show a different photograph on each page load',
			'help'  => 'Chosen in the browser, so it keeps rotating even when the page itself is cached. With this off, the first photograph in the list is always used.',
		),
		// A plain `bool`, DELIBERATELY NOT a `section` toggle, even though
		// it reads like one. sportspress/league-table.php renders the extra
		// stat columns either way -- they are in the DOM, and a CSS-only
		// label shows or hides them with no JavaScript -- so this sets the
		// INITIAL state of a disclosure the reader controls, not whether
		// anything renders. blueline_section_enabled() (inc/settings/sections.php)
		// means "renders at all" for all sixteen of its keys and fails
		// closed on an unknown one; a seventeenth key meaning "starts
		// expanded" instead would make that one function mean two different
		// things. See tests/StandingsExtraStatsDefaultTest.php, which pins
		// this decision rather than leaving it to a comment.
		'standings_extra_stats_default' => array(
			'type'  => 'bool',
			'tab'   => 'appearance',
			'label' => 'Open standings tables on the full stats view',
			'help'  => 'Off by default, so a standings table opens on Pos / Team / Record / Points. Either way a reader can switch between the two with the "Show full stats" control on the table itself -- this only chooses which one they land on.',
		),
		'brand_color_ink'               => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink (body text & headings)',
			'token_key' => 'ink',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ink_deep'          => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink, deep (darkest shade)',
			'token_key' => 'ink_deep',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ink_mid'           => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ink, mid (secondary text)',
			'token_key' => 'ink_mid',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_accent_text'       => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Accent (links & buttons)',
			'token_key' => 'accent_text',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_steel'             => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Steel (borders, large text/strokes only)',
			'token_key' => 'steel',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_ice'               => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Ice (fill only — never text on light)',
			'token_key' => 'ice',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_pale'              => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Pale (text on dark surfaces only)',
			'token_key' => 'pale',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_paper'             => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Paper (page background)',
			'token_key' => 'paper',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_white'             => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'White (card surfaces)',
			'token_key' => 'white',
			'help'      => 'Leave blank to use the theme default.',
		),
		'brand_color_success'           => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Success',
			'token_key' => 'success',
			'help'      => 'Leave blank to use the theme default. This value applies identically in light and dark mode — style.css normally uses a different shade for each, so check your chosen color reads well in both before saving.',
		),
		'brand_color_warning'           => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Warning',
			'token_key' => 'warning',
			'help'      => 'Leave blank to use the theme default. This value applies identically in light and dark mode — style.css normally uses a different shade for each, so check your chosen color reads well in both before saving.',
		),
		'brand_color_danger'            => array(
			'type'      => 'color',
			'tab'       => 'appearance',
			'label'     => 'Danger',
			'token_key' => 'danger',
			'help'      => 'Leave blank to use the theme default. This value applies identically in light and dark mode — style.css normally uses a different shade for each, so check your chosen color reads well in both before saving.',
		),
		// A DISCLOSURE affordance, not a privilege boundary -- the spec says so
		// twice (section 3's audience row, and "The Advanced toggle must state
		// in its own UI that it is a warning, not a lock"). Everyone who can
		// reach this page already holds `manage_options`, so flipping this
		// grants nobody anything they could not already do; it only stops a
		// volunteer wandering into the dangerous controls by accident. The
		// label and help text below have to say that plainly, because a
		// "here be dragons" switch that LOOKS like a lock is worse than no
		// switch at all -- someone would rely on it.
		//
		// What it gates today is the "Delete all Blueline data" control
		// (inc/settings/page.php's blueline_settings_render_delete_all_data()),
		// which is the most destructive thing the panel can do and so the most
		// dragon-like thing to put behind a dragons switch. The AA-failure
		// acknowledgement the spec also names is P2, with the colour work.
		//
		// It also has to exist for inc/settings/import.php's unconditional
		// discard to be reachable at all: until this was a real schema key the
		// unknown-key drop covered it, and the gap between those two is
		// exactly when a crafted file would have worked.
		'advanced_enabled'              => array(
			'type'  => 'bool',
			'tab'   => 'appearance',
			'label' => 'Show advanced controls',
			'help'  => 'Reveals controls that can break the site if used carelessly. This is a warning, not a lock: anyone who can open this page can switch it on, and switching it off hides those controls without restricting anybody.',
		),
		// Commerce tab.
		'registration_term'             => array(
			'type'     => 'term_id',
			'tab'      => 'commerce',
			'label'    => 'Registration product category',
			'fallback' => 91,
			// `taxonomy` is what makes this a term PICKER (a dropdown of real
			// product_cat terms, rendered via wp_dropdown_categories() -- see
			// inc/settings/page.php's blueline_settings_render_field()) rather
			// than a raw number box an admin has to already know the ID for.
			// A future `term_id` field that genuinely has no taxonomy to pick
			// from would simply omit this key and fall back to the number
			// input, per blueline_settings_render_field()'s own branching.
			'taxonomy' => 'product_cat',
		),
	);

	// Sections tab -- generated from blueline_section_definitions()
	// (inc/settings/sections.php) so a new section needs no second edit
	// here: the definition list is the single source of truth, and this
	// loop is the only place that turns it into schema entries.
	foreach ( blueline_section_definitions() as $key => $def ) {
		$schema[ $key ] = array(
			'type'  => 'section',
			'tab'   => 'sections',
			'label' => $def['label'],
			'group' => $def['group'],
		);

		// Only the four module_* entries carry a `help` string (the
		// floor's explanation -- see blueline_section_definitions()'s own
		// docblock); everything else's $def has no such key, and this must
		// not invent an empty one that would make blueline_settings_render_field()'s
		// `! empty( $field['help'] )` check start rendering a blank <p>.
		if ( ! empty( $def['help'] ) ) {
			$schema[ $key ]['help'] = $def['help'];
		}

		// Not part of the auto-generated boolean shape above: this one
		// needs `choices`. Inserted here, immediately after
		// chrome_footer_teams's own entry, so insertion order (which IS
		// render order -- blueline_settings_fields_for_tab() has no
		// group-based layout) actually puts it next to the toggle it
		// depends on, not at the end of the whole Sections tab.
		if ( 'chrome_footer_teams' === $key ) {
			$schema['chrome_team_directory_position'] = array(
				'type'         => 'text',
				'tab'          => 'sections',
				'group'        => 'Site chrome',
				'label'        => 'Team directory position',
				'help'         => 'Only matters while "Footer team directory" above is on.',
				'placeholders' => array(),
				'choices'      => array(
					'footer' => 'Footer directory',
					'flyout' => 'Flyout menu',
				),
			);
		}
	}

	return $schema;
}

/**
 * The default value for the `BLUELINE_SETTINGS_OPTION` option: every schema
 * field, present with a value. `page_id`/`term_id` fields default to `0`
 * ("use the fallback"); text/email fields default to the literal the theme
 * currently hardcodes, so installing the panel changes no rendered output
 * until an admin actually edits a field.
 *
 * @return array<string, mixed>
 */
function blueline_settings_defaults(): array {
	$defaults = array(
		'contact_email'                  => 'play@rookiehockey.ca',
		'footer_heading'                 => 'The League',
		'footer_location'                => 'Burlington, Ontario',
		'hero_offseason_cta'             => 'Join the mailing list',
		'module_new_here_heading'        => 'Never played? Perfect.',
		'module_new_here_cta'            => 'Read the FAQs',
		'account_empty_next_game'        => 'No upcoming game on your schedule yet.',
		'account_empty_stats'            => 'Stats update after each game is scored.',
		'hero_registration_headline'     => 'Burlington’s %s league.',
		'hero_registration_eyebrow'      => '%s · Registration open',
		'hero_registration_cta'          => 'Register — %s',
		'hero_preseason_headline'        => 'Puck drops %s.',
		'hero_in_season_headline'        => '%1$s %2$s this week.',
		'hero_playoffs_eyebrow'          => '%s · Playoffs',
		'hero_offseason_headline'        => 'Back on the ice %s.',
		// The banner ships off: no text, no window, quiet tone. Installing
		// this release must not put a strip of copy above every page.
		'announcement_text'              => '',
		'announcement_link'              => 0,
		'announcement_from'              => '',
		'announcement_to'                => '',
		'announcement_severity'          => 'info',
		// Nothing forced, and nothing to force it until: the break-glass
		// ships un-pulled.
		'season_state_override'          => '',
		'season_state_override_until'    => '',
		'page_schedule'                  => 0,
		'page_standings'                 => 0,
		'page_register'                  => 0,
		'page_faqs'                      => 0,
		'page_news'                      => 0,
		'page_legal'                     => 0,
		'page_contact'                   => 0,
		'page_equipment'                 => 0,
		'registration_term'              => 0,
		'hero_photos'                    => array(),
		'hero_photo_rotate'              => true,
		// Off: the extra stat columns start hidden today (the checkbox in
		// sportspress/league-table.php carried no `checked` attribute at
		// all before this field existed), and installing this release must
		// not change how a standings table already renders.
		'standings_extra_stats_default'  => false,
		// Brand palette colours default to empty strings: blueline_settings()
		// merges each of these against ITS OWN '' default declared here, not
		// against blueline_brand_color_tokens()'s palette -- the palette
		// fallback happens later, inside blueline_resolved_brand_color()
		// (inc/team-colors.php), which every real caller reads through
		// instead of blueline_settings() directly, so a blank stored value
		// still ends up falling back to the theme's hard-coded palette.
		'brand_color_ink'                => '',
		'brand_color_ink_deep'           => '',
		'brand_color_ink_mid'            => '',
		'brand_color_accent_text'        => '',
		'brand_color_steel'              => '',
		'brand_color_ice'                => '',
		'brand_color_pale'               => '',
		'brand_color_paper'              => '',
		'brand_color_white'              => '',
		'brand_color_success'            => '',
		'brand_color_warning'            => '',
		'brand_color_danger'             => '',
		// Off: a disclosure affordance defaults to hiding what it discloses,
		// or it discloses nothing.
		'advanced_enabled'               => false,
		// `aa_acknowledgements` (design spec §4.4/§4.5) is deliberately NOT
		// listed here, for the same reason `_schema` never has been:
		// membership in blueline_settings()'s returned array is decided by
		// presence in THIS array, so a bookkeeping key that must stay out of
		// that return value -- see blueline_settings()'s own docblock
		// (inc/settings/store.php) -- must stay out of this one too. It is
		// still real, protected storage
		// (BLUELINE_SETTINGS_RESERVED_KEYS, inc/settings/page.php;
		// validated by inc/settings/acknowledgements.php's
		// blueline_sanitize_acknowledgements()) -- just never a default
		// value a schema field falls back to.

		// `_validated_against` (design spec §6.5's storage-shape ruling,
		// Phase 2.2) follows the exact same shape as `aa_acknowledgements`
		// immediately above, for the identical reason: nothing reads it
		// back through blueline_settings() (inc/settings/validation.php's
		// blueline_validated_against() reads get_option() directly
		// instead), so it stays out of this array too. Still real,
		// protected storage (BLUELINE_SETTINGS_RESERVED_KEYS,
		// inc/settings/page.php; validated by that same file's
		// blueline_settings_sanitize_callback() reserved-key branch).

		// `occasions` (design spec §5) IS listed here, unlike
		// `aa_acknowledgements` immediately above -- the front-end resolver
		// (Task 3) needs blueline_settings( 'occasions' ) to return
		// something. Its own default is a genuinely empty array: the four
		// shipped presets (blueline_occasion_presets(), Task 2) are a
		// READ-ONLY catalog for a future admin UI, never pre-populated live
		// entries -- design spec §5's second ruling.
		'occasions'                      => array(),

		// Matches the position this theme has always rendered -- installing
		// this field must not move anything for an install that has never
		// opened the Sections tab.
		'chrome_team_directory_position' => 'footer',
	);

	// Every section defaults to enabled: an install that has never opened
	// the Sections tab must look exactly as it did before this tab existed.
	foreach ( blueline_section_definitions() as $key => $unused_def ) {
		$defaults[ $key ] = true;
	}

	return $defaults;
}
