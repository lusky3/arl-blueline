# SDD ledger — plan: docs/superpowers/plans/2026-08-11-blueline-r1.md

Task 1: complete (commits 3dc3aae..4500598, review clean)
Task 1: ⚠️ resolved by controller — staging shows `blueline inactive`, `rookie-child` still active. Correct for this stage.
Task 1: minor (deferred): scripts/smoke-staging.sh omits `set -e` on purpose (needs to aggregate failures); add a one-line comment justifying it.
Task 1: minor (deferred): WPCS/phpcs lint was never executed; a WPCS pass must happen before merge (Task 16 gate covers it).
Task 1: minor (deferred): themes/blueline/index.php references content parts that do not exist until Task 5; renders an empty <main> until then.

Task 2: complete (commits 4500598..7e0dcda, review clean)
Task 2: ⚠️ resolved by controller — package.json `tokens:check` wiring was verified verbatim in Task 1's review; not in this diff because Task 1 created it.
Task 2: note — reviewer independently re-derived the WCAG luminance/ratio maths and reproduced all asserted ratios exactly; inverted ice-guard orientation confirmed correct.
Task 2: minor (deferred): several tokens (ink-deep, white, border, border-strong, semantic, radius, shadow, most of type/spacing scale) are unused until later tasks; correctness unexercised so far.
Task 2: minor (deferred): --bl-border / --bl-border-strong not covered by the contrast guard; add coverage when a task first uses them as UI-component boundaries (WCAG 1.4.11).

CONTROLLER ENV FIX (after Task 3): staging `wp-config.php` had WP_DEBUG=false, making every task's "check for PHP notices" gate blind.
  Set WP_DEBUG=true (WP_DEBUG_DISPLAY stays false, WP_DEBUG_LOG stays true). Backup at
  /var/lib/docker/volumes/staging_wp_data/_data/wp-config.php.bak-blueline on staging-host.
  Verified: 44 log entries, ZERO mention blueline. All pre-existing plugin deprecations
  (checkout-field-editor-pro, follow-up-emails, paypal-for-woocommerce) plus the expected
  "Theme without header.php/footer.php" pair, which Task 4 resolves.
  NOTE: revert this before the production cutover comparison, or leave on deliberately — decide in Task 16.

Task 3: complete (commits 7e0dcda..5093323, review clean)
Task 3: note — implementer caught a latent bug: default wp-scripts webpack hashes font filenames, which would have silently desynced the PHP preload hrefs from the built CSS. Fixed via a webpack rule override.
Task 3: note — Inter self-hosted as one variable font (`font-weight: 400 700`); reviewer confirmed correct, nothing requests an out-of-range weight.
Task 3: minor (deferred): webpack.config.js woff-rule override locates the rule by stringified-regex match — version-coupled to @wordpress/scripts internals.
Task 3: minor (deferred): 2 PHPCS violations remain inside the verbatim-mandated register_nav_menus() block; add `phpcs:ignore` so the Task 16 WPCS gate does not read them as unreviewed drift.
Task 3: minor (deferred): Inter variable range is 400-700; shipped fvar axis is 100-900, so widen if a later task needs 300 or 800.

Task 4: implemented at dd496fc. Review returned Needs fixes — 4 Important findings:
  (1) nav href escaped with esc_attr() not esc_url() [plan-mandated escaping constraint]
  (2) phpcs:ignoreFile <sniff> silently disabled ALL linting for inc/template-tags.php, the file holding
      this task's escaping logic — reviewer proved it empirically (0 errors with, 12+1 without)
  (3) BLUELINE_VERSION bump replaced one hand-maintained static version with another
  (4) desktop dropdown could overflow the viewport right edge
Task 4: note — implementer found and fixed two real bugs outside its file list: style.css (sole source of all
  --bl-* tokens) was never enqueued, so NO token reached the browser; and BLUELINE_VERSION was static against
  a 30-day immutable cache. Task 3's review passed enqueue.php without catching either.
Task 4: fix round 1/5 — original implementer could not be resumed (no transcript); dispatched a fresh
  implementer with brief + report + findings. All 4 Important + 2 Minor addressed (commits dd496fc..51b0b0a).
  Re-review verdict: findings 1-3 cleanly closed; finding 4's CSS fix correct.
