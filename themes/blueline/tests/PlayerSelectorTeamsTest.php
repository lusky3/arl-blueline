<?php
/**
 * The "Jump to a teammate" selector for a player on two current teams
 * (sportspress/player-selector.php, inc/sportspress/core.php).
 *
 * The template used to look up teammates through the first current team only,
 * so a spare goalie never saw their second team's roster. Teammates are now
 * listed per team, in one list with a group heading for each team when there
 * are several. The list is built by a pure helper so it can be exercised
 * here; the template itself cannot be rendered by this suite (it runs
 * WP_Query through get_posts and SportsPress's term helpers).
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/sportspress.php';

/**
 * See this file's own docblock.
 */
final class PlayerSelectorTeamsTest extends TestCase {

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * A player post as get_posts() returns one, with an optional jersey number.
	 *
	 * @param int         $id     Post ID.
	 * @param string      $name   Post title.
	 * @param string|null $number Jersey number meta, or null for none.
	 * @return object
	 */
	private function player( int $id, string $name, ?string $number = null ): object {
		$state                     = &blueline_test_state();
		$state['posts'][ $id ]     = array(
			'status'    => 'publish',
			'permalink' => 'https://example.test/player/' . $id . '/',
		);
		$state['post_meta'][ $id ] = null === $number ? array() : array( 'sp_number' => $number );

		return (object) array(
			'ID'         => $id,
			'post_title' => $name,
		);
	}

	/**
	 * One team: a plain list, no group heading, numbered players first
	 * (ascending), then the rest by name, and the viewed player selected.
	 */
	public function test_one_team_is_a_plain_sorted_list(): void {
		$groups = array(
			array(
				'label'   => '',
				'players' => array(
					$this->player( 3, 'Zed', null ),
					$this->player( 2, 'Bob', '9' ),
					$this->player( 1, 'Al', '10' ),
					$this->player( 4, 'Cy', null ),
				),
			),
		);

		$result = blueline_sp_player_selector_options( $groups, 2 );

		$this->assertSame( 4, $result['count'] );
		$this->assertStringNotContainsString( '<optgroup', $result['html'] );
		$this->assertMatchesRegularExpression( '#9\. Bob.*10\. Al.*Cy.*Zed#s', $result['html'] );
		$this->assertStringContainsString( 'value="https://example.test/player/2/" selected="selected">9. Bob<', $result['html'] );
		$this->assertSame( 1, substr_count( $result['html'], 'selected="selected"' ) );
	}

	/**
	 * Two teams: a group per team, each with its own roster.
	 */
	public function test_two_teams_get_a_group_each(): void {
		$groups = array(
			array(
				'label'   => 'Kings',
				'players' => array( $this->player( 1, 'Al', '1' ), $this->player( 2, 'Bob', '2' ) ),
			),
			array(
				'label'   => 'Ice Bears',
				'players' => array( $this->player( 2, 'Bob', '2' ), $this->player( 5, 'Eve', '7' ) ),
			),
		);

		$result = blueline_sp_player_selector_options( $groups, 1 );

		$this->assertSame( 4, $result['count'] );
		$this->assertSame( 2, substr_count( $result['html'], '<optgroup' ) );
		$this->assertMatchesRegularExpression( '#<optgroup label="Kings">.*Al.*Bob.*</optgroup><optgroup label="Ice Bears">.*Bob.*Eve.*</optgroup>#s', $result['html'] );
	}

	/**
	 * A group nobody is in is dropped rather than printing an empty heading.
	 */
	public function test_an_empty_team_group_is_dropped(): void {
		$groups = array(
			array(
				'label'   => 'Kings',
				'players' => array( $this->player( 1, 'Al', '1' ) ),
			),
			array(
				'label'   => 'Ice Bears',
				'players' => array(),
			),
		);

		$result = blueline_sp_player_selector_options( $groups, 1 );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 1, substr_count( $result['html'], '<optgroup' ) );
		$this->assertStringNotContainsString( 'Ice Bears', $result['html'] );
	}

	/**
	 * Names and group labels are escaped.
	 */
	public function test_names_and_labels_are_escaped(): void {
		$groups = array(
			array(
				'label'   => 'A <b>&</b> B',
				'players' => array( $this->player( 1, 'Al <script>', '1' ), $this->player( 2, 'Bo', '2' ) ),
			),
		);

		$html = blueline_sp_player_selector_options( $groups, 1 )['html'];

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	/**
	 * The template builds a group per published current team and scopes each
	 * query to that team, instead of reading the first team only.
	 */
	public function test_template_looks_up_every_current_team(): void {
		$src = (string) file_get_contents( __DIR__ . '/../sportspress/player-selector.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading local theme source in a unit test.

		$this->assertStringContainsString( 'blueline_player_published_team_ids( $id )', $src );
		$this->assertStringNotContainsString( 'blueline_player_current_team_id( $id )', $src, 'the first team only' );
		$this->assertStringContainsString( 'blueline_sp_player_selector_options(', $src );
		$this->assertStringContainsString( "'optgroup'", $src, 'the allowlist must let the group headings through' );
	}
}
