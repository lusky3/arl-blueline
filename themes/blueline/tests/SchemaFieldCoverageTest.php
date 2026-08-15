<?php
/**
 * Guards against the class of bug this fix round (Task 8, round 1) exists
 * to catch: a schema field (inc/settings/defaults.php) an admin can edit,
 * save successfully ("Settings saved."), and that changes NOTHING on the
 * public site because no template ever actually reads it. That is the worst
 * shape a settings bug can take -- it looks like it worked.
 *
 * Concretely: Task 1 added `footer_heading`, `footer_location`,
 * `contact_email` and `hero_offseason_cta` to the schema. Task 5 wired the
 * eight `page_id` fields (via blueline_resolve_link()). Task 8 wired the
 * seven placeholder-bearing hero fields directly. Nobody wired Task 1's
 * four plain content fields at all -- and the gap was invisible to the
 * existing suite, because every test exercises functions/templates
 * directly with values it supplies itself; nothing was asserting that the
 * schema-to-template link the panel promises an admin actually exists.
 *
 * Modelled on tests/IncRequireCoverageTest.php's own approach for the
 * identical reason: read the schema at runtime (not a hardcoded field
 * list, which would carry the exact same blind spot for the next new
 * field) and scan the theme's real PHP source for a literal call reading
 * each key, rather than trusting that "a test imports the file" means "a
 * template reads the field".
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';

/**
 * Schema keys explicitly exempted from this guard -- with the reason, and
 * the commitment that this list is temporary scaffolding, not a permanent
 * escape hatch. An entry here is a documented, visible gap (this test still
 * names it every run, via test_exempt_keys_are_real_schema_fields() AND
 * test_exempt_keys_do_not_yet_have_a_real_consumer() below), never a silent
 * one.
 *
 * Was empty as of P1a's Task 9 (`registration_term` was the last entry
 * removed, once blueline_resolve_registration_term() started reading it).
 * The P1b-panel-completion plan's Task 1 (inc/settings/sections.php) adds a
 * new, deliberate batch: the 12 `section` presence-toggle keys generated
 * from blueline_section_definitions(). Task 1 is explicitly scoped as
 * foundation only -- the schema, the sanitizer branch, and the ONE read
 * accessor (blueline_section_enabled()) -- and wires zero consumers itself;
 * that is later tasks' job (the homepage modules, the account cards, the
 * site chrome each call blueline_section_enabled() with their own literal
 * key once they exist). So today every one of these 12 keys is genuinely
 * unread anywhere in the theme's real source, which is exactly what this
 * guard is supposed to catch -- hence the exemption, rather than either
 * fabricating a call site that doesn't belong to this task or weakening the
 * guard to stop looking.
 *
 * This is NOT a silent escape hatch, for two independent reasons:
 *
 * 1. test_every_schema_field_has_a_real_consumer() below now also recognises
 *    `blueline_section_enabled( 'key' )` as a valid consumer for a
 *    `section`-typed field -- mirroring the existing `blueline_resolve_link()`
 *    indirection already carved out for `page_id` fields. The day a later
 *    task adds that literal call for one of these keys, the guard sees it
 *    on its own; the exemption below is not what will eventually clear it.
 * 2. test_exempt_keys_do_not_yet_have_a_real_consumer() is the actual
 *    forcing function this list needs: it fails the moment any exempted key
 *    ALREADY has a real consumer somewhere in the theme's source, which can
 *    only happen if a later task wired one without also removing that key
 *    from this list -- i.e. the list going stale is a red build, not a
 *    silent no-op.
 *
 * Remove each key from this list as the task that wires its consumer lands.
 */
const BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS = array(
	'module_next_games',
	'module_standings_snippet',
	'module_new_here',
	'module_latest_news',
	'chrome_sponsors',
	'chrome_utility_nav',
	'chrome_footer_trust',
	'chrome_footer_teams',
	'account_next_game',
	'account_my_team',
	'account_season_stats',
	'account_registration',
);

