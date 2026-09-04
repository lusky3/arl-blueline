# Branded Email Templates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every customer-facing email this site sends — 28 WooCommerce
email types, all 5 currently-active Follow-Up Email campaigns, and the two
WooCommerce-owned account emails standing in for WordPress core's own —
renders in this site's real brand colors/type instead of generic
WooCommerce/wp-email-template defaults.

**Architecture:** No new email-wrapper template files. WooCommerce core's
`email_improvements` feature flag (confirmed enabled on this site) already
ships a modern, fully token-driven, actively-maintained email system driven
entirely by `WooCommerce → Settings → Emails` options. This theme pins
those 8 options to real `--bl-*` values via `add_filter( 'option_{name}',
... )` — the same pattern `inc/sportspress.php` already uses for other
plugins' options — so every email type inherits correct branding with zero
per-template edits. Two genuinely missing content templates
(`customer-new-account.php`, `customer-reset-password.php`) get real
brand-voice copy. wp-email-template's competing wrapper is turned off for
WooCommerce/FUE emails specifically.

**Tech Stack:** WordPress/WooCommerce PHP, this theme's existing
`add_filter( 'option_{name}', ... )` convention, PHPUnit (this theme's
existing suite).

**Spec:** `docs/superpowers/specs/2026-09-04-blueline-email-templates-design.md`

## Global Constraints

- `base_color` must be `--bl-accent-text` (`#3F6E9D`), never `--bl-ice`
  (`#74C0E1`) — the latter is documented fill-only in this theme's own
  `style.css` (1.94:1 contrast) and would make every email link
  unreadable; this is the one hard accessibility constraint the whole plan
  turns on.
- No `wp_mail()`-level or raw-SMTP changes — everything here is
  WooCommerce/theme-option-level.
- `composer lint` / `composer test` must stay green after every task.

---

### Task 1: Pin WooCommerce email color/type options to brand tokens

**Files:**
- Modify: `inc/woocommerce.php`
- Test: `tests/EmailBrandingTest.php` (new)

**Interfaces:**
- Produces: `blueline_wc_email_option_overrides(): array` — pure function,
  option name => forced value, the single source of truth both the live
  `add_filter` registrations and the test read from.

- [ ] **Step 1: Write the failing test**

```php
<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- must be inline on this exact line; see tests/bootstrap.php's identical disable for why.

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}

require_once __DIR__ . '/../inc/woocommerce.php';

final class EmailBrandingTest extends TestCase {

	public function test_base_color_is_the_accent_text_token_not_the_fill_only_ice_token(): void {
		$overrides = blueline_wc_email_option_overrides();

		// The one hard accessibility constraint (see this plan's Global
		// Constraints): base_color drives link text color, so it must be
		// the WCAG-AA text-safe token, never --bl-ice.
		$this->assertSame( '#3f6e9d', strtolower( $overrides['woocommerce_email_base_color'] ) );
		$this->assertNotSame( '#74c0e1', strtolower( $overrides['woocommerce_email_base_color'] ) );
	}

	public function test_every_expected_option_is_present(): void {
		$overrides = blueline_wc_email_option_overrides();

		$expected_keys = array(
			'woocommerce_email_background_color',
			'woocommerce_email_body_background_color',
			'woocommerce_email_base_color',
			'woocommerce_email_text_color',
			'woocommerce_email_footer_text_color',
			'woocommerce_email_header_alignment',
			'woocommerce_email_font_family',
			'woocommerce_email_header_image_width',
		);

		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $overrides, "missing override for {$key}" );
		}
	}

	public function test_font_family_is_one_of_woocommerces_own_supported_choices(): void {
		$overrides = blueline_wc_email_option_overrides();

		// EmailFont::$font's fixed list (Automattic\WooCommerce\Internal\Email\EmailFont) --
		// an unsupported value here would silently fall back to Helvetica
		// inside WooCommerce's own code, which is harmless but means this
		// value should just BE 'Helvetica' rather than something WC ignores.
		$this->assertSame( 'Helvetica', $overrides['woocommerce_email_font_family'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter EmailBrandingTest`
