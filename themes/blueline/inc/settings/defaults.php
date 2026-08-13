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
 * default" rather than "point at this specific post/term").
 *
 * Every `text`/`textarea` field MUST carry a `placeholders` array naming the
 * exact sprintf() conversion specs (e.g. `%s`) its value is required to
 * contain — `array()` for a field whose value never reaches a sprintf()
 * call site. This is enforced, not merely documented:
 * SettingsDefaultsTest::test_every_text_and_textarea_field_declares_a_placeholders_key()
 * fails the build if any `text`/`textarea` field omits the key, and
 * blueline_settings_defaults() must supply a default containing every spec
 * a field declares here (a separate assertion in the same test file). No
 * field declares a non-empty `placeholders` contract yet: every current
 * theme literal with a real sprintf() placeholder is deliberately excluded
 * until Task 8 adds it, one field at a time, against the validator Task 3
 * built. Every existing `text` field below declares `placeholders => array()`
 * in the meantime — not "no contract yet", but "the contract IS zero
 * placeholders" — so a stray "%" in an existing field's value is rejected by
 * inc/settings/sanitize.php's blueline_sanitize_field() today, before Task 8
 * ever exists, rather than only once a future task remembers to protect it.
 *
 * @return array<string, array<string, mixed>>
 */
function blueline_settings_schema(): array {
	return array(
		// Content tab.
		'contact_email'      => array(
			'type'  => 'email',
			'tab'   => 'content',
			'label' => 'Contact email',
		),
		'footer_heading'     => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer column heading',
			'placeholders' => array(),
		),
		'footer_location'    => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Footer location line',
			'placeholders' => array(),
		),
		'hero_offseason_cta' => array(
			'type'         => 'text',
			'tab'          => 'content',
			'label'        => 'Off-season CTA label',
			'placeholders' => array(),
		),
		// Links tab — every value is a page ID; 0 means "use the built-in path".
		'page_schedule'      => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Schedule page',
			'fallback' => '/schedule',
		),
		'page_standings'     => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Standings page',
			'fallback' => '/standings',
		),
		'page_register'      => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Register page',
			'fallback' => '/register',
		),
		'page_faqs'          => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'FAQs page',
			'fallback' => '/faqs',
		),
		'page_news'          => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'News page',
			'fallback' => '/news',
		),
		'page_legal'         => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Legal page',
			'fallback' => '/legal',
		),
		'page_contact'       => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Contact page',
			'fallback' => '/arl-league-info/contact-us',
		),
		'page_equipment'     => array(
			'type'     => 'page_id',
			'tab'      => 'links',
			'label'    => 'Equipment page',
			'fallback' => '/arl-league-info/equipment',
		),
		// Commerce tab.
		'registration_term'  => array(
			'type'     => 'term_id',
			'tab'      => 'commerce',
			'label'    => 'Registration product category',
			'fallback' => 91,
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
		'contact_email'      => 'play@rookiehockey.ca',
		'footer_heading'     => 'The League',
		'footer_location'    => 'Burlington, Ontario',
		'hero_offseason_cta' => 'Join the mailing list',
		'page_schedule'      => 0,
		'page_standings'     => 0,
		'page_register'      => 0,
		'page_faqs'          => 0,
		'page_news'          => 0,
		'page_legal'         => 0,
		'page_contact'       => 0,
		'page_equipment'     => 0,
		'registration_term'  => 0,
	);
}
