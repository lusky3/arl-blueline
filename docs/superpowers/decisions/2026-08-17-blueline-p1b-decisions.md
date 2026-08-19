# Blueline P1b — decisions, open questions, and what this branch taught us

Companion to `docs/superpowers/plans/2026-08-15-blueline-p1b-panel-completion.md`.
Branch: `p1-control-panel`. Test counts move as work lands, so this document does not
quote one as "final" -- run `npm run check` for the current figure. (An earlier version
of this line called 676/2116 the final state; three test commits landed after it.)

The plan says what was built. This says **what was decided and why**, which decisions
still want a human, and the failure modes this codebase keeps producing. It exists
because the working ledger it was distilled from is gitignored and dies with its
worktree.

---

## 1. Decisions that need human eyes

Each of these was made under "proceed with your best choice"; each is defensible and
each could reasonably go the other way.

### 1.1 Three spec requirements declined

The spec's §6.3 lists presence toggles that were never built. Three are declined
outright, and declining something the spec literally asks for is the most reversible-
but-consequential class of call here.

| Item | Ruling | Why |
|---|---|---|
| **6 account cards** (only 4 exist) | Four is correct | The two untoggled are the claim card and the billing group. Each is the *only* route to something a user needs — linking a player profile, managing payment. Hiding either strands them. `inc/settings/sections.php` documents the refusal. The spec's number is wrong. |
| **Roster layout** | Out of scope | There is exactly one layout. The second was *deliberately deleted* — `sportspress/team-lists.php`'s docblock records that it replaced a 16-up card grid design review flagged as the exact banned pattern DESIGN.md warns about. Building the toggle means re-implementing a rejected design, then shipping a control that lets a volunteer switch it back on. |
| **Event-state display** | Out of scope | Cheap to wire (two guards). But the chip it would hide is the only thing distinguishing "not played yet" from "score not entered yet" — the precise P0 defect `blueline_sp_event_state()` was written to fix. Hiding it re-creates a fixed bug by configuration, and the spec never says why anyone would want that. |

**Also:** the spec mandates "the Register CTA cannot be disabled while season state is
`registration_open`" — but there *is* no Register CTA toggle to floor. The spec floors a
control it never asks anyone to build. Not built: a floor whose only permitted value is
"on" is the same as no toggle.

### 1.2 Smaller calls worth a second opinion

- **The announcement banner renders site-wide**, from `header.php`, not homepage-only as
  the plan said. A banner only on the homepage misses everyone arriving on a schedule or
  event page from a shared link. Cost: it changes the homepage visual join, where the hero
  previously butted directly against the header's blue bands.
- **`advanced_enabled` gates the delete-all-data control.** It needed a real consumer
  (the alternative was the coverage-test exemption list, documented as temporary
  scaffolding). The teardown is the most "here be dragons" control in the panel, which is
  what the spec says Advanced is for.
- **Omitted keys carry forward on import** rather than resetting to defaults. Verified on
  staging. Kept, because merge-not-replace is the safer default — but it is why the diff
  preview must show carried-forward keys, or it misleads exactly when someone is trusting it.
- **Endpoint menu entries disappear with their card.** A bookmarked URL still renders, empty.
- **`wp blueline settings repair` exits non-zero *after* writing.** Under `set -e` that
  aborts a deploy mid-way. Intended as the "the database was broken" signal — but it needs
  saying wherever it gets wired into a deploy script.
- **Delete-all-data does not touch** `blueline_avatar_id` (member-uploaded media: their
  content, and dropping the pointer would orphan the file) or `sp_user` (SportsPress owns
  that key and keeps using it).

### 1.3 Known coverage gap, deliberately not faked

`blueline_season_state_data()`'s `WP_Query` calls are not covered end-to-end. There is no
`WP_Query` stub, and writing one from memory against `date_query` semantics that cannot be
checked against core here is precisely the stub-divergence failure mode this project has hit
seven-plus times. The seam (`blueline_season_state_moment()`) is unit-tested instead,
including across a DST boundary, and the gap is stated in the test docblock.

**Pre-existing, untouched:** in the recent-events query, `after` uses `gmdate()` (UTC) while
`before` uses a site-local string. Predates this work; the `$now` threading just puts the
mismatch side by side.

---

## 2. Rulings, with the reasoning that survives

- **Section toggles mean one thing.** `blueline_section_enabled()` means "renders at all" for
  all sixteen keys and fails closed on an unknown one. `standings_extra_stats_default` sets
  the *initial* state of a disclosure both halves of which stay reachable — so it is a plain
  `bool` field, not a seventeenth section key that would make one function mean two things.
- **Build what was missing, don't delete the symptom.** Four `chrome_footer_widgets_{1..4}`
  toggles now genuinely gate the footer widget loop. The warning's copy was false because it
  was mapped to the nearest toggle that existed rather than the one that should have.
- **The break-glass expiry is mandatory** — no expiry, expired, or unknown state all fall
  through to the computed state. An override nobody remembers becomes the site's permanent state.
- **The override applies before `apply_filters( 'blueline_season_state' )`**, so a developer
  filter still outranks an admin override.
- **Enum fields validate at save, not just on read**, via a `choices` schema key rendered as a
  `<select>`. An emergency control that silently no-ops fails exactly when someone depends on it.
