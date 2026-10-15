<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/template-tags.php';
require_once __DIR__ . '/../inc/account/player-data.php';
require_once __DIR__ . '/../inc/account/dashboard.php';
require_once __DIR__ . '/../inc/account/player-profile.php';

/**
 * Covers Player Profile's pure per-field display logic and the photo form.
 */
final class PlayerProfileTest extends TestCase {

	/**
	 * When a body exceeds post_max_size PHP discards every POST field, the hidden action
	 * included, and admin-post.php then fires no handler at all: a blank page. The action
	 * must also travel in the form's URL so the upload handler still runs and can send the
	 * member back with the too-large notice.
	 */
	public function test_photo_form_names_its_action_in_the_url_and_the_body(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		ob_start();
		blueline_render_player_profile_bio_section( 100, true );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'#<form class="bl-player-profile__photo-form" method="post" action="https://example\.test/wp-admin/admin-post\.php\?action=blueline_upload_player_photo"#',
			$html
		);
		$this->assertStringContainsString( '<input type="hidden" name="action" value="blueline_upload_player_photo">', $html );
	}

	/**
	 * An empty stored value (get_user_meta()'s own "not set" return) must
	 * show a "not provided" fallback, not a blank value next to its label.
	 */
	public function test_empty_value_falls_back_to_not_provided(): void {
		$this->assertSame( 'Not provided', blueline_player_profile_field_display( '' ) );
	}

	/**
	 * A real stored value passes through unchanged.
	 */
	public function test_real_value_passes_through_unchanged(): void {
		$this->assertSame( '4 - Beginner – Intermediate', blueline_player_profile_field_display( '4 - Beginner – Intermediate' ) );
	}

	/**
	 * The exact six fields this tab reads, and their labels -- documented
	 * here as a single source of truth so a future edit can't silently
	 * drop or relabel one without a test noticing.
	 */
	public function test_registration_fields_are_the_expected_six(): void {
		$fields = blueline_player_profile_registration_fields();

		$this->assertSame(
			array(
				'arl_division'          => 'Skill level',
				'arl_position'          => 'Position',
				'arl_jerseysize'        => 'Jersey size',
				'arl_gender'            => 'Gender',
				'arl_emergency_contact' => 'Emergency contact',
				'arl_emergency_number'  => 'Emergency contact number',
			),
			$fields
		);
	}
}