Task 4: fix round 2/5 — commits 51b0b0a..96de31e. Finding B CLOSED (re-verified against the corrected,
  non-degenerate layout at 900/1100/1400 plus a genuine single-row case at 1800px; zero overflow either edge;
  the suspected left-edge mirror risk does not manifest for this menu's real geometry). Finding A root-caused
  (a flex container with width:auto nesting a flex-grow child that itself nests flex-wrap content resolves to
  fit-content; width:100% fixes it) but only PARTIALLY resolved: implementer gave the header its own 1760px cap
  to reach single-row, and correctly refused to shrink type below AA-safe bounds to force it at 1200px.
Task 4: fix round 3/5 IN PROGRESS — controller ruling on round 2's concern. The implementer is right that ten
  top-level items cannot fit 1200px at accessible type sizes; the wrong conclusion is to widen the header.
  This is an IA problem. Round 3 requires:
    (a) revert the header-only 1760px cap (a header 560px wider than every other band was never designed);
        keep the width:100% root-cause fix
    (b) register a `utility` nav location rendered in .bl-header__actions
    (c) de-duplicate the Register CTA against the primary menu — "Register to Play" is currently rendered
        TWICE (once as a nav item, once as the CTA). Compare on post_name/URL path, never get_permalink()
        (Page Links To filters it site-wide)
    (d) configure staging only: Utility menu holding "My ARL Account" + "Log Out", removed from primary,
        leaving 7 primary items; production needs the same config at cutover — goes in the release notes
    (e) the theme must degrade gracefully with the unmodified 10-item menu, since production will have it
        until configured; a wrapped second row must look deliberate
Task 4: fix round 3/5 — commits 96de31e..5513776. Combined round-2+3 re-review (51b0b0a..5513776):
  ALL findings ADDRESSED, no new Critical/Important breakage. Re-reviewer independently rebuilt the header
  in an isolated repro and reproduced the measurements rather than trusting the report.
Task 4: complete (commits 5093323..5513776, review clean after 3 fix rounds)
Task 4: PRODUCTION CUTOVER REQUIREMENT (release notes) — staging was reconfigured; production needs the same:
  create a Utility menu holding "My ARL Account" + "Log Out", assign it to the `utility` nav location, and
  remove those two items from the primary menu (Menu 3.0). Do NOT reuse staging's numeric IDs. Without this,
  production renders all 10 primary items and wraps to 2 rows — verified tidy, but not the intended design.
Task 4: minor (deferred): CTA de-dup compares `$item->url`, which core's wp_setup_nav_menu_item() populates via
  get_permalink() internally. The theme makes no direct call, and comparing the actual rendered href is arguably
  the right design, but it is not fully immune to Page Links To's per-post filtering. Revisit if a duplicate
  Register item ever appears in production.
Task 4: minor (deferred): header.css:111 shrank .bl-header__site-title max-width 16rem -> 8rem; the text-fallback
  brand (used only when no custom logo is set) will ellipsis more aggressively. Cosmetic.
Task 4: superseded detail — two Important items were open entering round 3:
  (A) `.bl-header__inner` renders ~614-740px instead of filling the container, causing the nav to flex-wrap
      early. Implementer called it pre-existing/out-of-scope; controller ruling: Task 4 created header.php and
      header.css, so it is this task's defect.
  (B) Finding 4's live verification was INVALID — the measurement table reports identical left/right values for
      items of different lengths at different positions, only possible if each was measured wrapped alone on its
      own flex line (symptom of A). The single-row packed layout was never exercised. Also, the left:0 -> right:0
      change leaves the symmetric LEFT-edge case for early items unmeasured.
  Required: fix A, then re-measure both edges at 900/1100/1400px on the first 2-3 and last 2-3 top-level items,
  proving the menu is genuinely on one row.

Task 5: implemented at b78976b. Review returned Needs fixes — 1 Critical + 1 Important + 1 Minor.
Task 5: CRITICAL was self-inflicted by a volunteered "bonus fix": the editor-iframe CSS mechanism (a) left
  editor.css live and UNSCOPED in the parent wp-admin <head>, restyling the real Post/Page edit screen chrome
  with Barlow Condensed italic caps on a paper background, and (b) could not deliver tokens to the canvas at all,
  because WP's clone matcher only selects stylesheets containing .wp-block/.editor-styles-wrapper and style.css
  is pure :root. Reviewer verified against wp-includes/block-editor.php and block-editor.js rather than inferring.
Task 5: fix round 1/5 — commits b78976b..850e1e4. Switched to add_theme_support('editor-styles') +
  add_editor_style(). Implementer was BLOCKED from getting rendered-admin evidence (sandbox classifier refused
  auth-cookie generation and test-admin creation on the live staging site) — controller verified both halves
  instead: no enqueue_block_editor_assets hook remains (leak structurally impossible), and
  get_block_editor_theme_styles() returns `.editor-styles-wrapper,:root{--bl-ink:#132343;...}`, proving WP
  rewrites the selector so tokens DO resolve in the canvas.
Task 5: complete (commits 5513776..850e1e4, review clean after 1 fix round)
Task 5: minor (deferred) -> ROLLED INTO TASK 16: editor.css now hard-codes a second copy of every --bl-* hex
  value because there is no way to share tokens across the editor-injection path. No automated check keeps it in
  sync with style.css, so a future token change could silently drift the editor preview. Task 16 must add a
  build/lint-time parity check (natural home: tools/check-contrast.mjs, which already parses style.css tokens).

=== CROSS-TASK FACT, discovered in Task 6 — carry into Tasks 7, 8 and 12 ===
On this site, UPCOMING SportsPress games are NOT `post_status = 'publish'`. WordPress core auto-assigns
`future` to any post whose post_date is ahead of now, and SportsPress events are created that way here.
Staging breakdown of sp_event (verified 2026-08-11):
    publish 6030  (earliest 0012-08-20, latest 2026-08-07 — ALL IN THE PAST)
    pending   67  (latest 2020-03-29)
    future    33  (2026-08-14 .. 2026-08-28 — THESE ARE THE REAL UPCOMING GAMES)
    draft      3
Any query for upcoming games MUST use `'post_status' => array( 'publish', 'future' )`. WP_Query's default
excludes `future`, so the naive query silently returns zero forever. Past/recent-event queries are correctly
`'publish'` only. This affects: Season State's upcoming/playoff signals (Task 6), the homepage "next games"
module (Task 7), SportsPress event/calendar templates (Task 8), and "my next game" (Task 12).

Task 6: implemented at 22b08cd. Review returned Needs fixes — 1 Critical, 1 Important, 3 Minor.
Task 6: CRITICAL — inc/season-state.php:147 hard-coded 'publish' on the upcoming-events query, permanently
  zeroing upcoming_events / next_event_id / days_to_next_event / has_playoff_events, so the module could never
  return preseason, in_season or playoffs. Invisible on staging because registration_open short-circuits first
  and happens to be the right answer today. The implementer's own "no future events yet" concern was the symptom.
Task 6: IMPORTANT — missing return-type hints the brief's Interfaces section specified
  (blueline_season_state(): string, blueline_season_state_data(): array).
Task 6: minor (deferred): posts_per_page => -1 on both event queries against a 6000+ row sp_event table;
  bounded by date_query today, worth watching as seasons accumulate.
Task 6: minor (accepted, no change): function_exists('add_action') guard around hook registration exists only so
  the test can require the module against a partial bootstrap. Reviewer rates it defensible and plan-conformant,
  since the brief names only inc/season-state.php for this module. A separate hooks file would be cleaner but
  would deviate from the specified file list.
Task 6: fix round 1/5 IN PROGRESS — required fix plus a live proof on staging that, with has_purchasable_product
  forced false, the 33 future events now yield a real upcoming count, a non-null next_event_id and a plausible
  days_to_next_event, and that the decision function returns preseason/in_season rather than offseason.
Task 6: fix round 1/5 — commits 22b08cd..0e684d0. Re-review: ALL findings ADDRESSED, no new breakage.
  Live proof with has_purchasable_product forced false: upcoming_events 33 (matches controller's independent
  count exactly), next_event_id 116466, days_to_next_event 4, recent_events 44, decision -> in_season.
  Unforced state still registration_open. Re-reviewer traced the decision logic by hand and confirmed the
  numbers are internally consistent. New BLUELINE_PUBLISHED_STATUS constant correctly applied only at the two
  publish-only sites; the upcoming query uses array(CONST,'future') so the Critical cannot silently return.
Task 6: complete (commits 850e1e4..0e684d0, review clean after 1 fix round)

Task 7: implemented at 71fb64e. Review returned Needs fixes — 1 Critical, 1 Important, 2 Minor.
  Substance verified good: all 5 hero variants + all 5 module orders reproduce the brief's binding tables
  exactly; BOTH event queries correctly include 'future' (the Task 6 trap did not recur); price read live via
  wc_get_product(); escaping context-correct throughout; sponsors empty-state fix judged a sound root-cause fix.
Task 7: CRITICAL — reusing Task 4's Register-CTA de-dup for the season-aware CTA deletes the site's real
  "Schedule" nav link in 4 of 5 states. inc/template-tags.php:1252 passes $cta['url'] (= /schedule outside
  registration_open) into Blueline_Nav_Walker, whose dedup (template-tags.php:149-151) hides any depth-0
  childless item matching that path. All three live menus (119, 120, 612) contain a permanent depth-0 Schedule
  item. Silent, no warning. Lesson: the dedup must stay Register-specific, not "whatever the CTA is".
Task 7: IMPORTANT — hero state class can contradict hero copy. homepage-modules.php:390 prints the CALLER's
  $state, but blueline_homepage_hero_content() reassigns its own LOCAL $state to preseason/offseason at line 338
  when the registration_open product fails live re-verification. By-value, so it cannot propagate. Never
  exercised because every forced-state test had a valid product.
Task 7: minor: in_season headline highlights the whole "N games" phrase; the binding table highlights only {n}.
Task 7: minor: wp_kses() applied twice to the headline (format string, then built HTML).
Task 7: accepted deviations (no change): CTA logic lives in inc/template-tags.php because header.php has no CTA
  markup; the brief's literal Step 4 curl check cannot work (a filter set in wp eval cannot cross the process
  boundary) and the in-process substitute is arguably stronger; {n} read as a shared "games this week" count
  since no season-start date exists.
