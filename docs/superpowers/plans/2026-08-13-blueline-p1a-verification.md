# Blueline P1a — Verification Record

**Plan:** `2026-08-13-blueline-p1a-panel-foundation.md`
**Spec:** `2026-08-13-blueline-control-panel-design.md` §6
**Completed:** 2026-08-14 · 31 commits, `fb08da8..2a4a24a` on `p1-control-panel`

## Final state

| Gate | Result |
|---|---|
| PHPUnit | **373 tests / 1006 assertions** (was 180/399) |
| JS tests | 42 |
| Contrast guard | 35 `ok` + 1 informational, exit 0 |
| `composer lint` | exit 0 |
| Staging smoke | 25/25 |

**Verified in a real browser** on staging as a real administrator: panel renders, three tabs navigate,
values load, `page_id` fields are dropdowns, a bad value is rejected and reverted, the error message is
actionable, `aria-invalid`/`aria-describedby` resolve, the error summary renders and receives focus with
a working link, and a successful save displays "Settings saved."

## What shipped

`Appearance → Blueline` — Settings API, `manage_options`, no React/REST/webpack entry. Three tabs
(Content, Links, Commerce) over **20 schema fields**, every one of which provably reaches a call site.

- **Storage** — one autoloaded option (**868 bytes, 22 keys** — 0.6% of WordPress's 150KB autoload
  budget, inside the existing `alloptions` fetch, zero extra queries).
- **Write pipeline** — `sanitize_option_*` (validate) → `pre_update_option_*` (merge) → write →
  `update_option_*`/`add_option_*` (cache purge). Both filters registered at **file scope**, so WP-CLI,
  import and migration all traverse them.
- **Links** — 17 hardcoded `home_url()` call sites now resolve through page IDs, with a fallback that
  checks `post_status` (a trashed page returns a plausible URL that 404s).
- **Commerce** — the hardcoded registration term ID is now configurable and verified before use.
- **Copy** — 11 editable fields, 7 of which feed `sprintf()` and declare an enforced placeholder contract.
- **Cache** — guarded Redis `SCAN`+`UNLINK` purge, **shipped disabled**, defaulting to a manual-purge notice.
- **CLI** — `wp blueline settings export|import|validate|reset`, loaded only under `WP_CLI`.
- **Site Health** — a `blueline` diagnostics section.

## Bugs found and fixed that predate this work

1. **`/contact-us` was a live 404.** The off-season hero's primary CTA pointed at a page that does not
   exist; the footer pointed at the real one. Fixed and guarded.
2. **Two settings files were never loaded in production.** `links.php` and — worse — `sanitize.php`, the
   placeholder validator whose entire job is preventing a public `sprintf()` fatal. Every test passed
   because `tests/bootstrap.php` required them directly.
3. **The `blueline` textdomain notice on every request.** Root cause was *not* where it appeared to be:
   a bare file-scope call in `inc/account/endpoints.php:377`, found via a live `doing_it_wrong_run`
   backtrace, not by inspection.
4. **Four settings were editable but wired to nothing.** An admin could change the contact email, see
   "Settings saved", and the footer would not change.

## The defect that defines this phase

The error summary never rendered, and neither the controller's diagnosis (two `get_settings_errors()`
reads disagreeing) nor the implementer's first one (`autofocus` not honoured on a `<div>`) was correct.

**The actual cause:** a third-party plugin's admin-notices "declutter" module removes any `<div>` whose
class attribute contains `notice`. Our summary was `<div class="notice notice-error">`. It was deleted
client-side, after load. The inline per-field error survived only because it is a `<p>` and the selector
is div-scoped.

Found by inspecting the raw HTTP response body — proving the server was right — then instrumenting the
render path, then locating the plugin's selector. Fixed by emitting `<section>`.

The same plugin was also eating **"Settings saved."** and the **manual-purge notice** — the latter being
the entire shipped behaviour of the cache requirement, since the purge ships disabled. Neither was
caught until the final review pointed out that the browser check had exercised only the *failure* path.

## Guards added, and why

Eight now exist. Each was written after something got through:

| Guard | Catches |
|---|---|
| `IncRequireCoverageTest` | a file under `inc/` not required from `functions.php` |
| `SchemaFieldCoverageTest` | a schema field no call site reads |
| `PageLinkFallbackGuardTest` | a reintroduced hardcoded link path |
| `ContactUrlTest` | the specific 404ing slug |
| `IncTopLevelCallGuardTest` | a bare file-scope call executing at require time |
| `NoticeDivGuardTest` | a `<div>` notice this install's plugin stack would delete |
| `BootstrapFidelityTest` | the harness behaving as its author intended |
| `WpCoreContractTest` | the harness diverging from **real WordPress core** |
| contrast rules + placeholder contracts | AA regressions; `sprintf` fatals |

## The root-cause fix, added after the phase closed

The recurring failure below was three causes, not one. Only the third was actually fixed during the
phase; the others were fixed instance by instance.

| Class | Cause | Fixed by |
|---|---|---|
| **A** | Hand-written stubs of a real system, asserting nothing about their own fidelity | `WpCoreContractTest` (below) |
| **B** | Verifying at a layer where the failure cannot manifest — WP-CLI cannot see a plugin deleting DOM nodes | **Unfixed.** Needs a browser against a real install with the real plugin stack; this repo has no CI |
| **C** | Connectivity rather than behaviour — tests call units directly, never observing whether anything calls them | `IncRequireCoverageTest`, `SchemaFieldCoverageTest` |

