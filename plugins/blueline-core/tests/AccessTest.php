<?php
/**
 * Unit tests for the access module: who counts as a member account, the wp-admin gate, the
 * post-login redirect and the capability trim.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/player-link/player-link.php';
require_once __DIR__ . '/../includes/player-photo/player-photo.php';
require_once __DIR__ . '/../includes/access/access.php';

/**
 * Covers includes/access/access.php.
 */
final class AccessTest extends TestCase {

	private const ACCOUNT_URL = 'https://example.test/account/';

	/**
	 * Every cap the SportsPress `sp_player` role holds on staging (`wp cap list sp_player`).
	 */
	private const SP_PLAYER_CAPS = array(
		'level_1',
		'level_0',
		'read',
		'delete_posts',
		'edit_posts',
		'upload_files',
		'edit_sp_player',
		'read_sp_player',
		'edit_sp_players',
		'edit_published_sp_players',
		'assign_sp_player_terms',
		'edit_sp_event',
		'read_sp_event',
		'edit_sp_events',
		'edit_published_sp_events',
		'assign_sp_event_terms',
		'edit_sp_team',
		'read_sp_team',
		'edit_sp_teams',
		'edit_published_sp_teams',
		'assign_sp_team_terms',
	);

	/**
	 * The caps a member keeps.
	 */
	private const KEPT_CAPS = array( 'level_1', 'level_0', 'read', 'read_sp_player', 'read_sp_event', 'read_sp_team' );

	/**
	 * The upload fixture a test wrote, removed in tearDown().
	 *
	 * @var string
	 */
	private string $temp_file = '';