Task 7: fix round 1/5 IN PROGRESS.
Task 7: fix round 1/5 — commits 71fb64e..f3b12a4. Re-review: ALL findings ADDRESSED, no new breakage.
  Critical fixed properly at the mechanism level: blueline_header_cta() now returns an `is_register` boolean and
  only a genuine Register CTA's URL is passed to the walker; the walker's property was renamed
  duplicate_url -> register_cta_url with docblocks forbidding repurposing. Important fixed by resolving the
  effective state once and returning it, so class, copy AND module order cannot diverge; the fallback branch was
  genuinely exercised via a woocommerce_is_purchasable filter on the real product (116523), which is the actual
  gate the offer function checks.
Task 7: complete (commits 0e684d0..f3b12a4, review clean after 1 fix round)
Task 7: minor (deferred) -> ROLLED INTO TASK 16: the fix round's "targeted regression tests" were ad-hoc
  wp eval-file scripts run on staging then deleted. composer test is unchanged at 7 tests, and nothing in
  themes/blueline/tests/ references Blueline_Nav_Walker, is_register, or hero effective-state resolution.
  Both fixed bugs (nav de-dup scoping; hero state/copy agreement) therefore have NO committed automated
  coverage and a future refactor could reintroduce either silently. Task 16 should add unit coverage for the
  de-dup decision and the effective-state resolution — both are testable with the existing stub bootstrap.

Task 8: implemented at e293f6a. Review verdict Approved (1 Important, 4 Minor) — controller still ran a fix
  round, because the rubric treats Important as "cannot be trusted until fixed" and the invariant at stake is
  site-wide. Reviewer credited: all wp_get_post_terms()/get_terms() calls guarded with is_wp_error(); every SP
  touchpoint function_exists/class_exists guarded; escaping context-correct with no esc_attr()-on-URL anywhere;
  player position read from the sp_position taxonomy; sp_nationality attribute-escaped; missing-data states
  handled (no crest -> leaf mark, empty roster state, player with no team/number/position); every phpcs
  suppression names a specific sniff; venue pad distinction survives into the UI with a sibling-pad cross-link.
Task 8: note — implementer found two real bugs only via live browser testing, neither catchable by lint or curl:
  (1) SportsPress's the_title filter injects a number/role badge into sp_player/sp_staff titles;
  (2) event-venue.php is the ONE SP template that skips the .sp-table-wrapper scroll container, and its embedded
      Leaflet map overflowed the page body at mobile widths.
Task 8: note — the venue-archive event query correctly handles the future/publish split via a pre_get_posts
  scoped to is_tax('sp_venue') && is_main_query(). The Task 6 trap did not recur.
Task 8: IMPORTANT — the overflow fix is coupled to two exact plugin-emitted classes with no generic backstop.
  A SportsPress update changing that markup silently reopens a hard site-wide invariant, and nothing catches it.
Task 8: minor — blueline_sp_title() bypasses ALL the_title filtering (wptexturize, convert_chars, other plugins)
  via get_post_field(...,'raw'), and is applied to every entity title, not just the sp_player/sp_staff badge bug
  it was written for. SP entity titles site-wide lose typographic filtering ordinary posts keep.
Task 8: minor — .sp-player-list (the class the brief names for roster grids) never gets grid treatment; the grid
  was built under a theme-invented .sp-team-list class instead.
Task 8: minor — score-to-team pairing in blueline_sp_event_hero() is positional (array index), not keyed by team
  ID. Note SportsPress stores home and away teams as SEPARATE sp_team meta rows.
Task 8: PARKED with ruling — using get_permalink() for actual clickable entity links is correct. The site-wide
  constraint is about get_permalink() as a COMPARISON KEY (Page Links To may return a redirect target); for a
  real href the redirect target is the intended destination. Matches Task 5/7 precedent.
Task 8: fix round 1/5 IN PROGRESS — Important backstop + 3 Minors.
Task 8: fix round 1/5 — commits e293f6a..2f4b837. All 4 original findings CLOSED (backstop now uses a
  class-name-agnostic DOMDocument ancestor walk plus html{overflow-x:clip}; title bypass gated to
  sp_player/sp_staff at a single choke point; .sp-player-list given a <700px card transform; scores keyed by
  team ID via a new blueline_sp_team_result()). get_permalink() ruling correctly left alone.
Task 8: fix round 1 INTRODUCED an Important regression — the early-return guard was widened from
  'sp-table-wrapper' to '<table', and the the_content filter has no is_singular(sp_post_types()) guard, so the
  DOMDocument pass now runs on EVERY post site-wide. Ordinary block-editor tables (which already have a working
  .wp-block-table overflow wrapper) get bl-table-self-scroll added to the <table> itself, forcing display:block —
  a redundant nested scroll container that risks stripping implicit table semantics for assistive tech.
  Never smoke-tested: both rounds only covered SportsPress/core pages, never a blog post with a table.
