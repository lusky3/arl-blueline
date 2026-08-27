# Player Profile Tab Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Player Profile" tab to `/account` holding the claimed player's bio (photo, name, jersey number) and six read-only registration-checkout fields (skill level, position, jersey size, gender, emergency contact name/number), with a claim-card fallback for unclaimed users and a link to Edit Account for changes.

**Architecture:** One new endpoint registered the same way `my-team`/`my-schedule`/`preferences` already are, one new small render file (`inc/account/player-profile.php`) following this codebase's one-file-per-feature convention, and one new context-hint case added to the existing claim-card fallback mechanism. No new JS, no new data model — every value is either an existing player-data helper or a direct `get_user_meta()` read against already-populated WCFE Pro data.

**Tech Stack:** PHP 8.3 / WordPress / WooCommerce (theme `rookiehockey-blueline`, root `themes/blueline/`), PHPUnit, plain CSS.

**Spec:** `docs/superpowers/specs/2026-08-27-blueline-player-profile-design.md`

## Global Constraints

- Staging (Staging-host) only.
- Run `composer lint`, `./vendor/bin/phpunit`, and `npm run check` before every commit that touches PHP or CSS; all must pass.
- No editing capability for the six registration fields — read-only, with a link to Edit Account only.
- No DOB, requested team, or partner-request fields — explicitly out of scope.
- Verify the final result live in a real browser before considering this done, not curl alone — this arc's prior two sub-projects both found real bugs (a CSS specificity conflict, a cross-instance JS sync gap, and a rewrite-rules-flush deploy gap) that no static check caught.
- After deploying to staging for Task 4, no manual `wp rewrite flush` should be needed — `scripts/deploy-theme.sh` now runs it automatically for the staging target (added during sub-project 2's final review). If the new endpoint 404s anyway, that's a real regression worth investigating, not something to route around with a manual flush.

---

### Task 1: Register the `player-profile` endpoint and its rewrite rule

**Files:**
- Modify: `themes/blueline/inc/account/endpoints.php:26-70` (the `blueline_account_endpoints()` array) and `:294-297` (`blueline_register_account_rewrite_endpoints()`, currently registers `my-team`, `my-schedule`, `preferences`)
- Modify: `themes/blueline/tests/AccountEndpointsTest.php`
- Modify: `themes/blueline/tests/AccountNavItemsTest.php`

**Interfaces:**
- Produces: a `'player-profile'` key in `blueline_account_endpoints()` with `group: 'league'`, `order: 25` — consumed by Task 2's content handler. `'league'` (not a new group) is correct here: this is genuinely team/player content, matching `my-team`/`my-schedule`'s own group, and no nav template change is needed regardless (any non-`'billing'` group renders as a top-level pill automatically).

- [ ] **Step 1: Write the failing tests**

In `themes/blueline/tests/AccountEndpointsTest.php`, add:

```php
	/**
	 * Sub-project 3 (Player Profile tab): a new top-level endpoint in the
	 * 'league' group, alongside my-team/my-schedule -- genuinely team/player
	 * content, not account administration or site-experience settings.
	 */
	public function test_player_profile_endpoint_exists_in_the_league_group(): void {
		$e = blueline_account_endpoints();

		$this->assertArrayHasKey( 'player-profile', $e );
		$this->assertSame( 'league', $e['player-profile']['group'] );
		$this->assertSame( 'Player Profile', $e['player-profile']['label'] );
	}
```

In `themes/blueline/tests/AccountNavItemsTest.php`, following the exact style of the existing `test_preferences_carries_its_own_group_not_billing()` test in that same file, add:

```php
	/**
	 * Sub-project 3 (Player Profile tab): 'player-profile' must carry the
	 * 'league' group -- never 'billing' -- so the nav template renders it
	 * as a top-level pill, never inside the Billing disclosure.
	 */
	public function test_player_profile_carries_the_league_group(): void {
		$items = blueline_account_nav_items( array( 'player-profile' => 'Player Profile' ) );

		$this->assertSame( 'league', $items[0]['group'] );
	}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/phpunit --filter "AccountEndpointsTest|AccountNavItemsTest"`
Expected: FAIL — `player-profile` doesn't exist yet.

- [ ] **Step 3: Add the endpoint config**

In `blueline_account_endpoints()`, add after the `'my-schedule'` entry (before `'preferences'`):

```php
		'player-profile'  => array(
			'label' => __( 'Player Profile', 'blueline' ),
			'group' => 'league',
			'order' => 25,
		),
```

- [ ] **Step 4: Register the rewrite endpoint**

In `blueline_register_account_rewrite_endpoints()` (~line 294-297), add a fourth line matching the existing three:

```php
	add_rewrite_endpoint( 'player-profile', EP_ROOT | EP_PAGES );
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/phpunit --filter "AccountEndpointsTest|AccountNavItemsTest"`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `./vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add themes/blueline/inc/account/endpoints.php themes/blueline/tests/AccountEndpointsTest.php themes/blueline/tests/AccountNavItemsTest.php
git commit -m "feat(blueline): register the Player Profile account endpoint

Adds a new top-level nav pill and rewrite endpoint for
/account/player-profile/, in the 'league' group alongside my-team and
my-schedule -- genuinely team/player content."
```

---

### Task 2: Build the bio section and the claimed/unclaimed page shell

**Files:**
- Create: `themes/blueline/inc/account/player-profile.php`
- Modify: `themes/blueline/functions.php:62` (add a `require_once` line after `preferences.php`)
- Modify: `themes/blueline/inc/account/dashboard.php:272-281` (`blueline_claim_card_context_hint()`, add a `'player-profile'` case)
- Modify: `themes/blueline/assets/src/css/account.css`
- Create: `themes/blueline/tests/PlayerProfileTest.php`

**Interfaces:**
- Consumes: `blueline_get_linked_player_id( int $user_id ): ?int` (`inc/account/player-link.php:184`), `blueline_player_jersey_number( int $player_id )` returning `?string` (`inc/account/player-data.php:209-213`), `blueline_leaf_mark( string $class ): void` (used identically in `dashboard.php` for the team-crest fallback), `blueline_account_render_claim_card( int $user_id, string $context = 'dashboard' ): void` (`inc/account/dashboard.php`), `blueline_account_render_claim_notice(): void`, `blueline_account_module_start()`/`_end()` (`inc/account/dashboard.php`). Also plain WordPress `has_post_thumbnail( $player_id )`, `get_the_post_thumbnail( $player_id, 'thumbnail' )`, `get_the_title( $player_id )` — this is the exact idiom already used identically in `sportspress/team-lists.php:119-120,255-256` and `inc/sportspress.php:1456-1457`; there is no wrapper function for this in the codebase and none should be invented here.
- Produces: `blueline_account_player_profile_endpoint()`, hooked to `woocommerce_account_player-profile_endpoint`, the content handler for the new tab. Task 3 appends the registration-details section to this same function.

- [ ] **Step 1: Write the failing test**

Create `themes/blueline/tests/PlayerProfileTest.php`:

```php
<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see inc/template-tags.php's identical disable.
/**
 * Unit tests.
 *
 * @package blueline
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/account/player-profile.php';

/**
 * Covers Player Profile's pure per-field display logic.
 */
final class PlayerProfileTest extends TestCase {

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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter PlayerProfileTest`
Expected: FAIL — `player-profile.php` and `blueline_player_profile_field_display()` don't exist yet.

- [ ] **Step 3: Add the `'player-profile'` claim-card context hint**

In `themes/blueline/inc/account/dashboard.php`, `blueline_claim_card_context_hint()` (~line 272-281), add a case matching the existing `'my-team'`/`'my-schedule'` pattern:

```php
function blueline_claim_card_context_hint( string $context ): string {
	switch ( $context ) {
		case 'my-team':
			return __( 'Once you’re linked, your team will appear here.', 'blueline' );
		case 'my-schedule':
			return __( 'Once you’re linked, your schedule will appear here.', 'blueline' );
		case 'player-profile':
			return __( 'Once you’re linked, your player profile will appear here.', 'blueline' );
		default:
			return '';
	}
}
```

- [ ] **Step 4: Create `inc/account/player-profile.php`**

```php
<?php
/**
 * The Player Profile tab: the claimed player's bio (photo, name, jersey
 * number) and six read-only registration-checkout fields (skill level,
 * position, jersey size, gender, emergency contact name/number), synced to
 * user meta by WooCommerce Checkout Field Editor Pro (confirmed live: these
 * are already complete, human-readable display strings -- no lookup table
 * needed, safe to render via esc_html()).
 *
 * Design spec: docs/superpowers/specs/2026-08-27-blueline-player-profile-
 * design.md.
 *
 * Read-only by design -- WCFE Pro already renders these six fields as
 * editable on Edit Account (confirmed live), so this page links there for
 * changes rather than building a second, duplicate editing mechanism.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

/**
 * A stored field's display value, or a "not provided" fallback for an
 * empty one. get_user_meta() returns '' for an unset key, not null, so
 * this is the difference between a blank value rendering next to its
 * label and an honest "we don't have this" state.
 *
 * @param string $raw The raw stored value.
 * @return string The value to display.
 */
function blueline_player_profile_field_display( string $raw ): string {
	return '' !== $raw ? $raw : __( 'Not provided', 'blueline' );
}

/**
 * The bio section: photo (or a leaf-mark fallback, mirroring
 * blueline_account_render_my_team()'s own crest-fallback pattern in
 * dashboard.php, adapted from a team crest to a player photo), name, and
 * jersey number.
 *
 * @param int $player_id sp_player post ID.
 */
function blueline_render_player_profile_bio_section( int $player_id ): void {
	blueline_account_module_start( 'player-profile-bio', __( 'Player profile', 'blueline' ) );
	?>
	<div class="bl-player-profile__bio">
		<?php if ( has_post_thumbnail( $player_id ) ) : ?>
			<span class="bl-player-profile__photo"><?php echo get_the_post_thumbnail( $player_id, 'thumbnail' ); ?></span>
		<?php elseif ( function_exists( 'blueline_leaf_mark' ) ) : ?>
			<span class="bl-player-profile__photo bl-player-profile__photo--fallback">
				<?php blueline_leaf_mark( 'bl-player-profile__photo-mark' ); ?>
			</span>
		<?php endif; ?>
		<div class="bl-player-profile__identity">
			<p class="bl-player-profile__name"><?php echo esc_html( get_the_title( $player_id ) ); ?></p>
			<?php $number = blueline_player_jersey_number( $player_id ); ?>
			<?php if ( $number ) : ?>
				<p class="bl-player-profile__number">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: jersey number. */
							__( 'Jersey #%s', 'blueline' ),
							$number
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php
	blueline_account_module_end();
}

add_action( 'woocommerce_account_player-profile_endpoint', 'blueline_account_player_profile_endpoint' );
/**
 * Content for /account/player-profile/. Task 3 appends the
 * registration-details section after the bio section built here.
 */
function blueline_account_player_profile_endpoint(): void {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id, 'player-profile' );
		return;
	}

	blueline_render_player_profile_bio_section( $player_id );
}
```

- [ ] **Step 5: Wire the file into the theme's loader**

In `themes/blueline/functions.php`, add a new line after the existing `preferences.php` line (~line 62):

```php
require_once BLUELINE_DIR . '/inc/account/player-profile.php';
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter PlayerProfileTest`
Expected: PASS.

- [ ] **Step 7: Add the bio section CSS**

In `themes/blueline/assets/src/css/account.css` (currently 612 lines), append this new section at the very end of the file, after its last existing section:

```css
/* ---------------------------------------------------------------------
 * Player profile -- bio (photo, name, jersey number) and registration
 * details (Task 3). The photo reuses the same circular-crop, cover-fit
 * pattern .bl-sp-roster__photo (sportspress.css) already establishes for
 * player headshots, sized larger here (96px, not that class's 32px) since
 * this is a dedicated profile page, not a compact roster row -- a new
 * class, not a literal reuse of that smaller one.
 * ------------------------------------------------------------------- */

.bl-player-profile__bio {
	display: flex;
	align-items: center;
	gap: var(--bl-space-4);
}

.bl-player-profile__photo {
	display: block;
	width: 96px;
	height: 96px;
	border-radius: 50%;
	overflow: hidden;
	flex: 0 0 auto;
}

.bl-player-profile__photo img {
	display: block;
	width: 100%;
	height: 100%;
	object-fit: cover;
}

.bl-player-profile__photo--fallback {
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--bl-surface-sunken);
}

.bl-player-profile__photo-mark {
	width: 48px;
	height: 48px;
	color: var(--bl-steel);
}

.bl-player-profile__name {
	margin: 0;
	font-weight: 600;
	font-size: var(--bl-text-lg);
}

.bl-player-profile__number {
	margin: var(--bl-space-1) 0 0;
	color: var(--bl-content-text-secondary);
	font-size: var(--bl-text-sm);
}
```

- [ ] **Step 8: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add themes/blueline/inc/account/player-profile.php themes/blueline/inc/account/dashboard.php themes/blueline/functions.php themes/blueline/tests/PlayerProfileTest.php themes/blueline/assets/src/css/account.css themes/blueline/assets/dist
git commit -m "feat(blueline): add the Player Profile tab's bio section

Photo (or a leaf-mark fallback), name, and jersey number for the
claimed player, with the existing claim-card fallback for an
unclaimed user -- same pattern my-team/my-schedule already use."
```

---

### Task 3: Build the registration-details section

**Files:**
- Modify: `themes/blueline/inc/account/player-profile.php` (add the registration section + append it to `blueline_account_player_profile_endpoint()`)
- Modify: `themes/blueline/assets/src/css/account.css`
- Modify: `themes/blueline/tests/PlayerProfileTest.php`

**Interfaces:**
- Consumes: `blueline_player_profile_field_display( string $raw ): string` (Task 2), plain `get_user_meta()`, WooCommerce's `wc_get_account_endpoint_url( 'edit-account' )` (the same function `woocommerce/myaccount/navigation.php` already calls directly for every nav link — **not** `blueline_account_endpoint_url()`, which was deleted as dead code during sub-project 1's final review; do not reintroduce it).
- Produces: nothing consumed by later tasks — this is the plan's last content task.

- [ ] **Step 1: Add a test for the six-field rendering shape**

In `themes/blueline/tests/PlayerProfileTest.php`, add (this test documents the exact field list/label mapping as a single source of truth, so a future edit to one doesn't silently drop a field):

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/phpunit --filter PlayerProfileTest`
Expected: FAIL — `blueline_player_profile_registration_fields()` doesn't exist yet.

- [ ] **Step 3: Add the registration-fields map and the section renderer**

In `themes/blueline/inc/account/player-profile.php`, add (after `blueline_player_profile_field_display()`, before `blueline_render_player_profile_bio_section()`):

```php
/**
 * The six registration-checkout fields this tab reads, meta key => label.
 * All six are confirmed live (staging, 2026-08-27) to already sync to user
 * meta via WooCommerce Checkout Field Editor Pro as complete, human-readable
 * display strings (e.g. "4 - Beginner – Intermediate") -- no lookup table
 * needed, safe via esc_html(). Extracted as its own function (rather than
 * inlined in the render function below) so the exact field list/label
 * mapping has one place to change, and one test asserting it hasn't
 * silently drifted.
 *
 * @return array<string,string> meta_key => label.
 */
function blueline_player_profile_registration_fields(): array {
	return array(
		'arl_division'          => __( 'Skill level', 'blueline' ),
		'arl_position'          => __( 'Position', 'blueline' ),
		'arl_jerseysize'        => __( 'Jersey size', 'blueline' ),
		'arl_gender'            => __( 'Gender', 'blueline' ),
		'arl_emergency_contact' => __( 'Emergency contact', 'blueline' ),
		'arl_emergency_number'  => __( 'Emergency contact number', 'blueline' ),
	);
}

/**
 * The registration-details section: the six fields above, read-only, with
 * a link to Edit Account for changes -- WCFE Pro already renders them as
 * editable there (confirmed live), so this does not duplicate that.
 *
 * @param int $user_id WordPress user ID.
 */
function blueline_render_player_profile_registration_section( int $user_id ): void {
	blueline_account_module_start( 'player-profile-registration', __( 'Registration details', 'blueline' ) );
	?>
	<dl class="bl-player-profile__fields">
		<?php foreach ( blueline_player_profile_registration_fields() as $meta_key => $label ) : ?>
			<div class="bl-player-profile__field">
				<dt><?php echo esc_html( $label ); ?></dt>
				<dd><?php echo esc_html( blueline_player_profile_field_display( (string) get_user_meta( $user_id, $meta_key, true ) ) ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<p class="bl-player-profile__edit-link">
		<a class="bl-account-module__link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>">
			<?php esc_html_e( 'Edit these details', 'blueline' ); ?> <span aria-hidden="true">&rarr;</span>
		</a>
	</p>
	<?php
	blueline_account_module_end();
}
```

- [ ] **Step 4: Append the section to the endpoint handler**

Change `blueline_account_player_profile_endpoint()` from:

```php
function blueline_account_player_profile_endpoint(): void {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id, 'player-profile' );
		return;
	}

	blueline_render_player_profile_bio_section( $player_id );
}
```

to:

```php
function blueline_account_player_profile_endpoint(): void {
	blueline_account_render_claim_notice();

	$user_id   = get_current_user_id();
	$player_id = function_exists( 'blueline_get_linked_player_id' ) ? blueline_get_linked_player_id( $user_id ) : null;

	if ( ! $player_id ) {
		blueline_account_render_claim_card( $user_id, 'player-profile' );
		return;
	}

	blueline_render_player_profile_bio_section( $player_id );
	blueline_render_player_profile_registration_section( $user_id );
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `./vendor/bin/phpunit --filter PlayerProfileTest`
Expected: PASS.

- [ ] **Step 6: Add the registration-fields CSS**

In `themes/blueline/assets/src/css/account.css`, append to the Player Profile section added in Task 2 (after `.bl-player-profile__number`'s rule):

```css
.bl-player-profile__fields {
	margin: var(--bl-space-5) 0 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
	gap: var(--bl-space-4);
}

.bl-player-profile__field dt {
	margin: 0;
	font-size: var(--bl-text-xs);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--bl-content-text-secondary);
}