- **Import discards `advanced_enabled` and `aa_acknowledgements` unconditionally.** Consent is
  the one thing a file cannot assert on an admin's behalf. This had to land *with* the key: the
  unknown-key drop covered it right up until it became a real schema field.
- **Delete ≠ reset.** `reset` writes defaults and leaves the snapshot history intact, so it is
  undoable. Delete removes the snapshots too. Both the panel copy and the CLI prompt say so,
  because the recoverable option is probably the one an admin wanted.
- **Snapshots skip a no-op save.** Otherwise ten harmless re-saves evict the entire undo history.
- **A repair returns an accepted value exactly as stored**, not as `sanitize_text_field()` would
  rewrite it — otherwise the reported list of repairs is not the complete list of changes.

---

## 3. Two standing rules this branch established the hard way

### 3.1 Every guard on a write path retroactively threatens every test that reached the guarded value through that path

Three instances: a `choices` guard invalidated two tests; a `date` guard invalidated three;
and `sanitize_text_field()`'s own trim/strip had left two more half-vacuous **since the field
was created** — never anyone's "new" guard at all.

The countermeasure that worked is mechanical, not attentional: **instrument the sanitizer's
rejection path to log the calling test, and run the whole suite.** About three lines. It
converts "I looked and found none" into a known scope. A sweep by inspection got the question
wrong once already — it asked "does a test seed an invalid value?" when the question is "does
a test seed a value this guard now refuses?"

### 3.2 A notice test that does not assert on rendered markup cannot see how the notice looks

A restore-failure notice shipped rendering a raw internal key as its label and a link to
nowhere. Its test inspected `get_settings_errors()` and never rendered the page.

### 3.3 Corollary: differential mutation

The sharpest tool used on this branch. Run the same mutation at two commits: a guard added in
the newer one may have made a *pre-existing* test inert. That is how the two dead read-clamp
tests were found — deleting either clamp was caught at one commit and survived at the next.

---

## 4. The defect this codebase keeps producing

**At least fifteen distinct instances on this branch**, and around twenty-one if repeated
phrasings of one underlying claim are counted separately: comments and admin-facing copy
asserting behaviour the code does not implement. Not one was a logic bug; every one was a
true-sounding sentence.

An earlier version of this section said "ten", written at a point when five more were still
live in the tree -- including one that had been diagnosed as false in a commit message on this
branch and left in place, and one written false in the same commit that made it false. The
count was not just historical and it was not conservative; it was simply low.

Notable variants:

- A comment claiming a direct `update_option()` bypasses the sanitizer. It does not — the
  filter is registered at file scope, and the repo's own committed core-contract fixture
  records it firing first. Found four times, in four different phrasings; the fourth was
  found only by grepping for the *claim* rather than the cited lines.
- A docblock asserting **test coverage that did not exist**. The behaviour was correct; the
  claim about what proved it was false.
- A test that pinned a value read off the running implementation, so **the assertion encoded
  the bug** rather than catching it. Found when a fix turned it red.

The last instance was written by the agent that had spent the whole branch cataloguing the
class, while holding it in working memory. That is the useful finding: this is not a property
of carelessness. It is a property of writing a claim at the moment you believe it and never
revisiting whether the code kept the promise.

**The rule adopted in response:** when a comment claims a test proves something, either make
it true or delete the claim — and prefer making it true. Applied to the teardown's own scope
claim, which now scans `inc/settings/*.php` for option constants rather than comparing a list
to itself.

---

## 5. Process notes for whoever runs the next one

- **Four review findings relayed to implementers turned out to be wrong**, one of which put a
  false comment *into* the codebase. The pattern was specific: claims that were *originated*
  got verified; claims that were *relayed* did not.
  - Diffing quoted code against the tip under review catches the "quoted a stale commit" kind.
  - It does **not** catch the "plausible reasoning about a mechanism" kind. Those need the
    mechanism probed — ask for the probe, or run it.
- **Implementers self-caught their own false claims three times** before committing, twice
  unprompted. Telling them the defect class explicitly, with incidents, worked better than
  telling them to be careful.
- **`phpcs:ignore` annotations do not compose.** A trailing annotation *replaces* a
  preceding-line one, silently. Three lines had a nonce ignore above and a sanitization
  ignore trailing; only the trailing one applied.

---

## 6. Outstanding

- **The final whole-branch review has now run**, in three passes: security surfaces, whole-suite
  vacuity (87 mutations), and truthfulness plus cross-task coherence. It found no exploitable
  vulnerability and no wrong implementation. What it found was guards that were correct but
  unwatched -- a destructive handler with no tests, an escape whose only two "covering" tests
  could not fail, a notice guard blind to any class built with PHP -- and the five further
  untrue comments now counted in §4. All are fixed.
- **Cross-task coherence came back clean**: one import path shared by CLI and panel, one
  meaning for `blueline_section_enabled()` across all sixteen keys, no load-order hazard, and
  reserved-key handling consistent across save, merge, import, snapshot, restore and repair.
- **P2 remains unplanned**: colour control, Occasions, `aa_acknowledgements`, deploy drift
  (`_validated_against`), and the nested storage shape §6.1 describes (deliberately still flat —
  re-nesting is a migration with no user-visible value).