Task 8: STICKY HEADER — CONTROLLER RULING: drop it, do not implement. The implementer found (and the reviewer
  confirmed) that .sp-league-table's position:sticky never worked, for two real reasons: border-collapse:collapse
  breaks sticky on table-part boxes, and an ancestor with non-visible overflow-x computes overflow-y to auto,
  making the mandated scroll wrapper the sticky scrollport — which has no spare height. This is a genuine
  conflict between two brief requirements. A CSS-only fix exists (bound the wrapper's max-height so it becomes
  its own vertical scroll region) but trades an inline full-height table for a boxed one with an internal
  scrollbar. RULING: five divisions of ~8 teams means standings tables are short; sticky buys almost nothing and
  the boxed-table tradeoff is a real cost on the site's most-visited page. Removing the dead CSS was correct.
  Round 2 must record the decision in the design spec, not just a code comment.
Task 8: minor — base.css:26-29 claims sticky headers "still work correctly", contradicting sportspress.css:185-206
  in the same commit which says sticky was removed because it never worked.
Task 8: minor — the claimed "11/11 unit test" for the new DOMDocument logic was a throwaway script; composer test
  is still 7 tests. The most intricate new logic in this task has zero durable coverage, and it is precisely the
  logic that just produced an Important regression. Round 2 must commit those tests.
Task 8: out-of-scope, left alone: sportspress/team-lists.php:106 duplicates the raw-title bypass rather than
  calling the narrowed blueline_sp_title(). Correct in context (always sp_player), just DRY duplication.
Task 8: fix round 2/5 IN PROGRESS.
Task 8: fix round 2/5 — commits 2f4b837..c92d9fa. Re-review: ALL findings ADDRESSED, no new breakage.
  DOM pass re-scoped by SportsPress's own `sp-` class convention (per-table, ancestor-aware) rather than a
  page-type guard. The implementer improved on the controller's suggestion: is_singular(sp_post_types()) would
  have BROKEN /standings, which embeds SP shortcodes in an ordinary Page. Committed tests now 19 (from 7),
  including four that directly target the regression (ordinary block-editor table must pass through byte-for-byte
  untouched, incl. style variants, mixed SP+ordinary content, and an sp- class elsewhere not tainting a table).
  Sticky-header ruling recorded in the design spec's Risks table with both root causes and the cost to add.
Task 8: complete (commits f3b12a4..c92d9fa, review clean after 2 fix rounds)
Task 8: minor (deferred): class-convention false positive — any future NON-SportsPress markup using an
  sp--prefixed class as an ancestor of an ordinary table would be misclassified and get display:block. Inherent
  to the chosen (and correct) scoping strategy, not a defect. Not exercised today.

=== WORKTREE LAYOUT FACT — carry into Tasks 9, 14, 15, 16 ===
This worktree branched from an EMPTY root commit, so it contains ONLY docs/, scripts/ and themes/.
The pre-existing repo files are untracked in the MAIN checkout and are NOT present here:
  /home/cody/git/rookiehockey.ca/staging/tests/   <- the checkout regression suite Task 9 and Task 16 gate on
  /home/cody/git/rookiehockey.ca/staging/README.md
  /home/cody/git/rookiehockey.ca/woocommerce/     <- repo copies of two WC overrides
  /home/cody/git/rookiehockey.ca/mu-plugins/, snippets/, CHANGES-2026-08.md
Any task that runs `cd staging/tests && ...` must use the absolute main-checkout path instead.

Task 9: complete (commits c92d9fa..fd0ed3d, review Approved first pass — no fix round needed)
Task 9: all 25 WooCommerce overrides ported. md5 parity proven byte-identical BEFORE any restyle; parent-theme
  grep clean; all 14 email templates are single-hunk pure additions (verified unmodified, not "modernised" —
  they keep inline styles/table layout for Outlook); myaccount/dashboard.php ported faithfully without
  pre-empting Task 12; checkout fields styled only by generic selectors, never by CFE-Pro field id.
Task 9: the one judgment call — replacing WooCommerce's default wrapper callbacks with
  blueline_wc_wrapper_start()/_end() required deleting archive-product.php's own inline #primary/#main div.
  Reviewer ruled this a NECESSARY consequence of the interface the brief itself mandates, not redesign
  overreach, and confirmed it fixes a real duplicate-ID accessibility defect live on production today.
  Money path proven architecturally isolated: page.php hardcodes its own <main>/<container> and never fires
  woocommerce_before_main_content, so Cart/Checkout/My-Account (all shortcode-rendered inside page.php) never
  touch the removed hook.
Task 9: ⚠️ resolved by controller — the reviewer could not witness the regression tests and asked for a second
  witness given the open registration window. Controller re-ran them independently:
    repro-duplicate-registration.sh -> "items_count after login merge: 1 — bug NOT reproduced" (correct)
    test-one-registration-guard.sh  -> "final items_count: 1 — GUARD WORKS"
  Money path verified twice.
Task 9: OBSERVATION FOR TASK 16 — test-one-registration-guard.sh may be passing VACUOUSLY. Its own step 3
  ("inject a SECOND registration ... guard has not run yet") reports items_count: 1, when a successful injection
  should show 2. If the injection silently no-ops, the guard is never actually exercised and the test proves
  nothing. This is the TEST's fidelity, not the theme's correctness, and it predates this work — but Task 16
  gates on this suite, so confirm the injection actually lands before treating a pass as meaningful.
Task 9: minor (deferred): brief Step 4 said "Buttons use the skew treatment"; commerce buttons were kept upright
  citing the .wp-block-button__link precedent. Documented, cosmetic, plan-mandated deviation.
Task 9: minor (deferred): bl-main--woocommerce modifier class has no CSS rule — dead weight until R2.

CONTROLLER ENV FIX (during Task 10): staging was MISSING mu-plugins/rh-royal-mcp-register-fix.php, so
  /register returned 405 on staging while production returns 200. My Task 10 brief asserted staging had it —
  that was WRONG and the implementer correctly pushed back. This is the documented mu-plugin sync gap in
  staging/README.md ("mu-plugins are NOT covered by the sync above"). Copied the file from the repo mirror,
  chowned 33:33, did NOT touch the rest of the directory. /register now 200 on staging; staging guard verified
  still loaded (arl_staging_guard_log exists). Staging is now a truer clone for Tasks 11-16.

