<?php
/**
 * Unit tests for includes/shared/attachment-usage.php: the single "is this attachment another
 * post's featured image" query that gates every attachment delete, and the shared meta keys.
 *
 * @package blueline-core
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/shared/attachment-usage.php';

/**
 * Pins the SQL itself (the other suites answer the lookup from a closure) and its fail-closed behaviour.
 */
final class AttachmentUsageTest extends TestCase {

	/**
	 * Fake $wpdb recording the prepared SQL and answering get_var() with $row.
	 *
	 * @var object
	 */
	private object $wpdb;

	/**
	 * Install the fake $wpdb.
	 */
	protected function setUp(): void {
		$this->wpdb = new class() {

			/**
			 * Table name.
			 *
			 * @var string
			 */
			public $postmeta = 'wp_postmeta';

			/**
			 * Error of the last query.
			 *
			 * @var string
			 */
			public $last_error = '';

			/**
			 * What get_var() returns: a post ID, or null for no row.
			 *
			 * @var string|null
			 */
			public $row = null;

			/**
			 * The SQL handed to prepare().
			 *
			 * @var string
			 */
			public $sql = '';

			/**
			 * The values handed to prepare().
			 *
			 * @var array
			 */
			public $args = array();

			/**
			 * Record the call; return the raw query.
			 *
			 * @param string $query   SQL with placeholders.
			 * @param mixed  ...$args Values.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				$this->sql  = $query;
				$this->args = $args;

				return $query;
			}

			/**
			 * The canned row.
			 *
			 * @param string $query Unused.
			 * @return string|null
			 */
			public function get_var( $query ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- signature parity with wpdb.
				return $this->row;
			}
		};

		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/**
	 * Remove the fake $wpdb.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * One lookup of the _thumbnail_id rows, excluding the ignored post, parameterised.
	 */
	public function test_it_asks_for_other_posts_thumbnail_rows(): void {
		blueline_attachment_is_thumbnail_elsewhere( 51, 100 );

		$this->assertStringContainsString( "FROM wp_postmeta WHERE meta_key = '_thumbnail_id' AND meta_value = %s AND post_id != %d", $this->wpdb->sql );
		$this->assertSame( array( '51', 100 ), $this->wpdb->args );
	}

	/**
	 * No other post uses it: free to delete.
	 */
	public function test_unused_attachment_is_not_in_use(): void {
		$this->assertFalse( blueline_attachment_is_thumbnail_elsewhere( 51, 100 ) );
	}

	/**
	 * Another post uses it as its featured image.
	 */
	public function test_attachment_used_by_another_post_is_in_use(): void {
		$this->wpdb->row = '200';

		$this->assertTrue( blueline_attachment_is_thumbnail_elsewhere( 51, 100 ) );
	}

	/**
	 * Fail closed: a failed lookup counts as in use, even though it returned no row.
	 */
	public function test_failed_lookup_counts_as_in_use(): void {
		$this->wpdb->last_error = 'Lost connection';

		$this->assertTrue( blueline_attachment_is_thumbnail_elsewhere( 51, 100 ) );
	}

	/**
	 * Fail closed: with no database object at all the attachment counts as in use.
	 */
	public function test_missing_database_object_counts_as_in_use(): void {
		$GLOBALS['wpdb'] = null;

		$this->assertTrue( blueline_attachment_is_thumbnail_elsewhere( 51, 100 ) );
	}

	/**
	 * The shared keys are the literal meta keys stored in the database.
	 */
	public function test_shared_meta_keys(): void {
		$this->assertSame( 'blueline_avatar_id', BLUELINE_AVATAR_META_KEY );
		$this->assertSame( '_blueline_player_photo', BLUELINE_PLAYER_PHOTO_FLAG_META );
		$this->assertSame( 'sp_user', blueline_player_user_meta_key() );
	}
}