**The uncomfortable part:** a full WordPress core checkout was at `/home/cody/arl-local/`, and the
harness referenced it nowhere. All four Class-A bugs were guesses about behaviour readable from a file
on the same machine.

`WpCoreContractTest` closes Class A in three pieces, deliberately **not** "a test that reads core at
runtime" — that would skip without an oracle, and a silently skipped test is the very failure mode
being eliminated:

1. **A committed fixture** (`tests/fixtures/wp-core-option-contract.json`) recording hook order and
   argument order for `update_option()`/`add_option()`/`sanitize_option()`, plus the core version it
   came from. Extracted from staging's live **6.9.4**, cross-checked against the local 6.8.7.
2. **A generator** under `tests/tools/` (deploy-excluded), which fails loudly and never emits a partial
   contract — verified against six malformed inputs.
3. **Two test jobs.** One always runs with no external dependency, asserting the stub's observed hook
   sequence and argument order match the fixture. One runs only with an explicitly configured oracle,
   asserting the fixture has not gone stale.

**It found two more divergences on its first run** — `sanitize_option_{$option}` dispatching 2 args
where core passes 3, and the generic `update_option`/`updated_option`/`add_option` actions never firing
at all. Both fixed; `known_gaps` is empty.

Independently confirmed not circular: a reviewer fetched core from WordPress' GitHub and the developer
docs and matched every hook name, order and argument list — including the non-obvious asymmetry where
`pre_update_option_{$option}` takes `( $value, $old_value, $option )` while the generic
`pre_update_option` takes `( $value, $option, $old_value )`.

### The guard's own known limits

- **It nearly repeated the bug it exists to prevent.** Job 2 originally defaulted to the local 6.8.7
  checkout while the fixture recorded staging's 6.9.4 — so a core upgrade would have left it passing
  against a stale oracle. It now requires an explicit `BLUELINE_WP_CORE_INCLUDES_DIR` and **fails**,
  never skips, on any version mismatch in either direction.
- It verifies **structural** contract — which hooks, in what order, with what arguments. Behavioural
  divergence (the `esc_url()` class) is out of reach of source extraction.
- Job 2 only runs when someone sets the env var. In a repo with no CI that is a documented manual step,
  same as every other gate here.

## Known limits of those guards

- **`NoticeDivGuardTest` is defeatable** by single-quoted class attributes, `printf`-templated tags,
  string concatenation, or helper-function indirection. Verified by construction; no occurrence exists
  today. A static single-pass scanner cannot see through indirection — closing it properly needs an AST
  check or a browser-level assertion.
- **`IncTopLevelCallGuardTest` misses an IIFE** — `(function () { badCall(); })();` passes. No occurrence today.
- The link guards **miss a trailing slash** (`home_url( '/schedule/' )`).

## Deferred to P1b / P2

1. **Storage shipped flat**, not the spec's nested `content`/`sections`/`links` sub-keys. Defensible, but
   §6.1 is now wrong and P1b's `sections` plus P2's `occasions` land in the same flat namespace. Decide
   whether to nest or reserve a prefix, and amend the spec.
2. **Reserved-key handling has no single owner.** `_posted_fields`/`_tab` are bare literals in five
   places across three files; `_schema` has a constant and two deliberately different policies (the CLI
   rejects, the sanitize callback clamps — documented, since a filter has no abort point).
3. **`_posted_fields` becomes load-bearing in P1b.** It exists for unchecked checkboxes and has never
   been exercised by a real `bool` field. The first toggle needs an end-to-end
   check/save/uncheck/save-from-another-tab test.
4. **Section-toggle floors are unbuilt** (§6.3) — at least one homepage module; the Register CTA locked
   during `registration_open`; a warning when disabling a section whose widget area is populated
   (`sidebar-1` and `footer-2` hold live production content). No cross-field validation plumbing exists;
   `blueline_sanitize_field()` is strictly per-field.
5. **No repair path for an already-stored bad value.** A value arriving via `wp db import` or a DB
   restore — this project's actual settings-travel mechanism — is never re-validated, and for the seven
   `hero_*` fields it flows straight into `sprintf()`.
6. **Not delivered from §6**, and not previously recorded as deferred: save snapshots, the import diff
   preview and size/depth bounds, `flush-cache`, "Delete all Blueline data", the `style.css` `:root`
   defaults parser (**§6.1.1 — a hard prerequisite for P2's contrast gate**), and the in-panel first-run
   help pane (§12).
7. **`delete_option()` bypasses the purge** entirely — plausible because the "Delete all Blueline data"
   action was never built, so an admin reaches for `wp option delete`.
8. **The purge constant remains unverified infra.** `DESIGN.md` carries the checklist; staging has no
   page-cache layer and structurally cannot validate it.
9. **Any new notice must not be a `<div>`** on this install — the break-glass notice, the deploy-drift
   notice, the orphaned-token warning all hit the same plugin.
10. **`page.php` (844 lines) parses on every front-end request** solely so one file-scope `add_filter`
    runs. Moving that filter to `sanitize.php` would let the file be `is_admin()`-gated.

## Process note

The single recurring failure mode was **a substitute behaving differently from the real thing**, five
times: the test harness vs. WordPress core (four separate fidelity bugs), WP-CLI vs. a browser POST, the
schema vs. its call sites, `functions.php` vs. what was on disk, and a simulated render cycle vs. a real
one. Every instance was green until something real ran against it.

Both wrong diagnoses this phase shared one shape: reasoning from a plausible mechanism instead of
observing the running system. Both were resolved only when someone captured evidence — a backtrace, a
live DOM, a raw HTTP response body.