.bl-player-profile__field dd {
	margin: var(--bl-space-1) 0 0;
	font-weight: 600;
}

.bl-player-profile__edit-link {
	margin: var(--bl-space-4) 0 0;
}
```

- [ ] **Step 7: Build and run the full check suite**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add themes/blueline/inc/account/player-profile.php themes/blueline/tests/PlayerProfileTest.php themes/blueline/assets/src/css/account.css themes/blueline/assets/dist
git commit -m "feat(blueline): add the Player Profile tab's registration-details section

Six read-only fields (skill level, position, jersey size, gender,
emergency contact name/number), already synced to user meta by WCFE
Pro as human-readable strings, with a link to Edit Account for
changes rather than a second editing mechanism."
```

---

### Task 4: Live-verify the finished Player Profile tab

**Files:** none expected — verification task. Only touch files if a real, specific gap turns up; fix in the most directly relevant file and re-run the full check suite before committing.

**Interfaces:** none.

- [ ] **Step 1: Deploy to staging**

```bash
cd themes/blueline && npm run build
cd .. && ./scripts/deploy-theme.sh staging
```

Confirm the deploy output includes "Success: Rewrite rules flushed." before "deployed staging" (sub-project 2 added this automatically) — if it's missing or the new endpoint 404s anyway, that's a real regression to investigate, not something to route around manually.