	/**
	 * Reset stores and request globals; wp-admin, index.php.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_test_is_admin'] = true;
		$GLOBALS['pagenow']          = 'index.php';
	}

	/**
	 * Drop the request globals this suite sets.
	 */
	protected function tearDown(): void {
		$_REQUEST = array();
		$_FILES   = array();
		$_POST    = array();

		if ( '' !== $this->temp_file && file_exists( $this->temp_file ) ) {
			unlink( $this->temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test temp file outside WordPress.
		}
		$this->temp_file = '';

		unset(
			$GLOBALS['pagenow'],
			$GLOBALS['bl_core_test_current_user'],
			$GLOBALS['bl_core_test_doing_cron'],
			$GLOBALS['bl_core_test_rest_request'],
			$GLOBALS['bl_core_test_media_calls'],
			$GLOBALS['bl_core_test_deleted_attachments'],
			$GLOBALS['bl_core_test_referer'],
			$GLOBALS['wpdb']
		);
	}

	/**
	 * A user holding $roles, with every cap of those roles granted.
	 *
	 * @param string[] $roles Role slugs.
	 * @param string[] $extra Extra caps granted to the user directly.
	 * @return Blueline_Core_Test_User
	 */
	private function user( array $roles, array $extra = array() ): Blueline_Core_Test_User {
		$caps = array( 'read' => true );

		if ( in_array( 'sp_player', $roles, true ) ) {
			$caps = array_fill_keys( self::SP_PLAYER_CAPS, true );
		}

		foreach ( $extra as $cap ) {
			$caps[ $cap ] = true;
		}

		return new Blueline_Core_Test_User( 5, $roles, $caps );
	}

	/**
	 * Log $user in for the gate.
	 *
	 * @param Blueline_Core_Test_User $user The user.
	 */
	private function log_in( Blueline_Core_Test_User $user ): void {
		$GLOBALS['bl_core_test_current_user'] = $user;

		$state                    = &blueline_test_state();
		$state['current_user_id'] = $user->ID;
	}

	/**
	 * Run the gate; return the redirect target or null when it let the request through.
	 *
	 * @return string|null
	 */
	private function gate_target(): ?string {
		try {
			blueline_core_block_wp_admin();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		return null;
	}

	/**
	 * Role sets that are member accounts and kept out of wp-admin.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function restricted_role_sets(): array {
		return array(
			'player'              => array( array( 'sp_player' ) ),
			'customer'            => array( array( 'customer' ) ),
			'subscriber'          => array( array( 'subscriber' ) ),
			'player and customer' => array( array( 'sp_player', 'customer' ) ),
		);
	}

	/**
	 * Role sets that include a staff role and are never restricted.
	 *
	 * @return array<string, array{0: string[]}>
	 */
	public static function staff_role_sets(): array {
		return array(
			'administrator'          => array( array( 'administrator' ) ),
			'ARL Board'              => array( array( 'arl_board' ) ),
			'player and ARL Board'   => array( array( 'sp_player', 'arl_board' ) ),
			'player and staff'       => array( array( 'sp_player', 'sp_staff' ) ),
			'customer and league'    => array( array( 'customer', 'sp_league_manager' ) ),
			'player and shop man.'   => array( array( 'sp_player', 'shop_manager' ) ),
			'player and event man.'  => array( array( 'sp_player', 'sp_event_manager' ) ),
			'customer and committee' => array( array( 'customer', 'arl_committee' ) ),
		);
	}

	/**
	 * A user whose every role is a member role is restricted.
	 *
	 * @param string[] $roles Role slugs.
	 */
	#[DataProvider( 'restricted_role_sets' )]
	public function test_member_only_role_sets_are_restricted( array $roles ): void {
		$this->assertTrue( blueline_core_user_is_restricted( $this->user( $roles ) ) );
	}

	/**
	 * Any staff role alongside (or instead of) a member role lifts the restriction.
	 *
	 * @param string[] $roles Role slugs.
	 */
	#[DataProvider( 'staff_role_sets' )]
	public function test_a_staff_role_is_never_restricted( array $roles ): void {
		$this->assertFalse( blueline_core_user_is_restricted( $this->user( $roles ) ) );
	}

	/**
	 * A member-role user granted a staff capability directly is not restricted.
	 */
	public function test_a_staff_capability_on_a_member_account_lifts_the_restriction(): void {
		foreach ( array( 'edit_others_posts', 'manage_options', 'manage_woocommerce', 'manage_sportspress', 'edit_others_sp_players', 'edit_others_sp_events' ) as $cap ) {
			$this->assertFalse( blueline_core_user_is_restricted( $this->user( array( 'sp_player' ), array( $cap ) ) ), $cap );
		}
	}

	/**
	 * Logged out, roleless or not a user at all: not restricted (nothing to trim or redirect).
	 */
	public function test_logged_out_or_roleless_users_are_not_restricted(): void {
		$this->assertFalse( blueline_core_user_is_restricted( new Blueline_Core_Test_User( 0, array( 'sp_player' ) ) ) );
		$this->assertFalse( blueline_core_user_is_restricted( new Blueline_Core_Test_User( 5, array() ) ) );
		$this->assertFalse( blueline_core_user_is_restricted( null ) );
		$this->assertFalse( blueline_core_user_is_restricted( new WP_Error( 'x', 'y' ) ) );
	}

	/**
	 * The member role list is filterable.
	 */
	public function test_the_restricted_role_list_is_filterable(): void {
		$this->assertSame( array( 'sp_player', 'customer', 'subscriber' ), blueline_core_wp_admin_blocked_roles() );

		add_filter(
			'blueline_core_wp_admin_blocked_roles',
			static function ( $roles ) {
				return array_merge( array_diff( $roles, array( 'customer' ) ), array( 'author' ) );
			}
		);

		$this->assertFalse( blueline_core_user_is_restricted( $this->user( array( 'customer' ) ) ) );
		$this->assertTrue( blueline_core_user_is_restricted( $this->user( array( 'author' ) ) ) );
	}

	/**
	 * A filter that returns junk falls back to the default list instead of restricting nobody.
	 */
	public function test_a_broken_role_filter_keeps_the_default_list(): void {
		add_filter( 'blueline_core_wp_admin_blocked_roles', '__return_false' );

		$this->assertSame( array( 'sp_player', 'customer', 'subscriber' ), blueline_core_wp_admin_blocked_roles() );
	}

	/**
	 * The gate runs early on admin_init.
	 */
	public function test_the_gate_and_the_trim_are_hooked(): void {
		$this->assertSame( 1, has_action( 'admin_init', 'blueline_core_block_wp_admin' ) );
		$this->assertSame( 1, has_action( 'admin_page_access_denied', 'blueline_core_block_wp_admin' ) );
		$this->assertNotFalse( has_filter( 'user_has_cap', 'blueline_core_trim_member_caps' ) );
		$this->assertNotFalse( has_filter( 'login_redirect', 'blueline_core_member_login_redirect' ) );
	}

	/**
	 * A member opening wp-admin is sent to the account page.
	 *
	 * @param string[] $roles Role slugs.
	 */
	#[DataProvider( 'restricted_role_sets' )]
	public function test_a_member_is_redirected_from_wp_admin_to_the_account_page( array $roles ): void {
		$this->log_in( $this->user( $roles ) );

		$this->assertSame( self::ACCOUNT_URL, $this->gate_target() );
	}

	/**
	 * Every wp-admin screen is covered, the editor and media screens included.
	 */
	public function test_the_editor_and_media_screens_are_covered(): void {
		$this->log_in( $this->user( array( 'sp_player' ) ) );

		foreach ( array( 'post-new.php', 'post.php', 'media-new.php', 'upload.php', 'profile.php', 'async-upload.php' ) as $screen ) {
			$GLOBALS['pagenow'] = $screen;
			$this->assertSame( self::ACCOUNT_URL, $this->gate_target(), $screen );
		}
	}

	/**
	 * Staff (including a player who is also on the ARL Board) still enter wp-admin.
	 *
	 * @param string[] $roles Role slugs.
	 */
	#[DataProvider( 'staff_role_sets' )]
	public function test_staff_enter_wp_admin( array $roles ): void {
		$this->log_in( $this->user( $roles ) );

		$this->assertNull( $this->gate_target() );
	}

	/**
	 * Fire core's admin_page_access_denied action (wp-admin/includes/menu.php runs it, before
	 * admin_init and just before its own 403 page, for a screen the user lacks the cap for);
	 * return the redirect target or null when nothing redirected.
	 *
	 * @return string|null
	 */
	private function denied_target(): ?string {
		try {
			do_action( 'admin_page_access_denied' );
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		return null;
	}

	/**
	 * The screens a trimmed member lacks the cap for. Core refuses them in menu.php before
	 * admin_init fires, so the gate must also run on the denial path.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function denied_screens(): array {
		$screens = array();
		foreach ( array( 'post-new.php', 'media-new.php', 'upload.php', 'edit.php', 'options-general.php', 'tools.php' ) as $screen ) {
			$screens[ $screen ] = array( $screen );
		}

		return $screens;
	}

	/**
	 * A member denied a screen by core is sent to the account page, not core's 403 page.
	 *
	 * @param string $screen The wp-admin screen.
	 */
	#[DataProvider( 'denied_screens' )]
	public function test_a_member_denied_a_screen_is_redirected_to_the_account_page( string $screen ): void {
		$GLOBALS['pagenow'] = $screen;

		foreach ( self::restricted_role_sets() as $label => $set ) {
			$this->log_in( $this->user( $set[0] ) );
			$this->assertSame( self::ACCOUNT_URL, $this->denied_target(), $label );
		}
	}

	/**
	 * Staff and mixed-role users denied a screen keep core's own answer (no redirect), and the
	 * handler endpoints and non-screen requests are never redirected on the denial path either.
	 */
	public function test_the_denial_path_leaves_staff_and_non_screen_requests_alone(): void {
		$GLOBALS['pagenow'] = 'options-general.php';

		foreach ( self::staff_role_sets() as $label => $set ) {
			$this->log_in( $this->user( $set[0] ) );
			$this->assertNull( $this->denied_target(), $label );
		}

		$this->log_in( $this->user( array( 'sp_player' ) ) );

		foreach ( array( 'admin-post.php', 'admin-ajax.php' ) as $endpoint ) {
			$GLOBALS['pagenow'] = $endpoint;
			$this->assertNull( $this->denied_target(), $endpoint );
		}

		$GLOBALS['pagenow']                   = 'upload.php';
		$GLOBALS['bl_core_test_rest_request'] = true;
		$this->assertNull( $this->denied_target(), 'REST' );

		unset( $GLOBALS['bl_core_test_rest_request'] );
		$GLOBALS['bl_core_test_doing_cron'] = true;
		$this->assertNull( $this->denied_target(), 'cron' );
	}

	/**
	 * The front-end handlers members rely on run through admin-post.php and admin-ajax.php, both of
	 * which fire admin_init; they are let through, as are REST, cron and AJAX requests.
	 */
	public function test_handler_endpoints_and_non_screen_requests_are_let_through(): void {
		$this->log_in( $this->user( array( 'sp_player' ) ) );

		foreach ( array( 'admin-post.php', 'admin-ajax.php' ) as $endpoint ) {
			$GLOBALS['pagenow'] = $endpoint;
			$this->assertNull( $this->gate_target(), $endpoint );
		}

		$GLOBALS['pagenow']            = 'index.php';
		$GLOBALS['bl_test_doing_ajax'] = true;
		$this->assertNull( $this->gate_target(), 'AJAX' );

		unset( $GLOBALS['bl_test_doing_ajax'] );
		$GLOBALS['bl_core_test_doing_cron'] = true;
		$this->assertNull( $this->gate_target(), 'cron' );

		unset( $GLOBALS['bl_core_test_doing_cron'] );
		$GLOBALS['bl_core_test_rest_request'] = true;
		$this->assertNull( $this->gate_target(), 'REST' );
	}

	/**
	 * WP-CLI is never redirected (a process of its own, since the constant cannot be undefined).
	 */
	#[RunInSeparateProcess]
	public function test_wp_cli_is_let_through(): void {
		define( 'WP_CLI', true );
		$this->log_in( $this->user( array( 'sp_player' ) ) );

		$this->assertNull( $this->gate_target() );
	}

	/**
	 * Off wp-admin (is_admin() false) the gate does nothing.
	 */
	public function test_the_gate_only_acts_in_wp_admin(): void {
		$GLOBALS['bl_test_is_admin'] = false;
		$this->log_in( $this->user( array( 'sp_player' ) ) );

		$this->assertNull( $this->gate_target() );
	}

	/**
	 * A logged-out request is left to core's own login redirect.
	 */
	public function test_logged_out_requests_are_left_to_core(): void {
		$this->assertNull( $this->gate_target() );
	}

	/**
	 * After login a member headed for wp-admin (core's default) lands on the account page; a
	 * front-end destination they asked for is kept; staff keep wp-admin; a failed login is untouched.
	 */
	public function test_login_redirect_sends_members_to_the_account_page(): void {
		$member = $this->user( array( 'sp_player' ) );
		$admin  = 'https://example.test/wp-admin/';

		$this->assertSame( self::ACCOUNT_URL, blueline_core_member_login_redirect( $admin, '', $member ) );
		$this->assertSame( self::ACCOUNT_URL, blueline_core_member_login_redirect( '', '', $member ) );
		$this->assertSame( self::ACCOUNT_URL, blueline_core_member_login_redirect( 'https://example.test/wp-admin/post-new.php', '', $member ) );
		$this->assertSame( self::ACCOUNT_URL, blueline_core_member_login_redirect( '/wp-admin/profile.php', '', $member ) );
		$this->assertSame( 'https://example.test/register/', blueline_core_member_login_redirect( 'https://example.test/register/', '', $member ) );

		$this->assertSame( $admin, blueline_core_member_login_redirect( $admin, '', $this->user( array( 'arl_board' ) ) ) );

		$failed = new WP_Error( 'incorrect_password', 'x' );
		$this->assertSame( $admin, blueline_core_member_login_redirect( $admin, '', $failed ) );
	}

	/**
	 * A player keeps only reading caps: no posts, uploads, comments moderation, or SportsPress
	 * player/event/team editing. Nothing is added that the role did not have.
	 */
	public function test_a_players_editing_caps_are_trimmed(): void {
		$user    = $this->user( array( 'sp_player' ) );
		$trimmed = blueline_core_trim_member_caps( $user->allcaps, array( 'edit_posts' ), array( 'edit_posts', 5 ), $user );

		$granted = array_keys( array_filter( $trimmed ) );
		sort( $granted );
		$expected = self::KEPT_CAPS;
		sort( $expected );

		$this->assertSame( $expected, $granted );
		$this->assertSame( array_keys( $user->allcaps ), array_keys( $trimmed ), 'No cap is added.' );
	}

	/**
	 * Every editing, publishing, deleting and term cap of posts, pages and every SportsPress post type
	 * is denied to a member, even one granted directly to the user.
	 */
	public function test_every_editing_cap_is_on_the_trim_list(): void {
		$caps = blueline_core_member_trimmed_caps();

		foreach ( array( 'edit_posts', 'publish_posts', 'delete_posts', 'upload_files', 'edit_pages', 'moderate_comments' ) as $cap ) {
			$this->assertContains( $cap, $caps );
		}

		foreach ( array( 'sp_player', 'sp_event', 'sp_team', 'sp_staff', 'sp_list', 'sp_table', 'sp_calendar' ) as $type ) {
			foreach ( array( "edit_{$type}", "edit_{$type}s", "edit_published_{$type}s", "edit_others_{$type}s", "publish_{$type}s", "delete_{$type}s", "delete_published_{$type}s", "assign_{$type}_terms" ) as $cap ) {
				$this->assertContains( $cap, $caps );
			}
			$this->assertNotContains( "read_{$type}", $caps );
		}

		$customer = $this->user( array( 'customer' ), array( 'upload_files' ) );
		$trimmed  = blueline_core_trim_member_caps( $customer->allcaps, array(), array( 'upload_files' ), $customer );
		$this->assertFalse( $trimmed['upload_files'] );
		$this->assertTrue( $trimmed['read'] );
	}

	/**
	 * Staff and mixed-role users keep every cap.
	 */
	public function test_staff_caps_are_never_trimmed(): void {
		$user = $this->user( array( 'sp_player', 'arl_board' ) );

		$this->assertSame( $user->allcaps, blueline_core_trim_member_caps( $user->allcaps, array(), array( 'edit_posts' ), $user ) );
	}

	/**
	 * Junk in, junk out: the filter never fatals on a malformed call.
	 */
	public function test_the_trim_passes_malformed_input_through(): void {
		$this->assertSame( 'x', blueline_core_trim_member_caps( 'x', array(), array(), $this->user( array( 'sp_player' ) ) ) );
		$this->assertSame( array( 'edit_posts' => true ), blueline_core_trim_member_caps( array( 'edit_posts' => true ), array(), array(), null ) );
	}

	/**
	 * Own profile: the player-photo handler still updates the player's own photo when the user holds
	 * only the caps left after the trim (the handler checks ownership, never an editing cap).
	 */
	public function test_a_trimmed_player_can_still_update_their_own_photo(): void {
		$this->seed_trimmed_photo_owner();

		$_FILES['player_photo'] = $this->png_upload();

		$this->assertStringContainsString( 'blueline_photo=updated', $this->photo_redirect() );
		$this->assertSame( array( array( 'player_photo', 100 ) ), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * Another player's post cannot be reached through admin-post: a forged player_id is ignored and
	 * the upload still lands on the submitter's own player.
	 */
	public function test_a_forged_player_id_cannot_target_another_players_post(): void {
		$this->seed_trimmed_photo_owner();

		$state                   = &blueline_test_state();
		$state['posts'][200]     = array(
			'type'   => 'sp_player',
			'author' => 9,
		);
		$state['post_meta'][200] = array( 'sp_user' => '9' );
		$_POST['player_id']      = '200';
		$_REQUEST['player_id']   = '200';
		$_FILES['player_photo']  = $this->png_upload();

		$this->photo_redirect();

		$this->assertSame( array( array( 'player_photo', 100 ) ), $GLOBALS['bl_core_test_media_calls'] );
	}

	/**
	 * A member (customer) whose caps were trimmed can still claim their player by name.
	 */
	public function test_a_trimmed_member_can_still_claim_their_player(): void {
		blueline_core_test_seed_claim_pool( 5, 'Cody Lusk', array( 100 => 'Cody Lusk' ) );
		$GLOBALS['bl_core_test_referer'] = self::ACCOUNT_URL;
		$this->grant_trimmed_caps( array( 'customer' ) );

		$_POST['player_id']    = '100';
		$_REQUEST['player_id'] = '100';
		$_REQUEST['_wpnonce']  = wp_create_nonce( 'blueline_claim_player' );

		try {
			blueline_handle_claim_player_submission();
			$this->fail( 'The handler must redirect.' );
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			$this->assertSame( self::ACCOUNT_URL . '?blueline_claim=linked', $e->location );
		}
	}

	/**
	 * Make user 5 the verified owner of player 100, holding only the caps the trim leaves a player.
	 */
	private function seed_trimmed_photo_owner(): void {
		$GLOBALS['bl_core_test_media_calls']         = array();
		$GLOBALS['bl_core_test_deleted_attachments'] = array();

		$state                    = &blueline_test_state();
		$state['current_user_id'] = 5;
		$state['post_types']      = array( 'sp_player' );
		$state['users'][5]        = (object) array( 'roles' => array( 'sp_player' ) );
		$state['posts'][100]      = array(
			'type'   => 'sp_player',
			'author' => 5,
		);
		$state['post_meta'][100]  = array( 'sp_user' => '5' );
		$_REQUEST['_wpnonce']     = wp_create_nonce( 'blueline_upload_player_photo' );
		$_POST['action']          = 'blueline_upload_player_photo';
		$GLOBALS['pagenow']       = 'admin-post.php';

		$this->grant_trimmed_caps( array( 'sp_player' ) );
	}

	/**
	 * Give the stub current_user_can() exactly the caps a member with $roles keeps after the trim.
	 *
	 * @param string[] $roles Role slugs.
	 */
	private function grant_trimmed_caps( array $roles ): void {
		$user = $this->user( $roles );
		$this->log_in( $user );

		$state         = &blueline_test_state();
		$state['caps'] = array_filter( blueline_core_trim_member_caps( $user->allcaps, array(), array(), $user ) );
	}

	/**
	 * A small valid PNG upload.
	 *
	 * @return array
	 */
	private function png_upload(): array {
		$ihdr  = pack( 'NNCCCCC', 600, 800, 8, 2, 0, 0, 0 );
		$bytes = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . $ihdr . pack( 'N', crc32( 'IHDR' . $ihdr ) );
		$path  = (string) tempnam( sys_get_temp_dir(), 'blaccess' );
		file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture outside WordPress.

		$this->temp_file = $path;

		return array(
			'name'     => 'player.png',
			'type'     => 'image/png',
			'tmp_name' => $path,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $bytes ),
		);
	}

	/**
	 * Run the photo handler; return where it redirected and remove the temp file.
	 *
	 * @return string
	 */
	private function photo_redirect(): string {
		try {
			blueline_handle_player_photo_upload();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		$this->fail( 'The handler must redirect.' );
	}
}