Task 10: implemented at 60cc6a0. Review verdict Approved (1 Important, 2 Minor).
Task 10: CONTROLLER VERIFICATION of the highest-risk item in the whole plan — authenticated endpoint dispatch.
  Generated a real logged-in cookie for user 2434 via wp_generate_auth_cookie on staging (YITH deactivated):
    /account/registrations    200  title "Orders"          <- WC's own orders handler fires on the ARL slug
    /account/store-credit     200  title "Store credit"    <- WC store-credit handler fires
    /account/refund-requests  200  renders ywcars_* assets + "Refund Requests" (YITH refund plugin content)
    /account/payment-methods  200  title "Payment methods"
    /account/edit-address     200  title "Addresses"
    /account/edit-account     200  title "Account details"
    /account/my-team          200  (Task 12 fills content)
    /account/my-schedule      200  (Task 12 fills content)
    /account/orders  -> 301 -> /account/registrations
    /account/credit  -> 301 -> /account/store-credit
  No 404 anywhere. The query-var remap genuinely dispatches to WooCommerce rather than merely returning a page.
Task 10: note — implementer found and fixed a real redirect-loop bug (WC's internal query-var remap colliding
  with legacy-slug detection). Reviewer judged the fix architecturally sound: the legacy slug gets its OWN
  dedicated query var, decoupling loop detection from woocommerce_get_query_vars internals rather than using a
  one-shot flag. Unit tests could not have caught this; only before/after staging testing surfaced it.
Task 10: IMPORTANT — the remap has no defensive guard. If a future plugin filters woocommerce_get_query_vars at
  higher priority and reverts the mapping, WC registers its own rewrite endpoint under the literal slug `orders`,
  the same name the theme registers under blueline_legacy_orders. Two add_rewrite_endpoint() calls for one name
  with different query vars is order-dependent and undetected -> silent 404 on a live account URL.
Task 10: minor — the 301 drops a trailing sub-value, so /account/orders/2/ (WC pagination) lands on a bare
  /account/registrations/. The brief's Step 5 literally specifies the no-value form; controller AUTHORISED
  deviating to preserve it.
Task 10: fix round 1/5 IN PROGRESS.
Task 10: fix round 1/5 — commits 60cc6a0..74d7198. Re-review: ALL findings ADDRESSED, no new breakage.
  Guard reads WC()->query->get_query_vars() (the FULLY FILTERED result) and, on collision, logs under WP_DEBUG
  and SKIPS registering the legacy endpoint — degrading to stock WooCommerce rather than 404ing. Re-reviewer
  worked the value-membership logic case by case and confirmed it correct in both directions:
    orders  intact remap  -> orders=>registrations, 'orders' not a value -> free -> register
    orders  reverted      -> orders=>orders,        'orders' IS a value  -> skip (correct)
    credit  this site     -> store-credit=>store-credit, 'credit' never a value -> always free (correct)
  Pagination preserved via wc_get_endpoint_url($slug, $value, myaccount_permalink).
Task 10: note — the implementer found a SECOND real bug while building the guard: its first version checked
  query-var KEY equality and 404'd /account/credit, because this site's WooCommerce Store Credit plugin natively
  owns `store-credit` as its key and `credit` never legitimately appears. Caught by its own staging testing.
Task 10: controller re-verified the full authenticated sweep AFTER the fix — all 8 slugs 200, WC's Orders and
  Store credit handlers still firing, both legacy 301s intact.
Task 10: complete (commits fd0ed3d..74d7198, review clean after 1 fix round). 33 unit tests.
Task 10: minor (deferred): the guard's correctness depends on WooCommerce's init_query_vars() (init priority 0)
  running before blueline's init priority 10, and on third-party woocommerce_get_query_vars filters being
  registered before that single apply_filters() fires. Inherent WP/WC hook-timing property, not covered by any
  automated test.
Task 10: minor (deferred): impure wrapper blueline_account_legacy_slug_is_safe() and
  blueline_register_account_rewrite_endpoints() have no unit coverage (no WC_Query stub in tests/bootstrap.php);
  the pure extracted helpers do. Honestly disclosed.

CONTROLLER CORRECTION (during Task 11): the "244 current-season players linked" figure I briefed was
  PRODUCTION's, not staging's. Staging is an older clone. Verified both directly:
    production 2026-08-11: 2037 current-season players, 247 linked; 269 linked across all seasons
    staging    2026-08-11: 2037 current-season players, 237 linked; 256 linked across all seasons
  NO DATA WAS LOST — the implementer was right to flag the discrepancy. Note production's link count is GROWING
  during the open registration window (it read 244/265 earlier the same day), so Task 14's backfill must measure
  its own before/after on whichever environment it runs against, not against a hard-coded baseline.

Task 11: complete (commits 74d7198..9c38533, review Approved first pass — no fix round needed). 39 unit tests.
Task 11: security review passed with no Critical or Important findings. Verified by the reviewer:
  - the write path self-guards (forbidden check is the FIRST statement), so even a future second call site is safe
  - check_admin_referer('blueline_claim_player') sits before any read/write, and the handler is the only route in
  - candidate matching correctly distinguishes "linked to ME" (kept as a candidate) from "linked to SOMEONE ELSE"
    (excluded) via an OR sub-clause on the meta query
  - all three WP_Error rejections fire; the forbidden path was demonstrated with a genuine plain customer
  - 2,037 current-season players handled in TWO lean SQL round-trips (meta_query + one batched prepared IN()
    title lookup), never hydrating post objects on a page load
Task 11: controller ENDORSED the implementer's deviation — it reordered the checks to put `forbidden` FIRST
  rather than the brief's listing order, so an unauthorised caller learns nothing about link state. Correct
  security instinct; write behaviour identical.
Task 11: note — the implementer's first forbidden-path test accidentally used an `arl_committee` user that
  actually held edit_users, caught it, and redid it with a plain customer. Reviewer confirmed the FINAL evidence
  genuinely demonstrates rejection (actor 2070 vs subject 1037, edit_users false).
Task 11: minor (deferred): pure matchers are functionally but not byte verbatim — phpcs:ignore comments and WPCS
  alignment whitespace added. No logic differs, no WordPress calls introduced. Acceptable given WPCS compliance
  is also mandated.
Task 11: minor (deferred): SP-inactive guard uses post_type_exists('sp_player') rather than the literally
  specified class_exists()/function_exists() form. Arguably tighter; functionally equivalent.

