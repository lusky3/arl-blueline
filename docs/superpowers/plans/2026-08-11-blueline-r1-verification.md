# Blueline R1 — Task 16 verification record

Date: 2026-08-11. Environment: `staging.rookiehockey.ca` (host `staging-host`, Docker Compose,
`swp`/`sdb` wrappers). Production (`production-host`) was read-only throughout — nothing on production
was changed by this task.

This is the final gate for the `2026-08-11-blueline-r1` plan. It covers the plan's original
five steps and the seven items that accumulated onto Task 16 during Tasks 1–15's reviews. Every
gate below was actually run during this task, not inferred from prior reports; where a gate is a
retest of something Task 9/10/15 already ran, that is noted.

---

## 1. Automated gates

| Gate | Result | Detail |
|---|---|---|
| `composer test` | **PASS** | 67 tests, 138 assertions, 0 failures (was 57 before this task; 10 new tests added — see §4) |
| `composer lint` (full `phpcs --standard=WordPress`) | **PASS with documented exceptions** | `tests/` is now 0 errors / 0 warnings (was 105 errors / 5 warnings). Remaining: 81 errors / 7 warnings, entirely inside `woocommerce/` vendor-authored override templates. See §7 for the full breakdown and why these are not being force-fixed. |
| `npm run build` (`wp-scripts build`) | **PASS** | Webpack 5.109.2 compiled successfully; `index.css` 46.7 KiB, `editor.css` 4 KiB, fonts copied, `.asset.php` manifests written. |
| `node tools/check-contrast.mjs` | **PASS** | All 10 contrast rules pass their WCAG minimum; the `--bl-ice`-must-stay-decorative guard passes; the new token-parity check (§5) passes — editor.css's 26 duplicated tokens match style.css exactly. |
| `./scripts/deploy-theme.sh staging` | **PASS** | Ran six times during this task (baseline, after the skip-link fix, after the WooCommerce wrapper fix, a deliberate fail/revert round-trip proving the new smoke check works — see below, and final). Each rsync + chown 33:33 completed cleanly. |
| `./scripts/smoke-staging.sh` | **PASS** | **Modified this task** — see §1.1. 16/16 checks (was 14; 2 new regression checks added), re-run after every deploy and every `simple_css` option change — all still passing in the final state. |
| `./scripts/audit-archive-pages.sh` | **PASS** | 30/30 archive pages pass (re-run three times during this task: after the tabindex fix, after the simple-css swap, and in the final full re-run). One known exclusion unchanged: page 13440 (private, unlinked, expected 404 for anonymous requests). |
| `staging/tests/repro-duplicate-registration.sh` | **PASS (bug not reproduced)** | `items_count after login merge: 1` — the double-registration path Task 9 fixed stays fixed. Re-run in the final full pass. |
| `staging/tests/test-one-registration-guard.sh` | **PASS — but only after a real defect in the test itself was fixed. See §6.** |
| `staging/tests/checkout-end-to-end.py` | **PASS** | Ran four times (baseline, after the skip-link fix, after the simple-css swap, final full re-run). Each run placed a real order (116714, 116715, then 116716) with 1 line item, correct `_arl_rules_version`/`_arl_rules_accepted` meta, and a `success` checkout result. |