Expected: FAIL with "function blueline_wc_email_option_overrides() not defined" (or a fatal from the require if the function doesn't exist yet).

- [ ] **Step 3: Write the implementation**

Add to `inc/woocommerce.php`, near the other WooCommerce-integration
filters (after the checkout-field-guidance block):

```php
/**
 * This site's own brand tokens (style.css) for every email color/type
 * option WooCommerce's email_improvements-flag styling reads
 * (confirmed enabled on this site: FeaturesUtil::feature_is_enabled(
 * 'email_improvements')). Pinned from code rather than left as
 * hand-edited wp-admin state, the same reasoning as every other
 * option_{name} filter in this codebase (e.g.
 * inc/sportspress.php's option_sportspress_league_menu_teams).
 *
 * base_color drives BOTH the CTA button fill AND, unconditionally
 * under email_improvements, the link text color -- --bl-ice
 * (#74C0E1) is documented fill-only in style.css (1.94:1 contrast)
 * and would make every email link nearly unreadable if used here.
 * --bl-accent-text (#3F6E9D, 5.13:1, style.css's own "links/accent
 * text on light" token) is the correct value: real WCAG AA link
 * contrast, and WooCommerce's own wc_hex_is_light() check on that
 * value picks white button text automatically -- a solid navy
 * button with white text, both accessible and a normal professional
 * treatment (this theme's own skewed ice-fill/ink-text ribbon uses a
 * CSS transform unsupported in email clients, so a literal port was
 * never viable here).
 *
 * @return array<string,string> option name => value.
 */
function blueline_wc_email_option_overrides(): array {
	return array(
		'woocommerce_email_background_color'      => '#F7FBFC', // --bl-paper (outer canvas).
		'woocommerce_email_body_background_color' => '#FFFFFF', // --bl-white (card surface).
		'woocommerce_email_base_color'             => '#3F6E9D', // --bl-accent-text (links + buttons).
		'woocommerce_email_text_color'             => '#132343', // --bl-ink (body copy, headings).
		'woocommerce_email_footer_text_color'      => '#2E4A74', // --bl-ink-mid (footer credit line).
		'woocommerce_email_header_alignment'       => 'left', // Matches this site's own left-aligned heading convention.
		'woocommerce_email_font_family'            => 'Helvetica', // Closest of WooCommerce's fixed EmailFont::$font list to Inter/system-ui.
		'woocommerce_email_header_image_width'     => '96', // Sized for the real uploaded logo's own aspect ratio.
	);
}

/**
 * Register one option_{name} filter per key in
 * blueline_wc_email_option_overrides() -- WordPress applies
 * `option_{$option}` on every get_option() call for that option, so
 * this pins each value regardless of what's actually stored in
 * wp_options (wp-admin's own Settings > Emails screen still shows and
 * can edit the underlying value; only the runtime value emails
 * actually render with is locked).
 */
foreach ( blueline_wc_email_option_overrides() as $blueline_email_option => $blueline_email_value ) {
	add_filter(
		"option_{$blueline_email_option}",
		static function () use ( $blueline_email_value ) {
			return $blueline_email_value;
		}
	);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter EmailBrandingTest`
Expected: PASS

- [ ] **Step 5: Run full suite, then commit**

```bash
composer lint && composer test
git add inc/woocommerce.php tests/EmailBrandingTest.php
git commit -m "feat(blueline): pin WooCommerce email colors/type to brand tokens"
```

---

### Task 2: Turn off wp-email-template's competing WooCommerce wrapper

**Files:**
- Modify: `inc/woocommerce.php`
- Test: `tests/EmailBrandingTest.php` (extend)

**Interfaces:**
- Consumes: nothing new.
- Produces: one more `add_filter( 'option_wp_email_template_general', ... )` registration.

- [ ] **Step 1: Write the failing test**

Add to `EmailBrandingTest.php`:

```php
	public function test_wp_email_template_woocommerce_wrapping_is_disabled(): void {
		$original = array(
			'apply_template_all_emails'      => 'no',
			'email_content_type'             => 'multipart',
			'email_container_width'          => '600',
			'background_colour'              => array(
				'enable' => '1',
				'color'  => '#f4f4f4',
			),
			'deactivate_pattern_background'  => 'no',
			'outlook_apply_border'           => 'yes',
			'apply_for_woo_emails'           => 'yes',
		);

		$patched = blueline_wp_email_template_disable_woo_wrapping( $original );

		$this->assertSame( 'no', $patched['apply_for_woo_emails'] );
		// Every other key survives untouched -- this is a targeted merge,
		// not a wholesale replacement of the plugin's own stored settings.
		$this->assertSame( 'no', $patched['apply_template_all_emails'] );
		$this->assertSame( '#f4f4f4', $patched['background_colour']['color'] );
	}

	public function test_wp_email_template_disable_tolerates_a_missing_or_malformed_option(): void {
		$this->assertSame( array(), blueline_wp_email_template_disable_woo_wrapping( array() ) );
		$this->assertFalse( blueline_wp_email_template_disable_woo_wrapping( false ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test -- --filter EmailBrandingTest`
Expected: FAIL — function not defined.

- [ ] **Step 3: Write the implementation**

Add to `inc/woocommerce.php`, right after Task 1's block:

```php
add_filter( 'option_wp_email_template_general', 'blueline_wp_email_template_disable_woo_wrapping' );
/**
 * wp-email-template (a3rev) ALSO wraps WooCommerce/Follow-Up Emails
 * output with its own generic template on top of WooCommerce's own
 * (confirmed live: wp_email_template_general's own
 * apply_for_woo_emails was "yes") -- off-brand styling
 * (Verdana/Century-Gothic-italic, #1155CC links) competing with the
 * option overrides above. Turns off ONLY that one integration, not
 * the whole plugin (it may still be the right tool for some other
 * wp_mail() sender this site uses) and not the whole stored option
 * (a targeted merge, so any other setting an admin configures there
 * later survives).
 *
 * @param mixed $value The stored wp_email_template_general option value.
 * @return mixed
 */
function blueline_wp_email_template_disable_woo_wrapping( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$value['apply_for_woo_emails'] = 'no';

	return $value;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test -- --filter EmailBrandingTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
composer lint && composer test
git add inc/woocommerce.php tests/EmailBrandingTest.php
git commit -m "fix(blueline): stop wp-email-template double-wrapping WooCommerce/FUE emails"
```

---

### Task 3: Brand-voice content templates for the two missing account emails

**Files:**
- Create: `woocommerce/emails/customer-new-account.php`
- Create: `woocommerce/emails/customer-reset-password.php`
- Reference (read, don't copy verbatim): `woocommerce/emails/customer-completed-order.php` (this theme's own established brand-voice pattern), the WooCommerce core stock templates of the same two filenames (structure/hook sequence to preserve).

**Interfaces:**
- Consumes: nothing new — same `$email_heading`/`$email`/`$user_login`/`$user_pass`/`$set_password_url` variables WooCommerce's own stock templates receive (do not invent new ones).
- Produces: nothing new — these are leaf templates, not consumed elsewhere.

This task is copy-writing plus a structural port, not new logic — no
TDD steps apply. Dispatched to a subagent with:
- The full text of WooCommerce core's stock `customer-new-account.php`
  and `customer-reset-password.php` (fetch via the same `wp eval`/`docker
  exec` path Task 1's author used during discovery,
  `/var/www/html/wp-content/plugins/woocommerce/templates/emails/`) as
  the structural reference (which hooks fire in which order — preserve
  exactly).
- `customer-completed-order.php` as the tone/voice reference.
- Instruction: copy body, do not invent new copy. Change greeting/body
  paragraphs to match this site's own established voice (short, direct,
  names the actual thing that happened, e.g. "your account is ready" /
  "reset your password"). Keep every `do_action()`/hook call from the
  stock template unchanged — only the literal customer-facing text
  between them changes.