=== MAJOR CROSS-CUTTING FINDING (discovered during Task 12) — simple-css plugin overrides ===
The `simple-css` plugin (ACTIVE on both production and staging) stores ~6,918 chars of CSS in the `simple_css`
option. It is THEME-INDEPENDENT, so it survives the cutover and applies to blueline. It was written against the
Rookie theme and the OLD brand palette (#0577da / #032867). Confirmed live on staging: the product archive emits
the block and contains #0577da four times.

Rules that ACTIVELY FIGHT blueline (all with !important, so our tokens lose):
  .woocommerce button.button.alt, .woocommerce #respond input#submit, .woocommerce a.button,
  .woocommerce button.button, .woocommerce input.button { color:#FFF !important; background:#0577da !important }
      -> EVERY WooCommerce button (add-to-cart, place-order, account buttons) renders in the OLD brand blue.
  .woocommerce form.login input[type=submit], form.login .button { background:#032867 !important }
  .wizard > .actions a..., .login .form-row .button { background:#0577da !important }
  .woocommerce-message { display:none !important }
      -> hides ALL WooCommerce success notices site-wide. This is why Task 12's claim-success notice was
         invisible until the implementer routed around it with a .bl-account-notice class.
  input[type="tel"] { border/padding/font-size... }  -> fights blueline form styling
Rules that are now DEAD (Rookie-only markup or long-gone content):
  .site-hgroup, .site-title, .site-credit, .header-area-has-search, .page-id-1723 .entry-header h1,
  #product-13471 (S2019 product), #tab-description, .upme-login-register-link (UPME not installed),
  #wizard > .content, and ~40 .page-item-NNN mobile-nav hiding rules (those classes come from wp_list_pages;
  blueline uses wp_nav_menu, which emits menu-item-NNN).
Rules worth KEEPING but currently in the old palette:
  .arenah3border (used in Arenas page content), .su-tabs.my-custom-tabs (Shortcodes Ultimate, still active),
  .woocommerce span.onsale{display:none} (still wanted — registrations should not show sale stickers),
  .page-id-6507 clik2pay logo sizing on checkout.
IMPACT: no review caught this because every review inspected OUR css, not a plugin injecting !important on top.
  Behaviour is unaffected; appearance and user feedback are.
ACTION -> ADDED TO TASK 16 SCOPE: audit simple_css, produce a pruned/retheme version with each removal justified,
  apply and verify on STAGING, and hand the production change to the user as an explicit cutover step. Do NOT
  change production CSS unilaterally.
NOTE: Tasks 12-15 visual verification is happening WITH these overrides still in place.

Task 12: implemented at 00263dc.
Task 12: note — implementer found the .woocommerce-message suppression during live testing and correctly routed
  around it with a dedicated .bl-account-notice class rather than fighting it.
Task 12: note — staging sp_user baseline is INTACT: 237 current-season linked / 256 all-season, unchanged.
  The implementer's "237 -> 256 drift" was conflating the two metrics, not real drift. Verified by controller.
Task 12: fix round 1/5 — commits 00263dc..155c654. Re-review: ALL findings ADDRESSED, no new breakage.
  Record now sourced from SportsPress's OWN computed SP_League_Table::data(), keyed off the team's
  current-season sp_table, verified live as real data (5-6-3 · 13 PTS) with an honest "Record not available yet."
  fallback — never a fabricated 0-0-0. Division resolved through that same season-scoped table's single sp_league
  term; the false blueline_sp_team_hero() precedent claim removed from BOTH docblock and report, and the new rule
  documented with its failure mode. Claim notice gets tabindex="-1" + focus-on-load via new account.js. Nav rail
  group labels are now <h2>. Registration lookup paginates (20/batch, 10-page cap).
Task 12: complete (commits 9c38533..155c654, review clean after 1 fix round). 43 unit tests.
Task 12: minor (deferred): blueline_team_current_table_id() runs twice per My Team render (once for division via
  blueline_get_player_team(), once for the record), each doing its own get_posts()+tax_query. Duplicate query.
Task 12: minor (deferred): blueline_format_team_record() guards pts against '' but not w/l/tie; could in theory
  render "Record: --" if SP ever returned present-but-empty columns. Speculative, not observed.
Task 12: minor (deferred): registration lookup's 200-order cap means a customer whose matching registration is
  older than their 200 most recent orders reads as unregistered. Documented, acceptable at this site's volumes.
Task 12: minor (deferred): blueline_team_current_table_id() has no documented tie-break if a team is rostered in
  two tables tagged with the same season (e.g. mid-season division reassignment).

=== SPEC OPEN ITEM #1 — RESOLVED (Task 13). This is a PRE-CUTOVER BLOCKER. ===
Question was: does YITH's reCAPTCHA or simple-cloudflare-turnstile actually guard the account form?
Answer, verified by the implementer via $wp_filter introspection and confirmed by the controller against
production options:
  * YITH's reCAPTCHA was genuinely configured and guarded ONLY `woocommerce_register_form`. It never guarded
    the login form.
  * simple-cloudflare-turnstile IS active on production and its keys ARE set (cfturnstile_key = ON,
    cfturnstile_secret = ON, cfturnstile_tested = ON). It currently guards Gravity Forms (cfturnstile_gravity ON).
  * BUT every WooCommerce/account form toggle is OFF:
        cfturnstile_woo_register (off)   cfturnstile_woo_login (off)
        cfturnstile_woo_checkout (off)   cfturnstile_woo_reset (off)
        cfturnstile_login (off)          cfturnstile_register (off)
CONSEQUENCE: YITH's reCAPTCHA is TODAY THE ONLY BOT PROTECTION ON THE WOOCOMMERCE REGISTRATION FORM.
  Deactivating YITH on production without first enabling Turnstile for that form leaves account registration
  unprotected. The design spec's hopeful "if Turnstile covers it, no replacement is needed" assumption is WRONG.
GOOD NEWS: Turnstile is already active with valid credentials, so this is a settings toggle, not an integration.
REQUIRED CUTOVER STEP (before deactivating YITH on production): enable `cfturnstile_woo_register` (and consider
  `cfturnstile_woo_login`), then verify the widget actually renders on /account.
NOTE: the implementer's report says Turnstile has "no keys" — that is INCORRECT; keys are set, only the form
  toggles are off. Controller-verified.

CONTROLLER CORRECTION (Task 13): my brief stated `yith_wcmap_users_avatar_ids` is a user_id => attachment_id map
  of 11 users. It is NOT — it is YITH's internal upload bookkeeping list. The real per-user link is user meta
  `yith-wcmap-avatar`, and production has exactly 10 real rows (verified). The option's 11th attachment is an
  orphan with no owning user. The implementer built against the verified real source rather than my literal
  premise, which was the right call. This is the second time an implementer has correctly overruled my brief.

ALSO FOUND: `show_avatars` is EMPTY (off) on production, and an active Code Snippets rule removes Gravatars.
  So avatars do not render for anyone today regardless of this migration. The migration still matters — it
  preserves the data so the 10 users keep their avatars if avatars are ever re-enabled — but nobody should
  expect a visible change at cutover.

Task 13: implemented at 529708b.
Task 13: fix round 1/5 — commits 529708b..096a417. Re-review: ALL findings ADDRESSED, no new breakage.
  The durable deliverable landed: spec §6.6/§9 now carry the corrected, verified Turnstile state and the
  unambiguous pre-cutover action; open item #1 genuinely REMOVED from §10 (replaced by a pointer, not
  duplicated). show_avatars-off + the "Remove Gravatars" Code Snippets rule (ID 23) recorded so nobody
  "verifies" the migration by looking for avatars and concludes it failed. Non-numeric avatar values now print
  as SKIPPED with the raw value; @var corrected to stdClass[].
Task 13: complete (commits 155c654..096a417, review clean after 1 fix round)
Task 13: minor (deferred): script's "Rows found" label counts skipped rows too (pre-existing convention).
Task 13: minor (deferred): spec's Turnstile write-up lists the four cfturnstile_woo_* toggles but omits the
  standalone cfturnstile_login/cfturnstile_register (both also verified off). Task 13's scope was the
  WooCommerce forms, so the required action is still unambiguous.
Task 13: minor (deferred): whichever future task adds the first get_avatar() call site must supply a meaningful
  alt — the filter deliberately does not set one.

=== MAJOR SPEC CORRECTION (Task 14) — the "only 12% of players are linked" premise is WRONG ===
The design spec §6.3 states that only 12% of current-season players have sp_user, so ~88% of logged-in players
would see an empty dashboard, and that the backfill is "what turns 12% into most of the roster."
That is based on a WRONG DENOMINATOR. Controller verified:

  `sp_current_team` is a STICKY "last team this player was ever on" field, not a current-season roster.
  It is set on 2,037 of 2,134 players — 95% of everyone who has ever played. Implausible as a live roster.

  The real season membership is the `sp_season` taxonomy. Verified counts:
      W2026-27 (term 674, CURRENT):   90 players,  76 linked  ->  84% COVERAGE
      W2025-26 (term 654, last full): 524 players, 241 linked ->  46%
      sp_current_team (historical):  2037 players, 241 linked ->  12%   <- the misleading figure
  (W2026-27 is only 90 so far because registration opened days ago and is still filling.)

WHY: `sportspress-player-registration` auto-links players to users AT CHECKOUT. Anyone registering through the
  current flow gets linked automatically. The unlinked long tail is historical players who never had accounts or
  who registered before that plugin existed.

CONSEQUENCES:
  1. The league-first dashboard will work for ~84% of players actually registered this season — the OPPOSITE of
     the spec's "88% will see the claim card" premise. That premise must be corrected in §6.3.
  2. Task 14's tiny yield (4 new links, 237 -> 241) is CORRECT AND EXPECTED, not a failure. The implementer
     traced it empirically: of 74 non-guest customers who bought a current-season product, 69 were ALREADY
     linked before the script ran. There was very little left to backfill.
  3. The claim flow (Tasks 11-12) remains genuinely valuable — for the ~14% gap, for returning players whose
     historical records are unlinked, and as a safety net — but it is a supplement, not the primary mechanism.
  4. Nothing built needs to change. Only the spec's framing and the release notes need correcting.
ACTION -> ADDED TO TASK 16 SCOPE: correct §6.3 of the design spec with these verified numbers and the
  sp_current_team-vs-sp_season distinction, so the next person does not repeat the mistake.

Task 14: implemented at 18ad193. Report/apply modes, AUTO-only writes, idempotent, reuses Task 11's matcher.
Task 14: --apply gate proven under wp eval-file (leading `--apply` is rejected pre-load, exit 1, zero writes;
  a positional `apply` argument is what actually writes — same convention Task 13 established).
Task 14: classification AUTO=4, REVIEW=0, NONE=1. Coverage 237 -> 241 current-season (256 -> 260 all-season).
Task 14: the brief demanded ten hand-checked AUTO rows; only 4 existed. The implementer hand-checked ALL FOUR
  against each order's billing email vs the WP account email plus roster placement, added 4 negative-control
  near-misses, and explicitly REFUSED to lower the threshold or widen scope to manufacture more rows.
  That is the right call and exactly the conservatism this script needed.
Task 14: fix round 1/5 — commits 18ad193..09f5bb3. All 5 original findings CLOSED. Pool narrowed 2,037 -> 281
  via an sp_season tax_query with a sparsity fallback; write path now calls blueline_link_player_to_user()
  (no duplicated invariants) behind a real --user=<id> + current_user_can('edit_users') gate whose refusal path
  was proven to write nothing; order statuses restricted; TSV column relabelled scored_name.
  Implementer caught TWO bugs beyond the ask: (a) a term's ->count mixes non-player post types, so it added
  blueline_sp_season_player_count(); (b) a naive term-id fallback crossed Winter->Summer and landed on S2026,
  which would have silently dropped a hand-verified-correct Winter-only match (Robert Baker).
Task 14: fix round 1 INTRODUCED two Important gaps, both failing SAFE (empty candidate list, never
  misidentification), but both real:
  (1) The current/fallback resolution is EXCLUSIVE-OR — it returns one term id, so when the current season is
      sparse the pool becomes ONLY last season. A genuine first-time registrant tagged only with W2026-27 and
      carrying no season history is therefore invisible to BOTH the claim card and the backfill, during exactly
      the early-season window the claim flow exists for. Only returning players (multi-season tags) were tested.
  (2) The session-letter rule is `strtolower(substr($slug,0,1))` — a bare first-character heuristic inferred
      from today's w2026-27 / s2026 convention, not validated. A future year-first slug (2026-winter /
      2026-summer, both starting "2") would silently re-merge sessions and reintroduce the very bug it fixes.
      blueline_claim_pool_season_term_id() — the function holding this logic — has ZERO unit tests; the 5 new
      tests cover only the pure sparsity-ratio helper.
Task 14: fix round 2/5 IN PROGRESS — make the pool ADDITIVE (current OR fallback), harden + test the session
  rule with a loud failure instead of a silent guess, and evidence the restore half of the reversible test.

=== TASK 16 SCOPE HAS GROWN — collected requirements ===
Beyond the plan's original Step 1-5, Task 16 must also:
  a) Audit the `simple-css` plugin's ~6,918 chars of CSS: prune dead Rookie-era rules, remove the !important
     WooCommerce overrides that beat blueline's tokens (#0577da buttons, .woocommerce-message{display:none}),
     retheme the still-wanted content rules, apply and verify on STAGING, and hand production the change as an
     explicit cutover step. Do NOT change production CSS unilaterally.
  b) Correct design spec §6.3: the "only 12% of current-season players are linked / 88% see the claim card"
     premise is wrong. Record the sp_current_team (sticky, 2037) vs sp_season (real: W2026-27 = 90 players,
     76 linked = 84%) distinction so nobody repeats it.
  c) Add a build/lint-time parity check between style.css tokens and editor.css's duplicated copies (Task 5).
  d) Add committed unit coverage for the nav de-dup decision and the hero effective-state resolution (Task 7).
  e) Confirm staging/tests/test-one-registration-guard.sh is not passing VACUOUSLY — its own step 3 reports
     items_count 1 where a successful injection should show 2 (Task 9).
  f) Run a genuine full WPCS pass; several tasks deferred lint debt in tests/ scaffolding (Task 1, 3).
  g) Decide whether staging's WP_DEBUG=true stays on (controller enabled it; backup at wp-config.php.bak-blueline).
