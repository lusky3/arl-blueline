# Branded email templates (WooCommerce, Follow-Up Emails, WordPress core)

Status: user pre-approved autonomous execution ("plan, spec and implement...
proceed autonomously from start to finish") — proceeding directly to the
implementation plan without an interactive review round. Documented here for
the record and for anyone reviewing the resulting PR.

## Context

User asked for branded templates across "all email types in use: standard
WordPress, Follow-Up Emails (FUE) and WooCommerce," matching current design
standards (the site's own `--bl-*` token system, `style.css`).

**Discovery (2026-09-04, staging):**

- WooCommerce registers **28 email types** (`WC()->mailer()->get_emails()`) —
  core order emails, `customer_reset_password`/`customer_new_account` (WP's
  own account emails are not in play; WooCommerce owns these on an integrated
  site), YITH Advanced Refund System (6 types), store credit, PayPal
  onboarding, partial payment (customer+admin), POS (disabled). All of them
  render through the exact same `WC_Emails::email_header()` /
  `email_footer()` calls (`do_action( 'woocommerce_email_header', ... )` /
  `woocommerce_email_footer`) — the shared wrapper every content template
  opens and closes with.
- The theme already overrides 13 of the ~15 content templates
  (`woocommerce/emails/*.php`, ported byte-identical from a prior theme per
  `inc/woocommerce.php`'s own docblock) — several already carry
  site-specific copy ("We have received your **registration**", not stock
  WooCommerce's "shopping with us"). `customer-new-account.php` and
  `customer-reset-password.php` are NOT overridden (still WooCommerce's own
  generic copy) — real gaps, in scope.
- The theme has **no** `email-header.php` / `email-footer.php` /
  `email-styles.php` override — every one of those 28 email types currently
  renders through WooCommerce's own stock wrapper.
- **Follow-Up Emails**: 5 currently `fue-active` campaigns (`Winter 2026-27
  Registration Open`, `Summer 2026 neck guard reminder`, `Summer 2026 Season
  Intro`, `Winter 2025-26 - Intro`, `Payment is Overdue (e-Transfer)`) plus a
  long archive of inactive/archived ones (not currently sending — out of
  scope). Confirmed via `wp post meta get {id} _template` on **all 5 active
  campaigns**: every one uses FUE's `WooCommerce` template mode, meaning they
  render through the SAME `email_header()`/`email_footer()` hooks as the WC
  emails above. One shared wrapper redesign, done well, covers WooCommerce
  AND every currently-active FUE campaign — no FUE-specific template work
  needed.
- **wp-email-template** plugin (a3rev) is active, with `apply_for_woo_emails
  => yes` — it currently ALSO wraps WooCommerce/FUE emails, with generic,
  off-brand styling (Verdana/Century-Gothic-italic headings, `#0A0A0A`
  text, `#1155CC` links — none of it from `--bl-*` tokens). Its own settings
  (`wp_email_template_general['apply_template_all_emails'] => "no"`) mean it
  is NOT the wrapper for arbitrary `wp_mail()` calls sitewide — its footprint
  is specifically the WooCommerce/FUE integration this spec is about to
  replace. Turning `apply_for_woo_emails` off (a WooCommerce Settings →
  Emails-adjacent option this plugin adds) hands the wrapper over to the
  theme's own template cleanly, no double-wrap.
- A `upme_email_templates` option (WP core-style registration/password-reset
  copy, e.g. `forgot_password`, `reg_default_user`) exists in the database
  but references the site's OLD name ("ARL - Adult Rookie League" vs. today's
  "ARL – Adult Recreational League") and matches no plugin found active on
  this site (checked the full `wp plugin list` — nothing resembling Ultimate
  Member, Profile Builder, Theme My Login, etc.). Orphaned data from a
  removed plugin. **Out of scope** — nothing currently sends through it.
- WooCommerce's own email color settings are already partially tuned
  (`base_color: #032867`, a real hosted logo) but don't match the site's
  actual tokens (`--bl-ink-deep: #0D1729`, `--bl-ink: #132343`), and default
  WooCommerce chrome (rounded corners, generic sans-serif, a boxed
  "greeting" bar) doesn't carry any of this site's own signature devices
  (the ice/ink band pair, `Barlow Condensed` display type, skewed CTA
  ribbons).

## Non-goals

- **Not** touching `upme_email_templates` — confirmed orphaned, nothing
  fires through it.
- **Not** building genuinely new content templates for the 6 YITH refund
  emails, store credit, or PayPal onboarding — they inherit the new
  wrapper's branding automatically (that's the whole point of fixing the
  wrapper) and none showed a content-level gap in discovery; template-level
  polish for those specific flows is a separate ask if the user wants it.
  Only `customer-new-account.php`/`customer-reset-password.php` (real gaps:
  no override exists at all) get NEW content templates as part of this pass.
- **Not** rewriting the ~13 already-overridden content templates from
  scratch — most already carry correct, brand-voice copy (confirmed live
  during the checkout-field audit work earlier this session). They get
  reviewed for structural consistency with the new wrapper's CSS classes,
  not rewritten.
- **Not** raw WordPress-core admin/system emails (auto-update notices,
  comment moderation, "PHP warning" site health mail) — internal-only,
  never seen by a customer, not a brand surface.
- **Not** archived/inactive FUE campaigns — they aren't sending; if one is
  reactivated later it already inherits the wrapper fix, same as every
  active one.
- **No** dark-mode-specific email styling. Email client dark-mode support is
  inconsistent and mostly auto-inverts light templates passably; chasing it
  properly (locked color-scheme meta + duplicate token sets) is
  disproportionate effort for a transactional-email surface. Colors are
  chosen with enough contrast margin that a naive auto-invert stays legible.

## Design

### Revision after reading WooCommerce core's actual current templates

The Architecture section below was written before reading
`woocommerce/templates/emails/email-{header,footer,styles}.php` directly.
This site's WooCommerce version (11.0.1) has the `email_improvements`
feature flag **enabled** (confirmed live:
`FeaturesUtil::feature_is_enabled('email_improvements')` returns true), and
under that flag WooCommerce core ships a modern, fully token-driven email
system: colors, font, header alignment, and logo width are ALL already
sourced from `WooCommerce → Settings → Emails` options
(`woocommerce_email_{background_color,body_background_color,base_color,
text_color,footer_text_color,header_alignment,header_image_width,
font_family}`), with correct responsive media queries, RTL handling, and
Gmail-specific compatibility hacks already built in and actively
maintained upstream.

Hand-writing custom `email-header.php`/`email-footer.php`/`email-styles.php`
overrides — the original plan below — would mean re-solving problems
WooCommerce core already solves well, with more long-term maintenance risk
(any future WC email update needs re-porting) for no real visual gain: this
flag's default layout (a clean white card, small logo, understated
typography, one solid accent button) already matches the "modern,
restrained" character of this site's own design system far better than a
heavy colored header band would in an email client. **Revised plan: no
template file overrides for the wrapper.** Instead, this theme's own
established pattern for locking a plugin option's value from code —
`add_filter( 'option_{name}', ... )`, already used for
`sportspress_league_menu_teams` etc. in `inc/sportspress.php` — pins these
seven options to real `--bl-*` token values, version-controlled and
resistant to an accidental wp-admin change drifting off-brand, rather than
leaving them as unpinned, hand-edited wp-admin state.

**One real accessibility catch found while mapping tokens:** `base_color`
drives BOTH the CTA button fill AND (unconditionally, under
`email_improvements`) the link text color. `--bl-ice` (`#74C0E1`) is
documented in this theme's own `style.css` as "FILL ONLY, never text on
light" (1.94:1 contrast) — using it as `base_color` would make every link
in every email nearly unreadable. `--bl-accent-text` (`#3F6E9D`, 5.13:1,
`style.css`'s own documented "links/accent text on light" token) is the
correct choice: real WCAG AA contrast for links, and WooCommerce's own
`wc_hex_is_light()` check on that same value picks white button text
automatically — a solid navy button with white text, which is both
accessible and a normal, professional email-button treatment (this site's
own skewed ice-fill/ink-text ribbon relies on a CSS transform unsupported
in email clients anyway, so a direct visual port was never on the table).

**Final option → token mapping:**

| Option | Value | Token |
|---|---|---|
| `woocommerce_email_background_color` | `#F7FBFC` | `--bl-paper` (outer canvas) |
| `woocommerce_email_body_background_color` | `#FFFFFF` | `--bl-white` (card surface) |
| `woocommerce_email_base_color` | `#3F6E9D` | `--bl-accent-text` (links + buttons) |
| `woocommerce_email_text_color` | `#132343` | `--bl-ink` (body copy, headings) |
| `woocommerce_email_footer_text_color` | `#2E4A74` | `--bl-ink-mid` (footer credit line) |
| `woocommerce_email_header_alignment` | `left` | matches this site's own left-aligned heading convention |
| `woocommerce_email_font_family` | `Helvetica` | closest of WooCommerce's fixed 10-option list (`EmailFont::$font`) to `Inter`/`system-ui` |
| `woocommerce_email_header_image_width` | `96` | sized for the real uploaded logo's own aspect ratio |

`woocommerce_email_header_image` (a real, already-uploaded ARL logo) and
`woocommerce_email_footer_text` (real league name + URL) are left as-is —
already correct, already real content, no placeholder to replace.

### Constraints specific to HTML email

The remainder of this section (typography/layout notes below) still
describes the underlying reasoning correctly; treat "Architecture" further
down as SUPERSEDED by the revision above for the wrapper specifically — it
still applies as-written for the two new account-email content templates
and the FUE/settings pieces.

Email clients are not browsers: no external stylesheets reliably load
(inline styles only, safe), no CSS custom properties, no flexbox/grid
(table-based layout), no reliable web fonts (Outlook desktop uses Word's
rendering engine — zero `@font-face` support). Every token below is
translated to its closest **web-safe** email equivalent, not `var(--bl-*)`
literally.

### Palette (from `style.css`, hex only — no custom properties in email HTML)

| Role | Token | Hex |
|---|---|---|
| Header/footer band | `--bl-ink-deep` | `#0D1729` |
| Body text | `--bl-ink` | `#132343` |
| Secondary text | `--bl-ink-mid` | `#2E4A74` |
| Accent fill (buttons, dividers) | `--bl-ice` | `#74C0E1` |
| Page background | `--bl-paper` | `#F7FBFC` |
| Card/content background | `--bl-white` | `#FFFFFF` |
| Border | `--bl-border` | (resolve from `style.css` at implementation time) |

Button text sits on `--bl-ice` fill — per this theme's own established rule
(`--bl-ice` is documented in `style.css` as "FILL ONLY, never text on
light"), button labels use `--bl-ink`, not white, on the ice-blue button
background, matching how `.bl-btn--primary` already works sitewide.

### Typography

- Headings: bold sans-serif fallback stack (`Arial, Helvetica, sans-serif`,
  bold weight, slight uppercase + letter-spacing where the design calls for
  it) as the closest safe approximation of `Barlow Condensed`'s condensed
  display feel — real condensed web fonts aren't renderable in Outlook.
- Body: `Arial, Helvetica, sans-serif` at 15–16px, 1.5 line-height — the
  closest safe stack to `Inter`'s own humanist-sans character (no
  `@font-face` reliance).
- No italic-as-default (WooCommerce's stock heading style is italic; this
  site's own display type uses italic for specific emphasis, not as a
  blanket rule — headings render upright, bold).

### Layout

Superseded by "Revision after reading..." above: WooCommerce core's own
`email_improvements`-flag layout (600px card, logo top, understated
typography, one solid button) is kept as-is structurally — no custom
600px-table/colored-band/skewed-ribbon markup is built. Buttons render via
WooCommerce's own `email-button.php` (a plain solid rectangle, no
`transform: skewX()` — unsupported in the email clients that matter here
anyway), sized by that template's own already-generous `16px 32px` padding,
which comfortably clears a touch target on the phone this audience mostly
reads email on.

### Architecture (revised — see "Revision after reading..." above)

Four pieces, no email wrapper template files:

1. **`inc/woocommerce.php`** — `add_filter( 'option_woocommerce_email_{name}', ... )`
   for each of the 8 options in the mapping table above, one function per
   option (or one small array-driven registration loop — decide at
   implementation time whichever reads more clearly next to this file's
   existing single-purpose filter functions), pinning them to the real
   token values so WooCommerce core's own `email-styles.php` renders every
   one of the 28 registered email types — and every currently-active FUE
   campaign, confirmed all 5 use FUE's "WooCommerce" template mode — in
   brand colors/type with zero template overrides. `option_{name}` filters
   this theme already uses for exactly this purpose (`inc/sportspress.php`,
   e.g. `option_sportspress_league_menu_teams`) are the established pattern
   to follow, not a new one.
2. **`inc/woocommerce.php`** — a second, small addition turning
   `apply_for_woo_emails` off inside wp-email-template's own stored
   `wp_email_template_general` option (a targeted `option_wp_email_template_general`
   filter merging that one key, not replacing the whole array), so its
   generic Verdana/Century-Gothic wrapper stops competing with WooCommerce's
   own — confirmed live this plugin's wrapper currently applies ON TOP of
   WooCommerce's for every WC/FUE email (`apply_for_woo_emails => "yes"`).
3. **`woocommerce/emails/customer-new-account.php`** and
   **`customer-reset-password.php`** (new overrides — confirmed neither
   exists in the theme today, both still render WooCommerce's own generic
   copy) — brand-voice copy matching this site's established tone (see
   `customer-completed-order.php`'s "We have received your registration"),
   built on the SAME `do_action()` hook sequence WooCommerce's own stock
   templates use (`woocommerce_email_header` → content →
   `woocommerce_email_footer`), so they inherit the exact same wrapper as
   every other email with zero new markup of their own.
4. A quick pass over the ~13 already-overridden content templates checking
   they use CSS classes WooCommerce's OWN `email-styles.php` still
   recognizes (`.email-introduction`, `.email-additional-content`, etc.) —
   these are the classes item 1's option filters actually style; a
   template using a stale/removed class would silently lose its styling
   under the newer `email_improvements`-flag markup.

### Testing

No email-sending integration test exists in this repo (nor should one —
outbound mail in CI is its own risk). Verification is:

- `wp eval` rendering `$email->style_inline( $email->get_content_html() )`
  for a representative sample (one order-status email, one YITH refund
  email, one FUE campaign, the two new account templates) and inspecting
  the resulting HTML directly — confirms the CSS actually landed inline
  (email clients ignore `<style>` blocks unless inlined) and no PHP notice
  fired.
- A real `wp eval`-triggered test send to a real inbox (this session's own
  established pattern for verifying user-facing behavior live, e.g. the
  player-photo-upload feature) — read visually before calling this done,
  not just "the HTML compiled."
- Existing PHPUnit suite stays green throughout (no new WooCommerce-adjacent
  PHP logic beyond simple template markup, but the settings-update function
  gets a real unit test — pure function, array in/out, matching this
  codebase's own established testing convention for WooCommerce-integration
  code, e.g. `CheckoutFieldGuidanceTest.php`).
