<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/cli-stubs.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/settings/acknowledgements.php';
require_once __DIR__ . '/../inc/enqueue.php'; // blueline_stylesheet_version(), which blueline_settings_inputs_hash() calls.
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/occasions.php';
require_once __DIR__ . '/../inc/settings/validation.php';

/**
 * Covers inc/settings/validation.php's deploy-drift check
 * (blueline_occasions_maybe_revalidate_on_drift(), hooked `admin_init`)
 * and its one-time notice (blueline_render_occasions_drift_notice(),
 * hooked `admin_notices`) -- design spec §6.5's hook-choice,
 * one-time-notice, and notice-markup rulings.
 *
 * Both are called DIRECTLY, never via do_action(), matching
 * tests/SettingsStoreTest.php's own precedent for
 * blueline_settings_migrate() (its closest structural analog): a
 * hook-fired test would only prove WordPress dispatches hooks, which is
 * not this suite's job to re-prove.
 */
final class OccasionsDriftTest extends TestCase {

	/**
	 * Reset every in-memory store, AND explicitly clear the drift
	 * notice's same-request relay -- blueline_test_reset() does not know
	 * about that module-level static, since it predates this task.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
		blueline_set_occasions_drift_notice_payload( null );
		unset( $GLOBALS['bl_test_doing_ajax'] );
		unset( $GLOBALS['pagenow'] );
	}

	/**
	 * Clear the AJAX flag again after every test, so a test that models an
	 * AJAX request cannot leak that context into an unrelated one that runs
	 * later in the same process (the reason the stub reads a global at all
	 * rather than only `DOING_AJAX`, which cannot be undefined once set).
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['bl_test_doing_ajax'] );
		unset( $GLOBALS['pagenow'] );
	}

	/**
	 * Grant the fake current user `manage_options` -- the capability the
	 * notice is gated on, matching every other notice in this codebase.
	 */
	private function grant_manage_options(): void {
		$state                           = &blueline_test_state();
		$state['caps']['manage_options'] = true;
	}

	/* ---------------------------------------- maybe_revalidate_on_drift */