Task 14: fix round 2/5 — commits 09f5bb3..44043bd. Re-review: ALL findings ADDRESSED, no new breakage.
  Pool is now ADDITIVE: the anchor term is always included and a same-session fallback is ADDED (never
  substituted) when sparse, bounded to at most two terms (the loop breaks after the first same-session term with
  members). The sp_current_team clause remains ANDed, which is why the pool moved only 281 -> 282.
  Session detection replaced with BLUELINE_SEASON_SLUG_SESSION_PATTERN '/^([ws])\d/i': a year-first slug like
  2026-winter is correctly REJECTED rather than read as session "2". Non-conforming anchor degrades to an empty
  array (no guess); non-conforming non-anchor terms are excluded and WP_DEBUG-logged. The pure decision function
  stays side-effect-free and is now directly unit-tested.
  Live proof used a REAL player: 116165 "Jay Tuck", tagged only with the current season and no history —
  unreachable under the old single-term pool, surfaced at score 1.0 under the additive one. Scratch account
  mutation reverted with bracketing queries shown.
Task 14: complete (commits 096a417..44043bd, review clean after 2 fix rounds). 57 unit tests.
Task 14: minor (deferred): BLUELINE_CLAIM_POOL_SPARSE_RATIO = 0.5 is an unlitigated judgment call.
Task 14: minor (deferred): the non-conforming-slug logger has no live-fire evidence (all real slugs conform);
  proven only at unit level against fabricated slugs.