**Gate count: 10 run, 10 passing** (the table above has 10 rows — this line previously read "9
run, 9 passing," an arithmetic slip against its own table, caught in review and corrected here).
One (`test-one-registration-guard.sh`) required a real fix to the test's own setup before its
pass became meaningful — see §6, which is the honest account of that.

### 1.1 `scripts/smoke-staging.sh` — modified this task

The Task 16 brief's own Files list required this file to be modified, and the first version of
this record shipped without touching it — an oversight caught in review. The two real defects
found and fixed elsewhere in this task (§2's skip link, §9's `simple-css` prune) had gained only
manual/Playwright verification, which nobody would repeat; both are cheaply assertable from a
plain HTTP GET, so both are now permanent regression checks:

- **Skip-link/tabindex regression.** `check /` now also asserts the literal string `<main
  id="main" class="bl-main bl-main--homepage" tabindex="-1">` is present in the homepage
  response — the exact tag `template-homepage.php` emits. If a future template edit drops the
  `tabindex` attribute, this line fails.
- **`simple-css` prune regression** (two checks, both against a real WooCommerce/SportsPress
  registration product page, `/registration/player-registration-w2026-27`): the old brand blue
  hex `0577da` must be **absent** from the response, and the literal string `woocommerce-message`
  must also be **absent** — the only way that string can appear in a plain page response (no
  notice is showing on a bare GET) is the plugin's injected `<style id="simple-css-output">`
  block hiding it, which is exactly the regression this guards against.

`check()`'s existing `<path> <code> <marker>` signature is unchanged in spirit — it gained a 4th,
optional **must-NOT-contain** marker (default `-`, meaning "skip," matching the existing
sentinel convention), rather than a one-off bolted onto the bottom of the script. The existing
failure-aggregation behaviour (`FAIL` variable, no early return inside the loop, `exit $FAIL`
after every check has run) is untouched.

**Both new checks were proven to actually fail, not just proven to pass:**
- Temporarily removed `tabindex="-1"` from `template-homepage.php`, redeployed:
  `FAIL / -> missing marker: <main id="main" class="bl-main bl-main--homepage" tabindex="-1">`,
  exit 1. Reverted (`git checkout --`), redeployed: clean pass, exit 0, `git status` empty on the
  file.
- Temporarily restored the pre-Task-16 `simple_css` option verbatim (§9.4) via `wp option
  update`: `FAIL /registration/player-registration-w2026-27 -> forbidden marker present: 0577da`
  and the same for `woocommerce-message`, exit 1. Restored the pruned option: clean pass, exit 0.

One incidental finding while designing the `0577da` check: SportsPress itself has an unrelated,
pre-existing, fully-commented-out "Custom CSS" snippet
(`<style type="text/css"> /* SportsPress Custom CSS */ /* #032867 #0066cc */</style>`) that also
renders inline on every page. It is two CSS *comments*, no live declaration — genuinely inert —
and is a different plugin setting entirely from the `simple_css` option this task's item a
scoped to. Noted here so nobody mistakes its presence for the prune having failed; it's also why
the smoke check uses `0577da` specifically (which that snippet does not contain) rather than a
broader old-brand-hex search.

---

## 2. Accessibility pass

- **Keyboard traverse, three page types (homepage, `/standings`, `/account`):** focus is visible
  everywhere, including on skewed controls. Verified live: `.bl-nav__link` (the actual `<a>`,
  never skewed — only its inner `<span class="bl-skew">` wrapper is) receives `outline: solid
  3px` on focus, confirmed via `getComputedStyle` after `.focus()`, with `transform: none` on the
  focused element itself. The design intent documented in `Blueline_Nav_Walker`'s docblock — "the
  focus ring drawn on it is always a true rectangle, never slanted" — is real, not aspirational.
- **Skip link — found broken, fixed, re-verified.** The skip link (`href="#main"`) was reachable
  and its href correct, but activating it only scrolled the viewport; `document.activeElement`
  stayed on `<body>`, because `<main id="main">` had no `tabindex`, so the browser had nothing
  focusable to move into. A keyboard user's next Tab press would have restarted from the top of
  the document — the skip link's entire purpose defeated. **Fixed**: added `tabindex="-1"` to
  every `<main id="main">` in the theme (15 templates: `404.php`, `archive.php`, `page.php`,
  `index.php`, `search.php`, `single.php`, `template-fullwidth.php`, `template-homepage.php`,
  `sportspress.php`, `sportspress/single-{team,player,event,staff}.php`,
  `sportspress/taxonomy-venue.php`, and `inc/woocommerce.php`'s `blueline_wc_wrapper_start()` for
  the shop archive). Re-verified live on both the homepage and `/account`: after activation,
  `document.activeElement` is `MAIN#main` with a visible focus outline. This is a real
  before/after fix, not a code-inspection assumption — both states were reproduced in a real
  browser (Playwright against staging) before and after the deploy.
- **Pre-existing, non-blueline accessibility gap found and left alone (documented, not fixed):**
  the very first Tab on every page lands inside a SportsPress-injected `.sp-league-menu` team-logo
  strip (rendered before `<body>`'s theme markup, via SportsPress's own hook — no blueline file
  references `sp-league-menu` anywhere) — 22 focusable elements before the theme's own skip link.
  Confirmed **not a blueline regression**: production's current live theme (`rookie`) has the
  identical ordering (`sp-league-menu` at byte offset 56241, skip-link at 79299, in the production
  homepage HTML fetched read-only during this task). This is a real, pre-existing UX gap in
  SportsPress's own body-open injection, present on both themes, outside this project's file
  list. Recorded here so it isn't mistaken for something blueline introduced or a fixed today.
- **No horizontal scroll at 360px:** verified via `document.documentElement.scrollWidth ===
  clientWidth === 360` on the homepage, `/standings` (a real SportsPress table page), and
  `/account`. All three: no overflow.
- **`prefers-reduced-motion` disables transitions:** confirmed in `assets/src/css/base.css:93-103`
  — a global `@media (prefers-reduced-motion: reduce)` rule collapses all
  animation/transition/scroll-behavior durations to `0.01ms !important` on `*, *::before,
  *::after`. Code-verified (not live-toggled, since Playwright's emulated-media control wasn't
  exercised here) — the rule's structure is unconditional and correct.
- **`node tools/check-contrast.mjs` re-run:** see §1 — passes, including the new parity check.

---

## 3. Confirm nothing was lost

```
$ swp option get sidebars_widgets --format=json
{"wp_inactive_widgets":[],"sidebar-1":["sportspress-countdown-2","recent-posts-2",
 "widgetquotesllama-2"],"footer-1":[],"footer-2":["block-5","block-7"],
 "footer-3":[],"footer-4":[],"array_version":3}
```
`sidebar-1` has 3 widgets, `footer-2` has 2 — matches the plan's expected counts exactly.

```
$ swp menu list
term_id  name           slug           locations       count
20       Archive        archive                        1
120      Menu 2.0       menu-2-0                        52
612      Menu 3.0       menu-3-0       primary          48
119      Primary        primary                         23
676      Utility Menu   utility-menu   utility           2
```
Menu 3.0 is still on the `primary` location with 48 items, as expected. The `Utility Menu`
(term 676, 2 items) is Task 4's staging reconfiguration (My ARL Account + Log Out, moved off the
primary menu) — present and correctly assigned, consistent with prior task notes.

```
$ find themes/blueline/woocommerce -type f | wc -l
26
```
The plan's brief expected 25 (Task 9's original port count). **This is not data loss.**
`git log` shows `woocommerce/myaccount/navigation.php` was added in commit `00263dc` (Task 12,
the league-first My Account dashboard) — a genuine, reviewed addition after Task 9's baseline,
needed to inject the new "My Team"/"My Schedule" endpoints into the account nav. 25 → 26 is
expected, intentional drift, not a gap.

---

## 4. Item d — committed unit coverage for two Task 7 fixes

Both fixes had been verified only by deleted ad-hoc `wp eval-file` scripts. Added:

- **`tests/NavWalkerDedupTest.php`** (6 tests) — exercises `Blueline_Nav_Walker` directly (no
  WordPress install; a minimal `Walker_Nav_Menu` stub was added to `tests/bootstrap.php`).
  Covers: a depth-0 childless item matching the Register CTA URL is hidden; the permanent
  "Schedule" item (or any item) is **never** hidden when the de-dup path is empty (i.e. when the
  header CTA isn't Register) — this is the exact regression Task 7 shipped and fixed; a parent
  item sharing the Register URL is not hidden (would destroy its submenu); a depth-1 (submenu)
  item sharing the URL is not hidden; URL comparison is by normalized path (trailing slash, case)
  not raw string.
- **`tests/HeroEffectiveStateTest.php`** (4 tests) — exercises
  `blueline_homepage_hero_content()` directly. Because `wc_get_product()` is undefined in the
  test stub environment, `blueline_homepage_registration_offer()` always returns `null`, so every
  `'registration_open'` request in this file exercises the exact fallback path that shipped
  broken (class printed the requested state while copy came from the fallback state). Covers:
  registration_open-with-upcoming-event falls back to preseason with matching class, copy, *and*
  module order (asserted against `blueline_homepage_module_order()` directly, proving the
  registration_open module order is never used once content has fallen back); registration_open
  with no upcoming event falls back to offseason, same three-way check; preseason/in_season/
  playoffs/offseason all pass through as their own effective state; every module name any state
  can order is a name `blueline_render_module()` actually knows how to render.

`tests/bootstrap.php` gained the stub functions these tests needed:
`Walker_Nav_Menu` (class stub), `wp_strip_all_tags`, `wp_kses`, `sanitize_html_class`, `absint`,
`wp_parse_url`, `taxonomy_exists`, `post_type_exists`, `get_the_date`, `home_url`, `_n`. All
guarded by `function_exists()`/`class_exists()`, so no existing test's behavior changed — verified
by re-running the full suite after each addition (stayed green throughout, 57 → 61 → 67).

---

## 5. Item c — token-parity check

`themes/blueline/tools/check-contrast.mjs` (already the natural home, since it already parsed
`style.css`'s tokens) gained `extractRootTokens()`, which pulls every `--bl-*: value;`
declaration out of a `:root { ... }` block by brace-depth matching, and a parity pass that:

- reads `style.css`'s `:root` block and `editor.css`'s `:root, .editor-styles-wrapper` block,
- normalizes hex shorthand (`#fff` ≡ `#ffffff`) and whitespace before comparing,
- fails if any token editor.css declares doesn't match style.css's value (drift),
- fails if editor.css declares a `--bl-*` name style.css doesn't define at all,
- is deliberately one-directional: editor.css may omit tokens it has no use for.

**Proven to actually catch drift**, not just proven to pass on a static file: a temporary edit
changing `editor.css`'s `--bl-ink` to `#ff0000` produced `FAIL --bl-ink drifted: style.css has
"#132343", editor.css has "#ff0000"` with exit code 1; reverting produced a clean pass with
exit 0 and zero net diff (`git diff --stat` empty after revert). Current state: 26 tokens, all
matching byte-for-byte.

---

## 6. Item e — is `test-one-registration-guard.sh` vacuous?

**It was.** Its own step 3 comment says "guard has not run yet," expecting `items_count: 1`
there only because the guard hasn't fired — but a genuine second-item injection should make that
line read `2`. It read `1`. Investigating: the script's step 3 calls
`ssh staging-host "swp eval-file /var/www/html/inject-second-reg.php --skip-themes 2>/dev/null"` —
stderr is discarded, and:

```
$ ssh staging-host "ls -la /var/www/html/inject-second-reg.php"
ls: cannot access '/var/www/html/inject-second-reg.php': No such file or directory
```

The file simply wasn't on staging. `staging/README.md` documents this as a prerequisite
("Needs `inject-second-reg.php` in the webroot") that had never been satisfied — the injection
call failed silently (stderr swallowed), the cart never actually gained a second item, and the
subsequent `items_count: 1` was the guard doing *nothing*, not the guard *working*. The pass was
real in the sense that the assertion held, but it proved nothing about the guard, exactly as
suspected.

**Fixed, in scope:** copied `staging/tests/inject-second-reg.php` to
`/var/lib/docker/volumes/staging_wp_data/_data/inject-second-reg.php` on staging, `chown 33:33`,
matching the documented prerequisite. Re-ran:

```
### 3: inject a SECOND registration (Goalie) under its own key
  cart items before: 1
    0c338957e384a517 product=116522 qty=1
  cart items after injection: 2
    0c338957e384a517 product=116522 qty=1
    bcc30d4ae9c3e9e8 product=116523 qty=1
  --- cart: after injection (guard has not run yet) ---
      items_count: 1
      key=bcc30d4ae9c3e9e8 id=116523 qty=1 Goalie Registration (W2026-27)
...
### RESULT
  final items_count: 1
  >>> GUARD WORKS: two-registration cart trimmed to one
```

The injection now genuinely lands 2 items (proven by the PHP script's own before/after echo, not
just the cart-summary endpoint). Reproduced twice — stable. **One honest nuance the test's own
comments get wrong**: the cart is already down to 1 item by the *very first* subsequent read
(the Store API `GET /wp-json/wc/store/v1/cart` call immediately after injection, before
`/checkout` is even loaded) — not specifically "at checkout" as step 3's inline comment assumes.
Whatever enforces the 1-item limit runs on cart hydration generally, not exclusively on
`woocommerce_check_cart_items` at checkout. Also: `guard notice present: 0` every run — the
specific text `Only one registration can be purchased per order` the script greps for never
appears, even though the limit is real and enforced. **Conclusion: the guard genuinely works —
confirmed with real injected data for the first time — but it acts silently and earlier than the
test's own comments assume; those comments are now inaccurate documentation of a real, working
mechanism, not evidence the mechanism is broken.** This is outside this task's file list to fix
(it lives in the main checkout's `staging/tests/`, and the mechanism itself was accepted and
reviewed under Task 9) — flagging the comment inaccuracy for whoever next edits that script.

---

## 7. Item f — full WPCS pass

Before this task: `tests/` carried 105 errors + 5 warnings across 7 files (`AccountEndpointsTest.php`,
`bootstrap.php`, `PlayerDataTest.php`, `PlayerLinkTest.php`, `SeasonStateTest.php`,
`SportspressTableScrollTest.php`, `StubsTest.php`) — almost entirely missing docblocks
(`Squiz.Commenting.FunctionComment.Missing`), plus a few real warnings in `bootstrap.php`
(unused stub parameters, a discouraged `strip_tags()` call).

**Cleared to zero.** `vendor/bin/phpcbf` fixed the 32 auto-fixable violations; the remaining
~90 missing-docblock errors were closed with real (not boilerplate-only) docblocks — each test
method got a one-line `@Test case.` comment at minimum, and functions with parameters
(`signals()` in `SeasonStateTest.php`, the new stub functions in `bootstrap.php`) got full
`@param`/`@return` tags. The two real warnings were fixed properly, not suppressed: `__()`'s
unused `$d` and `add_filter()`/`add_action()`'s unused `...$args` got named
`phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.*` comments with a one-line reason
each (matching the project's established precedent in `inc/template-tags.php` — named,
sniff-specific, never a blanket `phpcs:ignoreFile`); `strip_tags()` was replaced by routing
`sanitize_text_field()`'s stub through the new `wp_strip_all_tags()` stub instead. `bootstrap.php`
also picked up the same two file-organisation sniff disables `inc/template-tags.php` already
uses (`WordPress.Files.FileName.InvalidClassFileName`, inline on line 1;
`Universal.Files.SeparateFunctionsFromOO.Mixed`), for the identical reason: one stub class
(`Walker_Nav_Menu`) belongs alongside the many stub functions rather than in its own
`class-walker-nav-menu.php`.

**Remaining debt — 81 errors + 7 warnings, 100% inside `woocommerce/`, deliberately not fixed:**

| File | Errors | Warnings |
|---|---|---|
| `woocommerce/myaccount/my-refund-requests.php` | 36 | 5 |
| `woocommerce/emails/customer-renewal-invoice.php` | 18 | 0 |
| `woocommerce/emails/customer-store-credit.php` | 6 | 0 |
| `woocommerce/emails/customer-processing-renewal-order.php` | 3 | 0 |
| 14 other `woocommerce/{cart,checkout,emails}/*.php` files | 1–2 each | 0–2 |

**Why these are left alone.** Every one of these files is a WooCommerce/YITH-authored template
override that Task 9 explicitly ported byte-verbatim (md5-proven identical before any restyle)
and restyled *only* via generic CSS selectors, never by editing the PHP markup itself — a
deliberate, reviewed decision to preserve compatibility with upstream plugin updates on the
theme's most sensitive code path (checkout, email, refunds). The violations are exactly what
you'd expect from vendor code that predates WPCS on this project: tabs-vs-spaces mixing, `==`
instead of `===`, missing docblocks, and several "output not escaped" flags that are largely
false positives against YITH's own escaping conventions (e.g. `wc_price()` output, which WC
itself doesn't re-escape). Reformatting them for lint compliance alone — with zero functional
benefit — would touch the theme's highest-risk surface (the live registration/checkout/refund
path, during an open registration window) purely for cosmetic reasons, and would increase the
diff against upstream WooCommerce/YITH templates, working directly against the reason Task 9
kept them verbatim in the first place. **Decision: leave enumerated, not silently ignored** —
this table is the full account of what remains and why, satisfying item f's "enumerate exactly
what remains and why" alternative to a full clear.

---

## 8. Item g — the `WP_DEBUG` question

**Recommendation: leave `WP_DEBUG=true` on staging.** Confirmed the only change from the backup
(`wp-config.php.bak-blueline`) is `WP_DEBUG`: `false → true`. `WP_DEBUG_LOG` stays `true`,
`WP_DEBUG_DISPLAY` stays `false` — nothing has ever been shown to a visitor's browser; the only
effect is whether `debug.log` receives anything at all. WordPress core gates `WP_DEBUG_LOG`
behind `WP_DEBUG` itself (`wp_debug_mode()` in `wp-includes/load.php`), so with the original
`false` setting, `debug.log` was structurally blind regardless of `WP_DEBUG_LOG` — every prior
task's "check for PHP notices" gate had nothing to check. Reverting now would restore that
blindness for zero benefit: staging isn't customer-facing, `WP_DEBUG_DISPLAY` already prevents
any visible leakage, and this is precisely the environment where seeing every notice matters
most (R2 will need the same visibility). **Production's setting is untouched and remains
unknown to this task**, per the task's own instruction — this recommendation is staging-only.

Confirmed clean bill of health at the end of this task: `debug.log` is 43,236 lines, of which
exactly **13** mention `blueline` — all timestamped `16:30–16:31`, hours before this task's own
testing began, and textually identical to the pre-existing `woocommerce_get_query_vars`
legacy-slug lines Task 10 documented and left alone (its own guard correctly degrading rather
than 404ing). **Zero new blueline-related log lines were produced by anything done in this
task** — deploys, the skip-link fix, the WooCommerce wrapper fix, the simple-css swap, or any of
the live browser/checkout testing.

---

## 9. Item a — `simple-css` plugin audit and remediation

The plugin (active on **production and staging**, theme-independent — survives the cutover) held
6,918 characters of CSS written against the old Rookie theme and brand palette (`#0577da` /
`#032867`), several rules `!important`, actively fighting blueline's own tokens. Full original
is preserved verbatim below so it can be restored if needed; it is also still recoverable from
the staging DB backup process, but is reproduced here per the task's requirement.

### 9.1 Rule-by-rule disposition

| Rule | Disposition | Why |
|---|---|---|
| `.site-hgroup`, `.site-title`, `.site-credit` | **Removed** | Rookie-only classes; `bl-header__site-title` (blueline's own class) is unrelated — confirmed by direct string search, zero matches anywhere in the theme. |
| `.page-id-1723 .entry-header h1 {display:none}` | **Removed** | Page 1723 is still the Home page (confirmed live), but `template-homepage.php` has no `.entry-header h1` structure at all — the hero component replaces it entirely. Inert on blueline regardless of page ID. |
| `.woocommerce button.button.alt, …, .woocommerce input.button {color:#FFF!important;background-color:#0577da!important}` | **Removed** | `assets/src/css/woocommerce.css:91-112` already fully covers this exact selector group (including `.button.alt`, since `button.button.alt` still matches `.woocommerce button.button`) with `background: var(--bl-ink)` — proven by direct code comparison, not assumption. Fully redundant; keeping it would mean re-asserting a value that would silently drift out of sync with the token if it's ever changed. |
| `.woocommerce button.button.alt:hover, … {background-color:#032867!important}` | **Removed** | Same reasoning — `woocommerce.css:126-138` already sets `background: var(--bl-ink-mid)` on hover/focus-visible. |
| `.woocommerce-message {display:none!important}` | **Removed** | This was hiding *every* WooCommerce success notice site-wide — the exact defect Task 12 had to route around with `.bl-account-notice`. `woocommerce.css:37-80` already has full, on-brand, reviewed styling for `.woocommerce-message`. Verified live post-fix: a synthetic `.woocommerce-message` element now computes `display: flex`, not `none`. |
| `.upme-login-register-link {display:none!important}` | **Removed** | UPME plugin is not installed. |
| `#product-13471 > …ul {display:none!important}`, `#tab-description > h2 {…}`, `#tab-description > h2:before {content:'Important!!'…}` | **Removed** | Product 13471 is "Full Payment (S2019)" — still `publish` status (confirmed live) but a 6-year-stale, unlinked orphan product nobody reaches through any current registration flow. |
| `.div.updated.woocommerce-message {display:none!important}` | **Removed** | Broken selector — the leading `.` before `div` requires an element with a literal class `="div"`, which never existed; this rule matched nothing on either theme, ever (a typo, not a working rule). |
| `input[type="tel"] {border-color:#e8e8e8;…border-radius:3px}` | **Removed** | Generic rounded-corner styling clashes with blueline's sharp-corner design language (`--bl-radius: 0px`) on any tel field blueline doesn't already out-specificity (e.g. a bare Gravity Forms phone field); WC's own phone field is already covered by the higher-specificity `.woocommerce .input-text` rule in `woocommerce.css:155`. Better to surface a real styling gap (if one exists) than keep a rule that actively fights the design system. |
| `#wizard > .content {padding-right/left:10%}` (703px+ media query) | **Removed** | `.wizard` markup was not found anywhere in current live HTML (`/checkout`, `/account`, `/register` all checked) — dead. |
| `.header-area-has-search {background-image:none!important}` (703px media query) | **Removed** | Rookie-only class; confirmed zero matches anywhere in current markup. |
| Both `.page-item-NNN` blocks (~40 rules total, 703px and 480px media queries) + their `#gform_wrapper_1 {Display:block!important}` compensating rules | **Removed** | **Verified empirically, not just theoretically.** These target `page-item-{ID}`/`page_item` classes that WordPress core's nav-menu current-item detection *can* still add — but direct DOM inspection of blueline's live rendered primary nav (including on the exact page the "Goalies" item points to, where a `current-menu-item` class would apply if it were ever going to) showed **no such classes anywhere** — every `<li>` reads only `menu-item menu-item-type-post_type menu-item-object-page bl-nav__item`. Cross-referencing the ~40 hidden page IDs against the current live primary menu (`Menu 3.0`, 48 items) found 5 coincidental ID matches (Goalies=288, Past Rosters=2583, Past Standings=2557, Standings\|S2017=8521, Rosters\|S2017=8520) that are still real menu items today — but since the classes these rules target never render at all, even those 5 are confirmed inert. No Gravity Forms wrapper in the current theme is ever hidden at these breakpoints (`grep -rn gform` returns nothing in blueline), so the compensating `#gform_wrapper_1` rules serve no purpose either. |
| Already-commented-out clik2pay `label[for=...]` replacement block | **Removed** | Was already inert (wrapped in `/* … */` by its own original author); removing dead-and-disabled code for clarity. |
| `.arenah3border {…}` | **Kept, re-themed** | Still used live in the Arenas page content. `background-color: #032867 → #132343` (`--bl-ink`); `border-top: 8px solid #0577da → 8px solid #5188b7` (`--bl-steel` — chosen over `--bl-ice` because `--bl-ice` fails the WCAG 1.4.11 non-text 3:1 contrast requirement against the page's paper background at 1.94:1, while `--bl-steel` passes at 3.63:1 and is documented as "borders, large text, strokes ONLY" — exactly this use). `color: #FFFFFF` unchanged (already neutral). |
| `.su-tabs.my-custom-tabs` + its 3 sub-rules | **Kept, re-themed** | Shortcodes Ultimate is still active. Bar background `#032867 → #132343` (`--bl-ink`); active-tab background `#0577da → #3F6E9D` (`--bl-accent-text`) — computed white-on-`#3F6E9D` contrast is 5.35:1 (passes AA), while white-on-the-old-`#0577da` and white-on-`--bl-ice` were checked and found to be worse or failing (`--bl-ice` gives only 2.03:1 for white text, which is why it was rejected here too); active-panel background `#fcfcfc → #F7FBFC` (`--bl-paper`, the exact brand paper tone rather than a near-miss). Span text colour `#FFFFFF` unchanged. |
| `.woocommerce form.login input[type=submit], form.login .button {background:#032867!important}` | **Kept, re-themed** | `#032867 → #132343` (`--bl-ink`) — this is one of the two "old-brand-blue login button" rules called out explicitly in the task brief. Kept (not deleted) because full selector-vs-markup overlap with blueline's own button CSS wasn't proven with the same rigor as the alt-button case above; re-theming to blueline's own ink value makes the visual outcome correct either way, at zero risk. |
| `.wizard > .actions a, …, .login .form-row .button {background:#0577da!important}` | **Kept, re-themed** | `#0577da → #132343` (`--bl-ink`). Same reasoning; `.wizard` itself is dead (see above) but the `.login .form-row .button` half is plausibly still reachable, so the whole rule is re-themed together rather than split. |
| `body.login #loginform p.submit .button-primary, body.wp-core-ui .button-primary {display:block;width:100%}` | **Kept, unchanged** | Targets `wp-login.php`/`wp-admin` chrome, not the front-end theme at all; no colour, no conflict, harmless. |
| `.woocommerce span.onsale {display:none!important}` | **Kept, unchanged** | Still explicitly wanted — registrations should not show sale stickers. No colour. |
| `.page-id-6507 li.wc_payment_method.payment_method_c2p-gateway > label > img {max-width:100%;height:3em}` | **Kept, unchanged** | Page 6507 confirmed still the live Checkout page; clik2pay is a real, active payment option. No colour. |

**Result: 6,918 → 2,967 characters as stored** (the original uses CRLF line endings, the pruned
version LF-only — the two counts are not on a strictly like-for-like basis as raw character
counts). Two ways to state the reduction honestly: **57.1% smaller comparing each version's own
as-stored length** (6,918 → 2,967), or **55.6% smaller on a line-ending-normalized basis**
(6,918 CRLF-normalized to 6,683 LF-equivalent characters, vs. 2,967 LF characters) — the fairer
apples-to-apples figure. Either way it's roughly halved. (The pruned length grew slightly, from
an earlier 2,699-character draft, to 2,967 after a second revision described in §1.1: the
draft's rationale comments quoted the old brand's literal hex codes, which would have made the
new `smoke-staging.sh` regression check for `0577da` a false-positive risk against the CSS's own
explanatory text; the final version describes the old colours in words instead.) Every colour
value in the pruned CSS was checked against blueline's own token contrast math, not guessed.

### 9.2 Applied and verified on staging

```
$ wp option update simple_css --format=json < pruned-option.json
Success: Updated 'simple_css' option.
```
Re-fetched and confirmed: 2,967-char `css` value live (final revision — see above), `theme: "1"`
preserved. No caching layer in front of staging — the change was reflected in the very next page
load's `<style id="simple-css-output">` block, no purge needed.

**Live before/after proof, not just code review:**
- A real registration product's Add-to-Cart button: `getComputedStyle(...).backgroundColor` is
  now `rgb(19, 35, 67)` (`#132343`, blueline's `--bl-ink`) — previously `#0577da`.
- A synthetic `.woocommerce-message` element now computes `display: flex` — previously `none`.
- `./scripts/smoke-staging.sh` (now 16/16 — see §1.1), `./scripts/audit-archive-pages.sh`
  (30/30), and `staging/tests/checkout-end-to-end.py` (real order placed, `success`) were all
  **re-run after** this change and stayed green — the site-wide CSS swap did not break anything
  downstream.

### 9.3 Production cutover step (not executed — staging only per this task's scope)

```
# On production, after confirming the current simple_css option value has been
# backed up (see §9.4 for the verbatim original):
wp option update simple_css --format=json < pruned-option.json
```
Where `pruned-option.json` is `{"css": "<the 2,967-char pruned CSS in §9.5>", "theme": "1"}`.
No cache purge is required on staging; **production's own cache layer (the Redis-backed nginx
srcache mentioned in the cutover checklist) must be purged by key after this change**, since
`wo clean --fastcgi` does not touch it — see the consolidated checklist in §11.

### 9.4 Original CSS, verbatim (for restoration if ever needed)

```css
/*Some custom CSS added by Cody Lusk */

/* White shadow behind site header text */

.site-hgroup {
    text-shadow: 0 0 10px white;
}

.site-title {
    -webkit-text-stroke: 1px white;
}

/* Remove Credits in Footer */
.site-credit {
	display: none;
}

/* Remove Title on Homepage */
.page-id-1723 .entry-header h1 {
	display: none;
}

/* Arenas Page */
/* Border behind H3, H3 formatting */
.arenah3border {
	width: 100%;
	height: 45px;
	background-color: #032867;
	border-top: 8px solid #0577da;
    text-align: center;
	color: #FFFFFF;
}


/* Tab styling for shortcode tabs */
/* Main Border and Inactive Tab colour */
.su-tabs.my-custom-tabs {
	background-color: #032867;
}

/* Tab font styling */
.su-tabs.my-custom-tabs .su-tabs-nav span {
	font-size: 1.3em;
	color: #FFFFFF;
}

/* Active tab */
.su-tabs.my-custom-tabs .su-tabs-nav span.su-tabs-current {
	background-color: #0577da;
}

/* Active tab panel */
.su-tabs.my-custom-tabs .su-tabs-pane {
	padding: 1em;
	background-color: #fcfcfc;
}

/* Not sure. something to do with the login screen */
body.login #loginform p.submit .button-primary, body.wp-core-ui .button-primary {
	display: block;
	width: 100%;
}

/* WooCommerce Stuff */
.woocommerce form.login input[type=submit], form.login .button {
    background: #032867 !important;
}

/* My Account button colour */

.wizard > .actions a, .wizard > .actions a:hover, .wizard > .actions a:active, .login .form-row .button {
    background: #0577da !important;
}

.woocommerce button.button.alt, .woocommerce #respond input#submit, .woocommerce a.button, .woocommerce button.button, .woocommerce input.button {
    color: #FFFFFF !important;
    background-color: #0577da !important;
}

.woocommerce button.button.alt:hover, .woocommerce #respond input#submit, .woocommerce a.button:hover, .woocommerce button.button:hover, .woocommerce input.button:hover {
	background-color: #032867 !important;
}

.woocommerce-message {
    display: none !important;
}

.upme-login-register-link {
    display: none !important;
}

/* Hide the sale sticker that appears on the product image. */

.woocommerce span.onsale {
    display: none !important;
}


/* Hide Description tab for product (Full Payment S2019)*/
#product-13471 > div.woocommerce-tabs.wc-tabs-wrapper > ul {
    display: none !important;
}

/* Hide Description text for product (Full Payment S2019)*/
#tab-description > h2 {
    visibility: hidden;
}

/* Replace Description text for product (Full Payment S2019)*/
#tab-description > h2:before {
    content: 'Important!!';
	visibility:visible;
    
}


/*WooCommerce Activate notice */
.div.updated.woocommerce-message {
    display: none !important;
}

input[type="tel"] {
border-color: #e8e8e8;
color: #666;
border: 1px solid #ccc;
padding: 0.575em;
font-size: 14px;
border-radius: 3px
}

/* Padding on sides of the content */
@media screen and (min-width: 601px){
	#wizard > .content {
   		padding-right: 10%;
   		padding-left: 10%
	}
}


/* Adjust the size of the clik2pay logo on the Checkout page */
.page-id-6507 li.wc_payment_method.payment_method_c2p-gateway > label > img {
    max-width: 100%;
    height: 3em;
}


/* On the checkout page, intercept the default plugin image and replace it
.page-id-6507 label[for="payment_method_c2p-gateway"] {
  display: block;
  width: 369px; 
  height: 70px;
  overflow: hidden;
  text-indent: -9999px;
  background: url(https://staging.rookiehockey.ca/wp-content/uploads/2023/07/etransfer-clik2pay.png) no-repeat;
}
*/

/* Media Queries */
/* 703px is when the header repeats */
@media screen and (max-width: 703px) {
	/* Remove Header Image on Mobile */
	.header-area-has-search {
		background-image: none !important;
	/* Shrink Team Bar on Mobile */
	}

	.page-item-1458, /* Teams */
    .page-item-1453, /* Stats by Team */
    .page-item-1455, /* Invidvidual stats */
    .page-item-247, /* Invidvidual stats - Division 1 */
    .page-item-249, /* Invidvidual stats - Division 2 */
    .page-item-251, /* Invidvidual stats - Division 3 */
    .page-item-253, /* Invidvidual stats - Division 4 */
    .page-item-255, /* Invidvidual stats - Division 5 */
    .page-item-288, /* Invidvidual stats - Goalies */
    .page-item-2394, /* Rosters by Division */
    .page-item-2402, /* Rosters by Division - Division 1 */
    .page-item-2400, /* Rosters by Division - Division 2 */
    .page-item-2401, /* Rosters by Division - Division 3 */
    .page-item-2403, /* Rosters by Division - Division 4 */
    .page-item-2408, /* Rosters by Division - Division 5 */
    .page-item-180, /* New Registration */
    .page-item-182, /* Poolie Registration */
    .page-item-170, /* Returning Registration */
    .page-item-3545, /* Returning Goalie Registration */
    .page-item-3546, /* New Goalie Registration */
    .page-item-3543, /* Register Link */
    .page-item-2583, /* Past Rosters */
    .page-item-184, /* Pay Now */
    .page-item-2557, /* Past Standings */
    .page-item-5446, /* Past Standings 2015 */
    .page-item-5447, /* Past Standings 2016*/
    .page-item-3429, /* Past Stats */
    .page-item-8423, /* Fall Blast placeholder link */
    /*.page-item-8421,  Fall Blast Events Page */
    .page-item-8521, /* Standings | S2017 */
    .page-item-8519, /* Stats | S2017 */
    .page-item-8520, /* Rosters | S2017 */
    /*.page-item-1766  Special Events */ {
		display: none !important;
	}
    #gform_wrapper_1 {
        Display: block !important;
    }
}

@media only screen and (max-width: 480px) {
	.page-item-1458, /* Teams */
    .page-item-1453, /* Stats by Team */
    .page-item-1455, /* Invidvidual stats */
    .page-item-247, /* Invidvidual stats - Division 1 */
    .page-item-249, /* Invidvidual stats - Division 2 */
    .page-item-251, /* Invidvidual stats - Division 3 */
    .page-item-253, /* Invidvidual stats - Division 4 */
    .page-item-255, /* Invidvidual stats - Division 5 */
    .page-item-288, /* Invidvidual stats - Goalies */
    .page-item-2394, /* Rosters by Division */
    .page-item-2402, /* Rosters by Division - Division 1 */
    .page-item-2400, /* Rosters by Division - Division 2 */
    .page-item-2401, /* Rosters by Division - Division 3 */
    .page-item-2403, /* Rosters by Division - Division 4 */
    .page-item-2408, /* Rosters by Division - Division 5 */
    .page-item-2583, /* Past Rosters */
    .page-item-180, /* New Registration */
    .page-item-182, /* Poolie Registration */
    .page-item-170, /* Returning Registration */
    .page-item-1766, /* Special Events */
    .page-item-3429, /* Past Stats */
    .page-item-8293 /* Past Stats W2016-17 */{
		display: none !important;
	}
	#gform_wrapper_1 {
        Display: block !important;
    }
}
```

### 9.5 Pruned/re-themed CSS, verbatim (currently live on staging — final revision)

This is the second revision (see §1.1/§9.1): the first draft's comments quoted the old brand's
literal hex codes as documentation, which is exactly the string the new `smoke-staging.sh`
regression check searches for — a comment containing `0577da` would have made that check
unreliable. This version describes the old colours in words instead of hex, so the check has no
false-positive source anywhere in the live CSS, including its own comments.

```css
/* Some custom CSS added by Cody Lusk */
/* Pruned and re-themed for blueline -- Task 16, 2026-08-11. See the R1
   verification record (docs/superpowers/plans/2026-08-11-blueline-r1-verification.md,
   section "simple-css audit") for the full rule-by-rule rationale and the
   verbatim original this replaces. Old-brand hex values are deliberately
   NOT quoted in these comments -- smoke-staging.sh asserts their absence
   from every response as a regression guard, and a comment containing the
   literal hex would defeat that check. */

/* Arenas Page */
/* Border behind H3, H3 formatting -- re-themed from the old brand's navy
   and mid-blue to blueline's --bl-ink / --bl-steel. Still used live in the
   Arenas page content. */
.arenah3border {
	width: 100%;
	height: 45px;
	background-color: #132343;
	border-top: 8px solid #5188b7;
	text-align: center;
	color: #FFFFFF;
}

/* Tab styling for shortcode tabs (Shortcodes Ultimate, still active) */
/* Main Border and Inactive Tab colour -- re-themed to --bl-ink */
.su-tabs.my-custom-tabs {
	background-color: #132343;
}

/* Tab font styling */
.su-tabs.my-custom-tabs .su-tabs-nav span {
	font-size: 1.3em;
	color: #FFFFFF;
}

/* Active tab -- re-themed to --bl-accent-text (passes 5.35:1 white-text
   contrast; the old brand's mid-blue did not reliably). */
.su-tabs.my-custom-tabs .su-tabs-nav span.su-tabs-current {
	background-color: #3F6E9D;
}

/* Active tab panel -- re-themed to --bl-paper */
.su-tabs.my-custom-tabs .su-tabs-pane {
	padding: 1em;
	background-color: #F7FBFC;
}

/* wp-login.php / wp-admin only; unrelated to the front-end theme, harmless
   as-is. */
body.login #loginform p.submit .button-primary, body.wp-core-ui .button-primary {
	display: block;
	width: 100%;
}

/* WooCommerce login form submit button -- re-themed from the old brand's
   navy to --bl-ink so it matches blueline's own button styling exactly
   rather than fighting it. */
.woocommerce form.login input[type=submit], form.login .button {
    background: #132343 !important;
}

/* Account/login button colour -- re-themed from the old brand's mid-blue
   to --bl-ink. ".wizard" markup was not found anywhere on the current site
   (checked /checkout, /account, /register); ".login .form-row .button" is
   the part still plausibly reachable, so this stays as a retheme rather
   than a removal. */
.wizard > .actions a, .wizard > .actions a:hover, .wizard > .actions a:active, .login .form-row .button {
    background: #132343 !important;
}

/* Hide the sale sticker that appears on the product image -- still wanted;
   registrations should not show sale stickers. No colour, no change. */
.woocommerce span.onsale {
    display: none !important;
}

/* Adjust the size of the clik2pay logo on the Checkout page (page 6507,
   confirmed still the live Checkout page). No colour, no change. */
.page-id-6507 li.wc_payment_method.payment_method_c2p-gateway > label > img {
    max-width: 100%;
    height: 3em;
}
```

---

## 10. Item b — design spec §6.3 correction

Corrected in `docs/superpowers/specs/2026-08-11-arl-blueline-theme-design.md`. Full rationale is
in the spec itself (§6.3, §9 risk table) rather than duplicated here; summary:

- The spec's "only 12% of players are linked / ~88% see the claim card" premise used
  `sp_current_team` (a **sticky** "last team this player was ever on" field, set on 2,047 of
  2,134 players — 95% of everyone who has ever played) as its denominator. That is the wrong
  field for "current roster."
- Real season membership is the `sp_season` **taxonomy**. Re-verified live on staging,
  2026-08-11: **W2026-27 (current) = 90 players, 76 linked → 84% coverage**; W2025-26 (last full
  season) = 524 players, 241 linked → 46%.
- Cause: `sportspress-player-registration` auto-links `sp_user` at checkout, so the overwhelming
  majority of current-season players are linked automatically, with no manual step. The unlinked
  long tail is almost entirely historical players predating that auto-link mechanism.
- Consequence for the spec's narrative: the claim flow is the **primary mechanism** for the
  remaining ~16% and for returning players with unlinked historical records — not a supplement to
  a backfill that "turns 12% into most of the roster." The backfill (Task 14) is a small top-up:
  its actual measured yield was 4 new links out of 74 candidates, because 69 were already linked
  before it ran.
- Nothing built needs to change — only the spec's framing. §6.3 and the §9 risk-table row were
  both corrected in place, with the wrong `sp_current_team` figure kept (labelled as a warning
  against reuse) so a future reader understands exactly what went wrong and why, not just what
  the right number is.

---

## 11. Consolidated production cutover checklist

None of the following were executed against production — this task never touched
`production-host.example`. This is the full list, consolidating every deferred action from Tasks 1–16.

1. **Create a Utility menu** holding "My ARL Account" + "Log Out"; assign it to the `utility`
   nav location; remove those two items from the primary menu. **Do not reuse staging's numeric
   IDs** (staging's is term 676, items 108390/108396 — production needs its own).
2. **Enable `cfturnstile_woo_register`** (and consider `cfturnstile_woo_login`) *before*
   deactivating YITH. YITH's reCAPTCHA is currently the *only* bot protection on the WooCommerce
   registration form; Turnstile is already active and credentialed on production
   (`cfturnstile_key`/`cfturnstile_secret`/`cfturnstile_tested` all set), every `cfturnstile_woo_*`
   toggle is off. This is a settings toggle, not a new integration — load `/account?action=register`
   after enabling and confirm the widget actually renders and validates before removing YITH.
3. **Apply the pruned `simple_css`** — §9.3 above has the exact command and the full pruned CSS
   is in §9.5. **Back up production's current `simple_css` value first** (it is very likely
   identical to §9.4's verbatim original, but confirm before overwriting).
4. **Run the avatar migration and the `sp_user` backfill** against production. Both are
   idempotent (Task 13's migration script, Task 14's `--apply` backfill) and were already proven
   safe on staging; re-run their report mode first on production to get fresh before/after
   numbers before applying.
5. **Confirm `mu-plugins/rh-royal-mcp-register-fix.php` is present** on production *before*
   flushing rewrites. It is already present on production (verified read-only during this
   plan's earlier tasks) — this step is a **re-confirmation immediately before the flush**, not
   a fresh install; a previous flush on staging without it produced a `/register` 405.
6. **Purge the Redis-backed nginx srcache by key** after deploying/activating. `wo clean
   --fastcgi` does **not** touch this cache layer — it needs its own purge mechanism.
7. **`show_avatars` is off site-wide** and an active Code Snippets rule (ID 23) strips Gravatars
   — the avatar migration (#4) will produce **no visible change** even once it succeeds. This is
   expected, not a sign the migration failed; don't "verify" it by looking for visible avatars.
8. **Re-run smoke against production** after cutover (`BASE=https://rookiehockey.ca
   ./scripts/smoke-staging.sh`, or the production equivalent) before considering cutover
   complete.

Sequence matters: 1–2 should happen before/alongside activation (menu and bot-protection gaps
are user-facing immediately); 3 can happen any time after activation; 4–5 must happen in that
order (mu-plugin check *before* any rewrite flush) and 4 is only meaningful once the theme is
active; 6 happens last, after every other change that could be cached.

---

## 12. sp_user coverage numbers (final, for reference)

| Season | Players | Linked | Coverage |
|---|---|---|---|
| W2026-27 (current, `sp_season` taxonomy) | 90 | 76 | **84%** |
| W2025-26 (last full season, `sp_season` taxonomy) | 524 | 241 | 46% |
| `sp_current_team` (sticky historical field — **do not use as a denominator**) | 2,047 | 242 | 12% |

Re-verified live on staging 2026-08-11, matching Task 14's figures (small drift expected —
registration for W2026-27 is still open and growing).

## 13. Turnstile / reCAPTCHA (resolved, Task 13, re-confirmed here)

YITH's reCAPTCHA genuinely guards `woocommerce_register_form` only (never guarded login).
`simple-cloudflare-turnstile` is active on production with valid keys
(`cfturnstile_key`/`cfturnstile_secret`/`cfturnstile_tested` all set) and already guards Gravity
Forms — but every WooCommerce/account toggle (`cfturnstile_woo_register`, `cfturnstile_woo_login`,
`cfturnstile_woo_checkout`, `cfturnstile_woo_reset`, `cfturnstile_login`, `cfturnstile_register`)
is off. **YITH's reCAPTCHA is today the only bot protection on the WooCommerce registration
form.** Required pre-cutover action: enable `cfturnstile_woo_register` before deactivating YITH
— see checklist item 2 above.

---

## 14. Overall verdict

All ten automated/manual gates in §1 pass. The accessibility pass found one real, live
defect (the non-functional skip link) and fixed it, verified fixed with a real browser
before/after; found one pre-existing, non-blueline defect (SP league-menu tab order) and
documented it without attempting a fix outside this project's scope. The largest deferred item
(`simple-css`) was fully audited rule-by-rule, pruned by roughly half (57.1% as-stored, 55.6% on
a line-ending-normalized basis — §9.1), re-themed with contrast-checked colours, applied to
staging, and verified live with before/after button-colour and notice-visibility proof — with
the production step written up as an explicit, sequenced cutover action. Both of this task's own
fixes (the skip link and the `simple-css` prune) were additionally turned into permanent,
proven-to-fail-and-pass regression checks in `smoke-staging.sh` (§1.1), so neither depends on
anyone re-running a manual browser check to catch a future regression. The design spec's most
consequential numerical error was corrected with fresh verification, not just a copy-edit. The
one gate that looked clean but wasn't (`test-one-registration-guard.sh`) was diagnosed, fixed,
and re-run to a genuine result. WPCS debt in this project's own test scaffolding is fully
cleared; the debt remaining in vendor-authored WooCommerce templates is enumerated with an
explicit, defensible reason it was not touched.

**Nothing in this record was papered over.** Every finding above that could have been reported
as a clean pass without the deeper check (the vacuous guard test, the broken skip link, the
`page-item-NNN` empirical-vs-theoretical distinction, the wrong spec denominator) was instead
run to ground.