- After writing both files: `composer lint`, confirm 0 errors.

- [ ] Dispatch subagent, review diff for brand-voice fit and hook-fidelity, iterate once if needed.
- [ ] `composer lint && composer test`, commit:

```bash
git add woocommerce/emails/customer-new-account.php woocommerce/emails/customer-reset-password.php
git commit -m "feat(blueline): brand-voice copy for the two account emails WooCommerce owns"
```

---

### Task 4: Audit existing content templates for stale CSS classes

**Files:**
- Read-only survey: all 13 files in `woocommerce/emails/*.php` (excluding the two new ones from Task 3).
- Reference: `woocommerce/templates/emails/email-styles.php` (WooCommerce core, the CURRENT class list Task 1's option overrides actually style) — fetched live during discovery, not assumed from memory.

Dispatched to a subagent: for each of the 13 templates, list any CSS
class used that does NOT appear in WooCommerce core's current
`email-styles.php` (a class from an older WooCommerce version that's
since been renamed/removed would silently lose its styling under
`email_improvements`). Report findings only — no code changes unless a
real mismatch is found, in which case fix it directly (rename to the
current class) and note it in the same report.

- [ ] Dispatch subagent, read its report.
- [ ] If it found and fixed anything: `composer lint && composer test`, commit.
- [ ] If clean: no commit needed, note it in the final summary.

---

### Task 5: Verify live and report

- [ ] Deploy to staging: `./scripts/deploy-theme.sh staging`.
- [ ] `wp eval` a representative sample through
  `$email->style_inline( $email->get_content_html() )` (one order-status
  email, one YITH refund email, one FUE campaign, both new account
  templates) — confirm real brand hex values appear inline in the
  rendered HTML, not the old generic ones.
- [ ] A real test send to a real inbox (this session's own established
  verification pattern), read visually before calling this done.
- [ ] Open PR, full check suite green, report completion.