Task 15: implemented at 67affdf. Review verdict Approved (2 Important, 4 Minor).
  30 archive pages audited, ZERO fixes needed — every page 200, no rookie- markup, no leaked PHP errors, and no
  horizontal body scroll at 390px even on a page carrying 39 SportsPress tables and 800px-wide league tables.
  Enumeration cross-referenced the full page table against ALL FOUR nav menus (menu-3-0, menu-2-0, primary,
  archive), not just the current one — which surfaced 4 orphaned "Past Stats" pages linked from no menu, one page
  (14114) living at site root instead of its nested nav path, and one documented exclusion (13440, private).
  Reviewer confirmed the audit is NOT vacuous: no -L so a 301 cannot read as 200, failure aggregation is correct
  (non-subshell loop, exit $FAIL after the full table), and the first run genuinely failed all 30 on a
  trailing-slash 301 before being fixed — proof the check can fail.
Task 15: IMPORTANT — PHP-error regex requires digits immediately after "on line ", so an html_errors-wrapped
  error (`on line <b>42</b>`) would register as NO ERROR. False-pass shape on a script that gets re-run at
  cutover.
Task 15: IMPORTANT — the swp post list fetch discards stderr and is guarded only by a zero-count check, so a
  truncated CSV or an injected WP-CLI warning could silently shrink the audited set with no FAIL row and a zero
  exit code.
Task 15: minor — the _links_to (Page Links To) check that resolved the "301 is not automatically a failure" trap
  was a one-off manual query, not committed; HUB_IDS/EXTRA_IDS are hardcoded from manual investigation;
  path-resolve-error is printed in the HTTP column rather than DETAIL.
Task 15: NOTE — 4 orphaned "Past Stats" pages exist that no menu links to. That is a content/IA question for the
  league, not a blueline defect. Worth surfacing to the user.
Task 15: fix round 1/5 IN PROGRESS — harden both detection gaps and prove the error check can fail.
Task 15: fix round 1/5 — commits 67affdf..4c4f3f2. Re-review: ALL findings ADDRESSED, no new breakage.
  PHP-error detection now strips HTML tags before matching, so `on line <b>42</b>` collapses and matches, while
  the `.*on line [0-9]+` suffix requirement is PRESERVED — so it does not degrade into a bare-word match that
  would false-FAIL on archive prose containing "Warning" or "Cookie Notice:". A --self-test harness covers the
  plain form, the html_errors form and two negative fixtures, and the report separately shows the OLD pattern
  returning exit 1 on the html_errors string — the required proof it could fail.
  Fetch integrity: ssh exit status now checked (no longer lost through process substitution), MIN_PAGE_ROWS=100
  floor vs ~110 real, per-row numeric-id validation, MIN_TARGET_PAGES=20 floor vs 30 real. All four guards exit 2,
  distinct from a per-page FAIL (exit 1). Each demonstrated firing against modified copies.
  Page Links To folded in: a 301/302/307/308 is only a PASS when the page id actually carries _links_to meta.
Task 15: complete (commits 44043bd..4c4f3f2, review clean after 1 fix round). 30/30 pages, 0 content fixes.
Task 15: minor (deferred): fetch_links_to_ids() got none of the hardening built for fetch_pages() — its exit
  status is discarded, so a dropped connection yields an empty map. Fails SAFE (a redirected page would hard-FAIL
  rather than false-PASS), but it is an inconsistency the fix introduced while raising the bar elsewhere.
Task 15: minor (deferred): MIN_PAGE_ROWS=100 leaves only a ~9% shrink buffer; a legitimate bulk deletion of ~10+
  pages would trip it.
Task 15: minor (deferred): composer test / lint / smoke / debug.log claims are narrative assertions with no
  pasted output, unlike the self-test and fetch-guard transcripts which show real invocations.
