<?php
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/wp-html-api.php';
require_once __DIR__ . '/../inc/settings/defaults.php';
require_once __DIR__ . '/../inc/settings/sections.php';
require_once __DIR__ . '/../inc/settings/store.php';
require_once __DIR__ . '/../inc/team-colors.php';
require_once __DIR__ . '/../inc/sportspress.php';

/**
 * The signed-in viewer's own team row in a standings table, rendered through
 * the real sportspress/league-table.php.
 *
 * The SportsPress Events Manager plugin's [arl_standings] caches one rendered
 * copy of the table for every visitor and turns on
 * 'spem_standings_shared_render' while it renders it; it then runs
 * 'spem_standings_html' on every request. The copy rendered with the flag on
 * must carry no viewer's row, and each viewer must get only their own row
 * back from that one copy. The theme's own pages never set the flag and mark
 * the row while rendering, as before.
 *
 * Rendering the template needs SP_League_Table and the plugin's
 * blueline_current_user_team_ids(), so those tests run in a separate process
 * with tests/fixtures/standings-table-stubs.php.
 */
final class StandingsViewerRowsTest extends TestCase {

	private const TREBLEMAKERS = 101;
	private const TRAIN_WRECK  = 102;
	private const HAMMERS      = 103;

	/**
	 * Reset the fake WordPress state.
	 */
	protected function setUp(): void {
		blueline_test_reset();
		blueline_test_reset_state();
	}

	/**
	 * One shared copy, three viewers: A and B each get only their own row,
	 * a logged-out visitor gets none, and the copy itself carries no viewer's
	 * row even though A was signed in while it rendered.
	 */
	#[RunInSeparateProcess]
	public function test_one_shared_copy_serves_each_viewer_only_their_own_row(): void {
		$this->load_fixture();

		$shared = static function () {
			return true;
		};
		add_filter( 'spem_standings_shared_render', $shared );
		$GLOBALS['bl_test_viewer_team_ids'] = array( self::TREBLEMAKERS );
		$payload                            = $this->render_table();
		remove_filter( 'spem_standings_shared_render', $shared );

		$this->assertStringNotContainsString( 'bl-sp-row--mine', $payload );
		$this->assertStringNotContainsString( '--bl-team-primary', $payload );
		foreach ( array( self::TREBLEMAKERS, self::TRAIN_WRECK, self::HAMMERS ) as $team_id ) {
			$this->assertStringContainsString( 'data-team-id="' . $team_id . '"', $payload );
			$this->assertStringContainsString( 'hidden', $this->row( $payload, $team_id ), 'every row carries the hidden label the viewer pass reveals' );
		}

		$viewer_a = $this->as_viewer( $payload, array( self::TREBLEMAKERS ) );
		$viewer_b = $this->as_viewer( $payload, array( self::TRAIN_WRECK ) );
		$anon     = $this->as_viewer( $payload, array() );

		$this->assert_mine( $viewer_a, self::TREBLEMAKERS, '#cc0000' );
		$this->assert_not_mine( $viewer_a, self::TRAIN_WRECK );
		$this->assert_not_mine( $viewer_a, self::HAMMERS );

		$this->assert_mine( $viewer_b, self::TRAIN_WRECK, '#0033aa' );
		$this->assert_not_mine( $viewer_b, self::TREBLEMAKERS );
		$this->assert_not_mine( $viewer_b, self::HAMMERS );

		$this->assertSame( $payload, $anon, 'a logged-out visitor gets the shared copy untouched' );
		$this->assertStringNotContainsString( 'bl-sp-row--mine', $anon );
	}

	/**
	 * The theme's own standings pages (no plugin flag) mark the viewer's row
	 * while rendering, exactly as before, and print no hidden placeholders.
	 */
	#[RunInSeparateProcess]
	public function test_own_page_without_the_plugin_marks_the_row_while_rendering(): void {
		$this->load_fixture();

		$GLOBALS['bl_test_viewer_team_ids'] = array( self::TRAIN_WRECK );
		$html                               = $this->render_table();

		$this->assert_mine( $html, self::TRAIN_WRECK, '#0033aa' );
		$this->assert_not_mine( $html, self::TREBLEMAKERS );
		$this->assertStringNotContainsString( 'hidden', $html );
		$this->assertStringContainsString( 'data-team-id="' . self::TREBLEMAKERS . '"', $html );
	}

	/**
	 * Header cells are column headers, abbreviations carry their expansion
	 * (also as text, which DataTables copies into its sort label), the
	 * table is named by its visible heading, and the Record column points at
	 * a legend explaining W-L-T-OTL.
	 */
	#[RunInSeparateProcess]
	public function test_table_semantics_and_record_legend(): void {
		$this->load_fixture();

		$html = $this->render_table();

		$this->assertDoesNotMatchRegularExpression( '/<th\b(?![^>]*scope="col")[^>]*>/', $html, 'every header cell is scope="col"' );
		$this->assertStringContainsString( '<abbr title="Games played">GP</abbr>', $html );
		$this->assertStringContainsString( '<abbr title="Points">PTS</abbr>', $html );
		$this->assertStringContainsString( '(games played)', $html );

		$this->assertMatchesRegularExpression( '/<h[23] class="sp-table-caption" id="([^"]+)">.*<table[^>]*aria-labelledby="\1"/s', $html );

		$this->assertMatchesRegularExpression( '/<th[^>]*aria-describedby="([^"]+)"[^>]*>Record<\/th>.*<p class="bl-sp-standings-legend" id="\1">/s', $html );
		$this->assertStringContainsString( 'W-L-T-OTL', $html );
		$this->assertStringContainsString( 'wins, losses, ties, overtime losses', $html );
	}

