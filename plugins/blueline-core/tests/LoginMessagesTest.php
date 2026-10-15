<?php
/**
 * Unit tests for the generic login and lost-password answers (no "is there an account?" oracle).
 *
 * @package blueline-core
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/access/access.php';

/**
 * Covers includes/access/login-messages.php.
 */
final class LoginMessagesTest extends TestCase {

	private const LOGIN_TEXT = 'The username or password is incorrect.';
	private const RESET_TEXT = "If that account exists, we've emailed a link to reset the password.";

	/**
	 * Reset stores and request globals.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();

		$GLOBALS['bl_core_test_password_hashes'] = 0;
	}

	/**
	 * Drop the request globals this suite sets.
	 */
	protected function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();

		unset( $GLOBALS['pagenow'], $GLOBALS['bl_core_test_password_hashes'] );
	}

	/**
	 * Run a callable; return the redirect target, or null when it returned normally.
	 *
	 * @param callable $run What to run.
	 * @return string|null
	 */
	private function redirect_of( callable $run ): ?string {
		try {
			$run();
		} catch ( Blueline_Test_Redirect_Exception $e ) {
			return $e->location;
		}

		return null;
	}

	/**
	 * The three failures that reveal whether an account exists, as core's authenticators word them.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}> Label => [ code, core message, extra password hashes ].
	 */
	public static function enumerating_failures(): array {
		return array(
			'unknown username' => array( 'invalid_username', '<strong>Error:</strong> The username <strong>nobody</strong> is not registered on this site.', 1 ),
			'unknown email'    => array( 'invalid_email', 'Unknown email address. Check again or try your username.', 1 ),
			'wrong password'   => array( 'incorrect_password', '<strong>Error:</strong> The password you entered for the username <strong>cody</strong> is incorrect.', 0 ),
		);
	}

	/**
	 * Unknown username, unknown email and wrong password all read the same; the code is kept, so
	 * lockout plugins counting failures by code still see what happened.
	 *
	 * @param string $code    Error code.
	 * @param string $message Core's message.
	 * @param int    $hashes  Password hashes the filter adds.
	 */
	#[DataProvider( 'enumerating_failures' )]
	public function test_login_failures_share_one_message( string $code, string $message, int $hashes ): void {
		$result = blueline_core_generic_login_errors( new WP_Error( $code, $message ), 'someone', 'hunter2' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( self::LOGIN_TEXT, $result->get_error_message() );
		$this->assertSame( $hashes, $GLOBALS['bl_core_test_password_hashes'], 'An unknown account costs one password hash, like a known one.' );
	}

	/**
	 * Every other outcome passes through untouched: a lockout or captcha error, the empty-field
	 * errors, a successful login and an undecided (null) one.
	 */
	public function test_other_login_outcomes_pass_through(): void {
		$lockout = new WP_Error( 'itsec-lockout', 'Too many attempts.' );
		$captcha = new WP_Error( 'cfturnstile_error', 'Please verify you are human.' );
		$empty   = new WP_Error( 'empty_password', 'The password field is empty.' );
		$user    = new WP_User( 5 );

		$this->assertSame( $lockout, blueline_core_generic_login_errors( $lockout, 'a', 'b' ) );
		$this->assertSame( $captcha, blueline_core_generic_login_errors( $captcha, 'a', 'b' ) );
		$this->assertSame( $empty, blueline_core_generic_login_errors( $empty, 'a', '' ) );
		$this->assertSame( $user, blueline_core_generic_login_errors( $user, 'a', 'b' ) );
		$this->assertNull( blueline_core_generic_login_errors( null, 'a', 'b' ) );
		$this->assertSame( 0, $GLOBALS['bl_core_test_password_hashes'] );
	}

	/**
	 * The rewrite runs after every authenticator (core, lockout and captcha plugins).
	 */
	public function test_the_hooks_are_registered(): void {
		$this->assertSame( PHP_INT_MAX, has_filter( 'authenticate', 'blueline_core_generic_login_errors' ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'lostpassword_post', 'blueline_core_wc_lost_password_unknown_account' ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'lostpassword_errors', 'blueline_core_lost_password_unknown_account' ) );
		$this->assertSame( 10, has_filter( 'gettext_woocommerce', 'blueline_core_wc_lost_password_notice_text' ) );
		$this->assertSame( 10, has_filter( 'woocommerce_lost_password_confirmation_message', 'blueline_core_wc_lost_password_confirmation_message' ) );
		$this->assertSame( 10, has_filter( 'wp_login_errors', 'blueline_core_login_screen_reset_message' ) );
	}

	/**
	 * WooCommerce form, unknown account: the same redirect WooCommerce sends after a real reset email.
	 */
	public function test_wc_lost_password_for_an_unknown_account_looks_like_success(): void {
		$_POST['wc_reset_password'] = 'true';

		$target = $this->redirect_of(
			static function () {
				blueline_core_wc_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error(), false );
			}
		);

		$this->assertSame( 'https://example.test/account/lost-password/?reset-link-sent=true', $target );
	}

	/**
	 * WooCommerce form: a known account is left to WooCommerce (which sends the email and redirects
	 * the same way), another plugin's error (a captcha) is shown as usual, and a request that is not
	 * the WooCommerce form is ignored.
	 */
	public function test_wc_lost_password_leaves_known_accounts_errors_and_other_forms_alone(): void {
		$_POST['wc_reset_password'] = 'true';

		$this->assertNull(
			$this->redirect_of(
				static function () {
					blueline_core_wc_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error(), new WP_User( 5 ) );
				}
			)
		);
		$this->assertNull(
			$this->redirect_of(
				static function () {
					blueline_core_wc_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error( 'cfturnstile_error', 'Verify.' ), false );
				}
			)
		);

		$_POST = array();
		$this->assertNull(
			$this->redirect_of(
				static function () {
					blueline_core_wc_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error(), false );
				}
			)
		);
	}

	/**
	 * Core wp-login.php, unknown account (with or without core's "no account" error): the same
	 * redirect core sends after a real reset email, honouring redirect_to as core does.
	 */
	public function test_core_lost_password_for_an_unknown_account_looks_like_success(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';

		$unknown_email = new Blueline_Core_Test_Multi_Error( 'invalid_email', 'There is no account with that username or email address.' );
		$this->assertSame(
			'wp-login.php?checkemail=confirm',
			$this->redirect_of(
				static function () use ( $unknown_email ) {
					blueline_core_lost_password_unknown_account( $unknown_email, false );
				}
			)
		);

		$this->assertSame(
			'wp-login.php?checkemail=confirm',
			$this->redirect_of(
				static function () {
					blueline_core_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error(), false );
				}
			),
			'An unknown username (no @) carries no error yet; core adds invalidcombo after this filter.'
		);

		$_REQUEST['redirect_to'] = 'https://example.test/after/';
		$this->assertSame(
			'https://example.test/after/',
			$this->redirect_of(
				static function () {
					blueline_core_lost_password_unknown_account( new Blueline_Core_Test_Multi_Error(), false );
				}
			)
		);
	}

	/**
	 * Core wp-login.php: a known account, an empty field or another plugin's error is returned
	 * unchanged; outside wp-login.php (an admin "send reset link" call) nothing changes.
	 */
	public function test_core_lost_password_leaves_known_accounts_errors_and_other_callers_alone(): void {
		$GLOBALS['pagenow'] = 'wp-login.php';

		$none = new Blueline_Core_Test_Multi_Error();
		$this->assertSame( $none, blueline_core_lost_password_unknown_account( $none, new WP_User( 5 ) ) );

		$empty = new Blueline_Core_Test_Multi_Error( 'empty_username', 'Please enter a username or email address.' );
		$this->assertSame( $empty, blueline_core_lost_password_unknown_account( $empty, false ) );

		$captcha = new Blueline_Core_Test_Multi_Error( 'invalid_email', 'No account.' );
		$captcha->add( 'cfturnstile_error', 'Verify.' );
		$this->assertSame( $captcha, blueline_core_lost_password_unknown_account( $captcha, false ) );

		$GLOBALS['pagenow'] = 'users.php';
		$unknown            = new Blueline_Core_Test_Multi_Error( 'invalid_email', 'No account.' );
		$this->assertSame( $unknown, blueline_core_lost_password_unknown_account( $unknown, false ) );
		$this->assertSame( 'not an error', blueline_core_lost_password_unknown_account( 'not an error', false ) );
	}

	/**
	 * The confirmation WooCommerce shows after a reset request says nothing about whether the
	 * account exists.
	 */
	public function test_wc_confirmation_text_is_generic(): void {
		$this->assertSame( self::RESET_TEXT, blueline_core_wc_lost_password_notice_text( 'Password reset email has been sent.', 'Password reset email has been sent.', 'woocommerce' ) );
		$this->assertSame( 'Lost your password?', blueline_core_wc_lost_password_notice_text( 'Lost your password?', 'Lost your password?', 'woocommerce' ) );

		$paragraph = blueline_core_wc_lost_password_confirmation_message( 'A password reset email has been sent to the email address on file for your account.' );
		$this->assertStringNotContainsString( 'on file for your account', $paragraph );
		$this->assertStringContainsString( '10 minutes', $paragraph );
	}

	/**
	 * Core's "check your email" screen uses the same sentence; other login-screen messages are kept.
	 */
	public function test_core_check_email_screen_uses_the_generic_text(): void {
		$errors = new Blueline_Core_Test_Multi_Error( 'confirm', 'Check your email for the confirmation link, then visit the <a href="x">login page</a>.', 'message' );
		$errors->add( 'loggedout', 'You are now logged out.', 'message' );

		$result = blueline_core_login_screen_reset_message( $errors, '' );

		$this->assertSame( array( self::RESET_TEXT ), $result->get_error_messages( 'confirm' ) );
		$this->assertSame( 'message', $result->get_error_data( 'confirm' ), 'Still shown as a notice, not an error.' );
		$this->assertSame( array( 'You are now logged out.' ), $result->get_error_messages( 'loggedout' ) );

		$this->assertSame( 'x', blueline_core_login_screen_reset_message( 'x', '' ) );
	}
}