/**
 * Fails, naming every offending field, if any schema key is never read
 * anywhere in the theme's real (non-test) PHP source -- either directly via
 * `blueline_settings( 'key' )`, or via one of two established per-type
 * indirections: `blueline_resolve_link( 'key' )` for a `page_id` field
 * (inc/settings/links.php's own resolver -- see Task 5), or
 * `blueline_section_enabled( 'key' )` for a `section` field
 * (inc/settings/sections.php's own accessor -- see Task 1). Both
 * indirections exist for the identical reason: so every consumer shares one
 * resolver/guard rather than each repeating its own fallback or unknown-key
 * logic, and both are recognised here the same way -- a literal call naming
 * this exact key, for a field of the matching type.
 *
 * Deliberately NOT restricted to inc/ (unlike IncRequireCoverageTest): a
 * field's real consumer can be any theme template -- footer.php,
 * template-homepage.php, a woocommerce/ override -- so this scans the
 * whole theme root.
 */
final class SchemaFieldCoverageTest extends TestCase {

	/**
	 * Every .php file's source under the theme root, concatenated, with
	 * tests/, vendor/, node_modules/ excluded and every comment token
	 * discarded first -- a commented-out call must not count as "read",
	 * for the identical reason IncRequireCoverageTest's own docblock gives.
	 *
	 * @return string
	 */
	private function theme_source(): string {
		$root     = dirname( __DIR__ );
		$excluded = array( '/vendor/', '/node_modules/', '/tests/', '/.git/' );
		$combined = '';

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$path = $file->getPathname();

			foreach ( $excluded as $skip ) {
				if ( false !== strpos( $path, $skip ) ) {
					continue 2;
				}
			}

			$src = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test; wp_remote_get() is for HTTP.

			foreach ( token_get_all( $src ) as $token ) {
				if ( is_array( $token ) ) {
					if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
						continue; // Drop comments -- a commented-out call must not count as "referenced".
					}
					$combined .= $token[1];
				} else {
					$combined .= $token;
				}
			}
			$combined .= "\n";
		}

		return $combined;
	}

	/**
	 * Whether $key has a real, literal consumer in $source -- either a
	 * direct `blueline_settings( 'key' )` call, or one of the two
	 * established per-type indirections: `blueline_resolve_link( 'key' )`
	 * for a `page_id` field, or `blueline_section_enabled( 'key' )` for a
	 * `section` field. Shared by the guard itself
	 * (test_every_schema_field_has_a_real_consumer()) and the exemption
	 * list's own forcing function (test_exempt_keys_do_not_yet_have_a_real_consumer())
	 * so the two can never quietly drift onto different definitions of
	 * "consumed".
	 *
	 * @param string $key    Schema key to look for.
	 * @param array  $field  That key's schema entry (only `type` is read).
	 * @param string $source theme_source()'s combined, comment-stripped source.
	 * @return bool
	 */
	private function field_has_a_real_consumer( string $key, array $field, string $source ): bool {
		$quoted_key = preg_quote( $key, '/' );

		if ( preg_match( '/blueline_settings\(\s*[\'"]' . $quoted_key . '[\'"]\s*\)/', $source ) ) {
			return true;
		}

		if ( 'page_id' === ( $field['type'] ?? '' )
			&& preg_match( '/blueline_resolve_link\(\s*[\'"]' . $quoted_key . '[\'"]\s*\)/', $source ) ) {
			return true;
		}

		if ( 'section' === ( $field['type'] ?? '' )
			&& preg_match( '/blueline_section_enabled\(\s*[\'"]' . $quoted_key . '[\'"]\s*\)/', $source ) ) {
			return true;
		}

		return false;
	}

	/**
	 * The guard itself: every non-exempt schema key must have a real,
	 * literal consumer somewhere in the theme's live source.
	 */
	public function test_every_schema_field_has_a_real_consumer(): void {
		$source = $this->theme_source();
		$schema = blueline_settings_schema();
		$this->assertNotEmpty( $schema );

		$missing = array();

		foreach ( $schema as $key => $field ) {
			if ( in_array( $key, BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS, true ) ) {
				continue;
			}

			if ( $this->field_has_a_real_consumer( $key, $field, $source ) ) {
				continue;
			}

			$missing[] = $key;
		}

		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			'these schema fields exist but nothing in the theme reads them -- an admin can edit and save them, see "Settings saved.", and nothing on the public site will change'
		);
	}

	/**
	 * The exemption list must name only real, current schema keys -- a typo
	 * or a stale entry (a field since renamed or removed) would silently
	 * widen this guard's blind spot instead of narrowing it to exactly the
	 * documented, temporary gaps. The list is non-empty as of P1b's Task 1
	 * (see the const's own docblock for why), so this loop carries real
	 * assertions on every run; the addToAssertionCount() fallback exists only
	 * so a future run where the list is empty again does not get flagged by
	 * PHPUnit as "risky: no assertions" instead of genuinely passing.
	 */
	public function test_exempt_keys_are_real_schema_fields(): void {
		$schema = blueline_settings_schema();

		foreach ( BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $schema, "exempt key '$key' is not (or no longer) a real schema field" );
		}

		if ( array() === BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS ) {
			$this->addToAssertionCount( 1 );
		}
	}

	/**
	 * The forcing function that keeps BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS
	 * from going stale and permanent (the coordinator's fix-round-1 finding
	 * on this file): fails, naming the offender, the moment any exempted key
	 * ALREADY has a real consumer somewhere in the theme's live source. That
	 * can only happen if a later task wired blueline_section_enabled() (or
	 * blueline_settings(), or blueline_resolve_link()) for that key without
	 * also removing it from the exemption list above -- i.e. the exemption
	 * going stale is a red build here, not a silent gap this guard stops
	 * noticing.
	 *
	 * The addToAssertionCount() fallback exists for the same reason as
	 * test_exempt_keys_are_real_schema_fields()'s own: once every key is
	 * eventually removed from the list, an empty foreach must still report a
	 * real, passing assertion rather than PHPUnit flagging the test "risky:
	 * no assertions".
	 */
	public function test_exempt_keys_do_not_yet_have_a_real_consumer(): void {
		$source = $this->theme_source();
		$schema = blueline_settings_schema();

		$stale = array();
		foreach ( BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS as $key ) {
			if ( isset( $schema[ $key ] ) && $this->field_has_a_real_consumer( $key, $schema[ $key ], $source ) ) {
				$stale[] = $key;
			}
		}

		sort( $stale );

		$this->assertSame(
			array(),
			$stale,
			'these keys are still listed as exempt but already have a real consumer -- remove them from BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS now that a task has wired them'
		);

		if ( array() === BLUELINE_SCHEMA_COVERAGE_EXEMPT_KEYS ) {
			$this->addToAssertionCount( 1 );
		}
	}

	/**
	 * Proves the guard actually catches the exact bug it exists for, not
	 * just a synthetic pass: fed a schema containing one field with no
	 * matching call anywhere in the real theme source, it fails and names
	 * that field -- the same shape `footer_heading` et al. were in before
	 * this fix round. Exercises the matching logic directly (not by
	 * temporarily deleting a real call site from disk, which would leave
	 * the working tree in a broken state between test runs).
	 */
	public function test_guard_fails_and_names_an_unwired_field(): void {
		$source = $this->theme_source();

		// 'contact_email' stands in for the "wired" side of the contrast --
		// it really is read via a literal blueline_settings() call
		// somewhere in the theme (inc/template-tags.php, this fix round).
		// 'an_unwired_field' is invented for this test and, by construction,
		// appears nowhere.
		$fake_schema = array(
			'contact_email'    => array( 'type' => 'email' ),
			'an_unwired_field' => array( 'type' => 'text' ),
		);

		// Sanity-check the premise before trusting the assertion below.
		$this->assertMatchesRegularExpression( '/blueline_settings\(\s*[\'"]contact_email[\'"]\s*\)/', $source );
		$this->assertDoesNotMatchRegularExpression( '/blueline_settings\(\s*[\'"]an_unwired_field[\'"]\s*\)/', $source );

		$missing = array();
		foreach ( array_keys( $fake_schema ) as $key ) {
			$quoted = preg_quote( $key, '/' );
			if ( ! preg_match( '/blueline_settings\(\s*[\'"]' . $quoted . '[\'"]\s*\)/', $source ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame( array( 'an_unwired_field' ), $missing, 'the guard must name exactly the unwired field, nothing else' );
	}
}