	/**
	 * The "show full stats" disclosure's checkbox takes its starting state from the
	 * `standings_extra_stats_default` setting: closed as shipped, open when it is on.
	 */
	#[RunInSeparateProcess]
	public function test_the_full_stats_disclosure_starts_open_only_when_the_setting_is_on(): void {
		$this->load_fixture();

		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*class="bl-sp-standings-toggle-input"(?![^>]*checked)[^>]*>/', $this->render_table(), 'ships off' );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'standings_extra_stats_default' => true ) );

		$this->assertMatchesRegularExpression( '/<input type="checkbox"[^>]*class="bl-sp-standings-toggle-input" checked="checked">/', $this->render_table() );

		update_option( BLUELINE_SETTINGS_OPTION, array( 'standings_extra_stats_default' => false ) );

		$this->assertDoesNotMatchRegularExpression( '/bl-sp-standings-toggle-input"[^>]*checked/', $this->render_table() );
	}

	/**
	 * No title: a visually hidden caption names the table instead.
	 */
	#[RunInSeparateProcess]
	public function test_untitled_table_gets_a_hidden_caption(): void {
		$this->load_fixture();

		$html = $this->render_table( '' );

		$this->assertStringContainsString( '<caption class="screen-reader-text">Standings</caption>', $html );
		$this->assertStringNotContainsString( 'aria-labelledby', $html );
	}

	/**
	 * The 'spem_standings_html' callback is registered with all three
	 * arguments the plugin passes.
	 */
	public function test_viewer_callback_is_registered_for_the_plugin_filter(): void {
		$registered = $GLOBALS['bl_test_hooks']['spem_standings_html'][10] ?? array();

		$this->assertContains(
			array(
				'cb'   => 'blueline_sp_standings_viewer_html',
				'args' => 3,
			),
			$registered
		);
	}

	/**
	 * With the plugin inactive (no blueline_current_user_team_ids()) the
	 * callback hands the HTML back unchanged.
	 */
	public function test_viewer_callback_without_the_core_plugin_is_a_no_op(): void {
		$html = '<table><tr data-team-id="7"><td>Kings</td></tr></table>';

		$this->assertSame( $html, blueline_sp_standings_viewer_html( $html, 'w2026-27', 'regular' ) );
	}

	/**
	 * The post-pass only touches rows whose data-team-id matches, and only
	 * the label inside those rows.
	 */
	public function test_mark_viewer_rows_touches_only_matching_rows(): void {
		$html = '<table><thead><tr><th>Team</th></tr></thead><tbody>'
			. '<tr class="odd" data-team-id="7"><td>Kings <span class="bl-sp-mine-label" hidden>(your team)</span></td></tr>'
			. '<tr class="even" data-team-id="8"><td>Ducks <span class="bl-sp-mine-label" hidden>(your team)</span></td></tr>'
			. '</tbody></table>';

		$out = blueline_sp_standings_mark_viewer_rows( $html, array( 8 ) );

		$this->assertStringContainsString( '<tr class="odd" data-team-id="7"><td>Kings <span class="bl-sp-mine-label" hidden>', $out );
		$this->assertMatchesRegularExpression( '/<tr class="even bl-sp-row--mine" data-team-id="8"><td>Ducks <span class="bl-sp-mine-label screen-reader-text"\s*>/', $out );
		$this->assertSame( $html, blueline_sp_standings_mark_viewer_rows( $html, array() ) );
		$this->assertSame( $html, blueline_sp_standings_mark_viewer_rows( $html, array( 99 ) ) );
	}

	/**
	 * Known stat keys get an expansion; an unknown key, or a label that is
	 * already the full word, is printed as it is.
	 */
	public function test_header_html(): void {
		$this->assertSame(
			'<abbr title="Goals against">GA</abbr><span class="screen-reader-text"> (goals against)</span>',
			blueline_sp_standings_header_html( 'ga', 'GA' )
		);
		$this->assertSame( 'Team', blueline_sp_standings_header_html( 'name', 'Team' ) );
		$this->assertSame( 'Streak', blueline_sp_standings_header_html( 'strk', 'Streak' ) );
		$this->assertSame( 'X &amp; Y', blueline_sp_standings_header_html( 'custom', 'X & Y' ) );
	}

	/**
	 * The legend names exactly the columns the Record cell joins.
	 */
	public function test_record_legend_follows_the_record_columns(): void {
		$this->assertSame( 'Record is W-L-T-OTL: wins, losses, ties, overtime losses.', blueline_sp_standings_record_legend( array( 'w', 'l', 'tie', 'ot' ) ) );
		$this->assertSame( 'Record is W-L: wins, losses.', blueline_sp_standings_record_legend( array( 'w', 'l' ) ) );
	}

