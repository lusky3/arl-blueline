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
 * Field types: `text`, `email`, `page_id`, `term_id`, `bool`, `textarea`.
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
	return array(
		// Content tab.
		'contact_email'              => array(
			'type'  => 'email',
			'tab'   => 'content',
			'label' => 'Contact email',
		),
		'footer_heading'             => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer column heading',
			'placeholders' => array(),
		),
		'footer_location'            => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer location line',
			'placeholders' => array(),
		),
		'hero_offseason_cta'         => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Off-season CTA label',
			'placeholders' => array(),
		),
		// Hero copy carrying a live sprintf() placeholder contract (Task 8).
		// Each label spells out what the placeholder becomes so a volunteer
		// editing the field cannot omit or reorder it without understanding
		// why -- see inc/settings/sanitize.php's blueline_sanitize_field()
		// for what happens if they do anyway.
		'hero_registration_headline' => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — registration open (%s becomes the highlighted word, e.g. "beginner")',
			'placeholders' => array( '%s' ),
		),
		'hero_registration_eyebrow'  => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero eyebrow — registration open (%s becomes the current season label, e.g. "Winter 2026-27")',
			'placeholders' => array( '%s' ),
		),
		'hero_registration_cta'      => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero CTA — registration open, priced (%s becomes the formatted price, e.g. "$550.00")',
			'placeholders' => array( '%s' ),
		),
		'hero_preseason_headline'    => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — preseason (%s becomes the highlighted season-start date, e.g. "September 6")',
			'placeholders' => array( '%s' ),
		),
		'hero_in_season_headline'    => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — in season (%1$s becomes the highlighted game count; %2$s becomes "game" or "games")',
			'placeholders' => array( '%1$s', '%2$s' ),
		),
		'hero_playoffs_eyebrow'      => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero eyebrow — playoffs (%s becomes the current season label)',
			'placeholders' => array( '%s' ),
		),
		'hero_offseason_headline'    => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Hero headline — off-season (%s becomes the highlighted word, e.g. "soon")',
			'placeholders' => array( '%s' ),
		),
		// Links tab — every value is a page ID; 0 means "use the built-in path".
		'page_schedule'              => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Schedule page',
			'fallback' => '/schedule',
		),
		'page_standings'             => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Standings page',
			'fallback' => '/standings',
		),
		'page_register'              => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Register page',
			'fallback' => '/register',
		),
		'page_faqs'                  => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'FAQs page',
			'fallback' => '/faqs',
		),
		'page_news'                  => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'News page',
			'fallback' => '/news',
		),
		'page_legal'                 => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Legal page',
			'fallback' => '/legal',
		),
		'page_contact'               => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Contact page',
			'fallback' => '/arl-league-info/contact-us',
		),
		'page_equipment'             => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Equipment page',
			'fallback' => '/arl-league-info/equipment',
		),
		// Commerce tab.
		'registration_term'          => array(
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
	return array(
		'contact_email'              => 'play@rookiehockey.ca',
		'footer_heading'             => 'The League',
		'footer_location'            => 'Burlington, Ontario',
		'hero_offseason_cta'         => 'Join the mailing list',
		'hero_registration_headline' => 'Burlington’s %s league.',
		'hero_registration_eyebrow'  => '%s · Registration open',
		'hero_registration_cta'      => 'Register — %s',
		'hero_preseason_headline'    => 'Puck drops %s.',
		'hero_in_season_headline'    => '%1$s %2$s this week.',
		'hero_playoffs_eyebrow'      => '%s · Playoffs',
		'hero_offseason_headline'    => 'Back on the ice %s.',
		'page_schedule'              => 0,
		'page_standings'             => 0,
		'page_register'              => 0,
		'page_faqs'                  => 0,
		'page_news'                  => 0,
		'page_legal'                 => 0,
		'page_contact'               => 0,
		'page_equipment'             => 0,
		'registration_term'          => 0,
	);
}