- [ ] **Step 2: Verify the nav pill**

Log in as a real, claimed test account. Confirm "Player Profile" appears as its own top-level pill between "My Schedule" and "Preferences," and highlights as active when on that page.

- [ ] **Step 3: Verify the bio and registration-details sections with REAL populated data**

`bl-test-verify` (the synthetic test account used throughout this arc's live verification) has NONE of the six registration fields set — it will only exercise the "Not provided" fallback path, not the populated-data path. Use a real registrant account instead: user ID 99 was confirmed during this sub-project's own spec research to have all six fields populated with real, human-readable values (`arl_gender` = "Male", `arl_position` = "Any", `arl_division` = "4 - Beginner – Intermediate", `arl_emergency_contact` = "Jennifer McCarthy", `arl_emergency_number` = "4164515215", `arl_jerseysize` = "XL"). Confirm live: the photo (or fallback mark if that player has none), name, and jersey number render correctly in the bio section, and all six registration fields render their real values, not "Not provided," for this account.

If user 99 is no longer usable for login-based verification (e.g. no way to reset its password, or it turns out to not actually be linked to a real WordPress user account you can authenticate as), find another currently-claimed account with real WCFE Pro data via `wp user meta list <id>` on staging, checking for the presence of `arl_division` as the signal, before concluding the populated-data path can't be verified.

- [ ] **Step 4: Verify the "Not provided" fallback path**

Log in as `bl-test-verify` (or any other claimed account with none of the six fields set) and confirm every registration field shows "Not provided" rather than rendering blank next to its label.

- [ ] **Step 5: Verify the "Edit these details" link**

Click it, confirm it lands on `/account/edit-account/`.

- [ ] **Step 6: Verify the unclaimed-user fallback**

If a currently-unclaimed test account is available, confirm landing on `/account/player-profile/` shows the claim card (not a blank page or an error), with the `player-profile`-specific hint text ("Once you're linked, your player profile will appear here.") when there are no claim candidates. If no unclaimed test account is readily available, verify this by reading the code path instead (the `! $player_id` branch in `blueline_account_player_profile_endpoint()` is identical in shape to `blueline_account_my_team_endpoint()`'s own, already-live-verified equivalent from a much earlier point in this session) and note in your report that this specific branch was verified by code-path equivalence, not a live click-through.

- [ ] **Step 7: If any fix was needed, run the full check suite and commit it**

Run: `cd themes/blueline && npm run build && npm run check && composer lint && ./vendor/bin/phpunit`

```bash
git add -A
git commit -m "fix(blueline): <describe the specific live-verification fix>"
```

If no fix was needed, skip this step.

---

## Final steps (after all tasks)

- [ ] Run `cd themes/blueline && npm run check && composer lint && ./vendor/bin/phpunit` one more time on the fully assembled branch.
- [ ] Push the branch and open a PR against `main` via `gh pr create`, describing the Player Profile tab and linking the design spec. Note in the PR description that this is the final sub-project of the three-part `/account` redesign arc.
- [ ] Watch CI; merge only on green.
- [ ] Redeploy to staging (`npm run build` then `./scripts/deploy-theme.sh staging`) and re-verify Task 4's checklist against the merged, deployed result in a real browser.