	/**
	 * Load the separate-process stubs and seed three teams.
	 */
	private function load_fixture(): void {
		require_once __DIR__ . '/fixtures/standings-table-stubs.php';

		$state                                    = &blueline_test_state();
		$state['post_meta'][900]                  = array( 'sp_columns' => array( 'gp', 'w', 'l', 'tie', 'ot', 'pts' ) );
		$state['post_meta'][ self::TREBLEMAKERS ] = array( 'sp_colors' => array( 'primary' => '#cc0000' ) );
		$state['post_meta'][ self::TRAIN_WRECK ]  = array( 'sp_colors' => array( 'primary' => '#0033aa' ) );
		$GLOBALS['bl_test_league_table_data']     = array(
			0                  => array(
				'pos'  => 'Pos',
				'name' => 'Team',
				'gp'   => 'GP',
				'w'    => 'W',
				'l'    => 'L',
				'tie'  => 'Tie',
				'ot'   => 'OT',
				'pts'  => 'PTS',
			),
			self::TREBLEMAKERS => array(
				'pos'  => 1,
				'name' => 'TrebleMakers',
				'gp'   => 3,
				'w'    => 2,
				'l'    => 0,
				'tie'  => 0,
				'ot'   => 1,
				'pts'  => 5,
			),
			self::TRAIN_WRECK  => array(
				'pos'  => 2,
				'name' => 'Train Wreck',
				'gp'   => 3,
				'w'    => 1,
				'l'    => 1,
				'tie'  => 1,
				'ot'   => 0,
				'pts'  => 3,
			),
			self::HAMMERS      => array(
				'pos'  => 3,
				'name' => 'Hammers',
				'gp'   => 3,
				'w'    => 0,
				'l'    => 2,
				'tie'  => 1,
				'ot'   => 0,
				'pts'  => 1,
			),
		);
	}

	/**
	 * Render the real template for sp_table 900, as [team_standings] would.
	 *
	 * @param string $table_title Visible heading ('' for none).
	 * @return string
	 */
	private function render_table( string $table_title = 'Division 5 | W2026-27' ): string {
		// Template arguments, as sp_get_template() would extract() them.
		$id             = 900;
		$highlight      = '';
		$link_posts     = true;
		$show_team_logo = false;
		$show_title     = '' !== $table_title;
		$title          = '' !== $table_title ? $table_title : false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the template's own argument name, local to this method.

		ob_start();
		include dirname( __DIR__ ) . '/sportspress/league-table.php';
		return (string) ob_get_clean();
	}

	/**
	 * The plugin's per-request filter, as a viewer with these teams.
	 *
	 * @param string $payload  Shared cached copy.
	 * @param int[]  $team_ids Viewer's current teams.
	 * @return string
	 */
	private function as_viewer( string $payload, array $team_ids ): string {
		$GLOBALS['bl_test_viewer_team_ids'] = $team_ids;

		return (string) apply_filters( 'spem_standings_html', $payload, 'w2026-27', 'regular' );
	}

	/**
	 * One team's <tr>…</tr>.
	 *
	 * @param string $html    Table HTML.
	 * @param int    $team_id Team id.
	 * @return string
	 */
	private function row( string $html, int $team_id ): string {
		$this->assertMatchesRegularExpression( '/<tr\b[^>]*data-team-id="' . $team_id . '"[^>]*>.*?<\/tr>/s', $html );
		preg_match( '/<tr\b[^>]*data-team-id="' . $team_id . '"[^>]*>.*?<\/tr>/s', $html, $m );

		return $m[0];
	}

	/**
	 * The row is the viewer's: class, team colour, and the revealed label.
	 *
	 * @param string $html    Table HTML.
	 * @param int    $team_id Team id.
	 * @param string $color   Team primary colour.
	 */
	private function assert_mine( string $html, int $team_id, string $color ): void {
		$row = $this->row( $html, $team_id );

		$this->assertMatchesRegularExpression( '/^<tr[^>]*class="[^"]*\bbl-sp-row--mine\b/', $row );
		$this->assertMatchesRegularExpression( '/^<tr[^>]*style="[^"]*--bl-team-primary: ' . preg_quote( $color, '/' ) . '/', $row );
		$this->assertMatchesRegularExpression( '/<span class="bl-sp-mine-label screen-reader-text"\s*>\(your team\)<\/span>/', $row );
		$this->assertSame( 1, substr_count( $html, 'bl-sp-row--mine' ), 'only one row is the viewer\'s' );
	}

	/**
	 * The row is someone else's: no class, no colour, no exposed label.
	 *
	 * @param string $html    Table HTML.
	 * @param int    $team_id Team id.
	 */
	private function assert_not_mine( string $html, int $team_id ): void {
		$row = $this->row( $html, $team_id );

		$this->assertStringNotContainsString( 'bl-sp-row--mine', $row );
		$this->assertStringNotContainsString( 'style=', $row );
		$this->assertStringNotContainsString( 'screen-reader-text">(your team)', $row );
	}
}
