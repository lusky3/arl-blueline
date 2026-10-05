<?php
/**
 * Unit tests.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-photo/player-photo.php';

/**
 * The legacy "Profile Picture" account tab redirects to Player Profile, so it must not be listed.
 */
final class ProfilePictureMenuTest extends TestCase {

	/**
	 * Only the Profile Picture tab goes; every other item keeps its place and label.
	 */
	public function test_only_the_profile_picture_item_is_removed(): void {
		$items = array(
			'dashboard'       => 'Dashboard',
			'player-profile'  => 'Player Profile',
			'profile-picture' => 'Profile Picture',
			'edit-account'    => 'Change My Details',
		);

		$this->assertSame(
			array(
				'dashboard'      => 'Dashboard',
				'player-profile' => 'Player Profile',
				'edit-account'   => 'Change My Details',
			),
			blueline_remove_profile_picture_menu_item( $items )
		);
	}

	/**
	 * A menu without the item, or an unexpected filter value, comes back unchanged.
	 */
	public function test_other_input_is_returned_unchanged(): void {
		$this->assertSame( array( 'dashboard' => 'Dashboard' ), blueline_remove_profile_picture_menu_item( array( 'dashboard' => 'Dashboard' ) ) );
		$this->assertSame( array(), blueline_remove_profile_picture_menu_item( array() ) );
		$this->assertSame( 'x', blueline_remove_profile_picture_menu_item( 'x' ) );
	}
}