	/**
	 * No drift at all (stored `_validated_against` already matches the
	 * current hash) short-circuits before classifying anything: the
	 * notice payload stays unset.
	 */
	public function test_no_drift_short_circuits(): void {
		$hash = blueline_settings_inputs_hash();
		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => $hash ) );

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertNull( blueline_get_occasions_drift_notice_payload() );
	}

	/**
	 * Drift with nothing non-valid to report still updates
	 * `_validated_against` to the current hash -- design spec §6.5's
	 * ruling that the hash updates regardless of outcome.
	 */
	public function test_drift_with_nothing_to_report_still_updates_the_hash(): void {
		update_option( BLUELINE_SETTINGS_OPTION, array( '_validated_against' => 'a-stale-hash' ) );

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertNull( blueline_get_occasions_drift_notice_payload() );
		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
	}

	/**
	 * Drift with a stale acknowledgement sets the notice payload AND
	 * updates the hash, in the same call.
	 */
	public function test_drift_with_a_stale_acknowledgement_sets_the_payload_and_updates_the_hash(): void {
		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'_validated_against'  => 'a-stale-hash',
				'occasions'           => array(
					'canada-day' => array(
						'id'     => 'canada-day',
						'label'  => 'Canada Day',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '07-01',
							'end_md'   => '07-01',
						),
						'accent' => '#274a63',
						'motif'  => 'none',
						'line'   => '',
						'mode'   => 'auto',
					),
				),
				'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-different-stale-hash', 1 ),
			)
		);

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertSame(
			array( 'occasion:canada-day' => 'stale' ),
			blueline_get_occasions_drift_notice_payload()
		);
		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
	}

	/**
	 * An AJAX request skips the entire check: `admin_init` fires on
	 * admin-ajax.php (core calls do_action('admin_init') there directly,
	 * `nopriv` handlers included) but `admin_notices` never does, so a
	 * routine Heartbeat poll from an admin's open wp-admin tab would
	 * otherwise detect the drift, set a payload nothing will ever render,
	 * and advance `_validated_against` past it -- after which the next real
	 * page load's cheap hash-compare short-circuits and the one-time notice
	 * is lost for good.
	 *
	 * The second half is the part that makes skipping SAFE rather than
	 * merely quiet: the same drift, on a non-AJAX call, is still found and
	 * still reported. Skipping defers the report to a request that can
	 * display it; it does not consume or hide it.
	 */
	public function test_an_ajax_request_defers_the_whole_check_to_the_next_page_load(): void {
		$stored = array(
			'_validated_against'  => 'a-stale-hash',
			'occasions'           => array(
				'canada-day' => array(
					'id'     => 'canada-day',
					'label'  => 'Canada Day',
					'type'   => 'decorative',
					'window' => array(
						'start_md' => '07-01',
						'end_md'   => '07-01',
					),
					'accent' => '#274a63',
					'motif'  => 'none',
					'line'   => '',
					'mode'   => 'auto',
				),
			),
			'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-different-stale-hash', 1 ),
		);

		update_option( BLUELINE_SETTINGS_OPTION, $stored );

		$GLOBALS['bl_test_doing_ajax'] = true;

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertNull( blueline_get_occasions_drift_notice_payload(), 'an AJAX request must set no notice payload -- nothing would ever render it' );
		$this->assertSame( 'a-stale-hash', blueline_validated_against(), 'an AJAX request must not advance _validated_against past a drift it cannot report' );

		unset( $GLOBALS['bl_test_doing_ajax'] );

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertSame(
			array( 'occasion:canada-day' => 'stale' ),
			blueline_get_occasions_drift_notice_payload(),
			'the very same drift must still be found on the next non-AJAX call'
		);
		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
	}

	/**
	 * The same story again for `wp-admin/admin-post.php`: it fires
	 * `admin_init` (this codebase's own inc/settings/cache.php already
	 * routes its cache-purge-notice dismissal through an
	 * `admin_post_{$action}` handler, so this is a live path) and then
	 * redirects or exits from inside its own action -- never reaching
	 * `admin_notices` -- so it must defer exactly like an AJAX request
	 * does, and the deferred drift must still be found on the next real
	 * page load.
	 */
	public function test_an_admin_post_request_defers_the_whole_check_to_the_next_page_load(): void {
		$stored = array(
			'_validated_against'  => 'a-stale-hash',
			'occasions'           => array(
				'canada-day' => array(
					'id'     => 'canada-day',
					'label'  => 'Canada Day',
					'type'   => 'decorative',
					'window' => array(
						'start_md' => '07-01',
						'end_md'   => '07-01',
					),
					'accent' => '#274a63',
					'motif'  => 'none',
					'line'   => '',
					'mode'   => 'auto',
				),
			),
			'aa_acknowledgements' => blueline_record_acknowledgement( array(), 'occasion:canada-day', 'ink-on-occasion-accent', '#274a63', 1.66, 'a-different-stale-hash', 1 ),
		);

		update_option( BLUELINE_SETTINGS_OPTION, $stored );

		$GLOBALS['pagenow'] = 'admin-post.php';

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertNull( blueline_get_occasions_drift_notice_payload(), 'an admin-post.php request must set no notice payload -- nothing would ever render it' );
		$this->assertSame( 'a-stale-hash', blueline_validated_against(), 'an admin-post.php request must not advance _validated_against past a drift it cannot report' );

		unset( $GLOBALS['pagenow'] );

		blueline_occasions_maybe_revalidate_on_drift();

		$this->assertSame(
			array( 'occasion:canada-day' => 'stale' ),
			blueline_get_occasions_drift_notice_payload(),
			'the very same drift must still be found on the next non-admin-post call'
		);
		$this->assertSame( blueline_settings_inputs_hash(), blueline_validated_against() );
	}

	/* ---------------------------------------------- the notice itself */

	/**
	 * Nothing renders without `manage_options`, even with a payload set.
	 */
	public function test_notice_renders_nothing_without_manage_options(): void {
		blueline_set_occasions_drift_notice_payload( array( 'occasion:canada-day' => 'stale' ) );

		ob_start();
		blueline_render_occasions_drift_notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * Nothing renders when there is no payload to show, even with the
	 * capability granted.
	 */
	public function test_notice_renders_nothing_when_the_payload_is_empty(): void {
		$this->grant_manage_options();

		ob_start();
		blueline_render_occasions_drift_notice();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/**
	 * A `stale` entry names the occasion's own label (not its raw scope
	 * string) and explains it needs re-review.
	 */
	public function test_notice_names_the_occasion_label_for_a_stale_entry(): void {
		$this->grant_manage_options();

		update_option(
			BLUELINE_SETTINGS_OPTION,
			array(
				'occasions' => array(
					'canada-day' => array(
						'id'     => 'canada-day',
						'label'  => 'Canada Day',
						'type'   => 'decorative',
						'window' => array(
							'start_md' => '07-01',
							'end_md'   => '07-01',
						),
						'accent' => '#274a63',
						'motif'  => 'none',
						'line'   => '',
						'mode'   => 'auto',
					),
				),
			)
		);
		blueline_set_occasions_drift_notice_payload( array( 'occasion:canada-day' => 'stale' ) );

		ob_start();
		blueline_render_occasions_drift_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Canada Day', $html );
		$this->assertStringContainsString( 're-review', $html );
	}

	/**
	 * An `orphaned` entry (its occasion is no longer stored at all) names
	 * the raw scope string instead, and says the occasion no longer
	 * exists.
	 */
	public function test_notice_names_the_raw_scope_for_an_orphaned_entry(): void {
		$this->grant_manage_options();

		blueline_set_occasions_drift_notice_payload( array( 'occasion:ghost' => 'orphaned' ) );

		ob_start();
		blueline_render_occasions_drift_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'occasion:ghost', $html );
		$this->assertStringContainsString( 'no longer exists', $html );
	}

	/**
	 * The markup is a `<ul>` inside a `<section class="notice ...">`,
	 * never a `<div>` -- tests/NoticeDivGuardTest.php enforces the latter
	 * project-wide; this test pins the former's positive shape directly.
	 */
	public function test_notice_markup_is_a_ul_inside_a_section(): void {
		$this->grant_manage_options();
		blueline_set_occasions_drift_notice_payload( array( 'occasion:ghost' => 'orphaned' ) );

		ob_start();
		blueline_render_occasions_drift_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<section class="notice notice-warning">', $html );
		$this->assertStringContainsString( '<ul>', $html );
		$this->assertStringContainsString( '<li>', $html );
		$this->assertStringNotContainsString( '<div', $html );
	}
}
