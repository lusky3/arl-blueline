# Blueline — sitewide footer theme toggle

## 1. Goal

Give every visitor -- logged in or not -- a light/dark/system toggle in the
site footer, with no page reload. Supersedes
docs/superpowers/specs/2026-08-22-blueline-theme-toggle-design.md §8's own
"out of scope for this pass: a visible, no-login toggle for guests" --
that scope line reflected what was asked for at the time (Account Details
only), not a technical limitation. The underlying storage/rendering
mechanism that spec built (BLUELINE_THEME_PREFERENCES, the `data-theme`
attribute, the `[data-theme]` CSS already in style.css) is unchanged and
fully reused here.

Already approved by the site owner; this document records the
implementation decisions, not a proposal.

## 2. Decisions taken

| Question | Decision | Why |
|---|---|---|
| Where the control lives | `.bl-footer__bottom-inner` (`blueline_site_footer()`), next to the copyright line. | The one universally-rendered chrome element on every page, same reasoning `blueline_footer_team_directory()` and the occasion `line` already use for their own placement there. |
| Markup shape | Three `<button>`s (System / Light / Dark) in a `role="group"` with `aria-pressed` marking the active one, not a `<select>` and not `role="radiogroup"`. | A click must apply instantly with no separate submit step, which rules out a `<select>` (needs a change+submit or extra JS to fire on `change`). A toggle-button group with `aria-pressed` is a well-established accessible pattern for this shape and needs no roving-tabindex/arrow-key machinery a `radiogroup` would call for -- judgement call, not specified by the brief. |
| Guest persistence | localStorage only, key `blueline:theme-preference`, written by assets/src/js/footer-theme-toggle.js. Never reaches the server. | Anonymous responses are srcache-cached and shared across every guest (inc/settings/cache.php); nothing that could vary per-anonymous-visitor may ever be rendered server-side into that cached HTML (inc/account/theme-preference.php's own docblock, unchanged from the original spec). |
| Guest FOUC prevention | A small, BLOCKING inline `<script>` in `<head>`, before `wp_head()`, printed only for a guest (`blueline_render_guest_theme_bootstrap_script()`, called from header.php). Reads the same localStorage key and sets `data-theme` on `<html>` before any stylesheet loads. | Matches the original spec's own "attribute is in the very first byte of HTML, zero flash" property for a logged-in visitor, extended to a guest the only way possible without server-side knowledge: a synchronous script that runs before paint. |
| Logged-in persistence | New AJAX action `wp_ajax_blueline_save_theme_preference` (never `wp_ajax_nopriv_...`), nonce-scoped to `blueline_save_theme_preference`, POSTed via `fetch()`. Calls the same `blueline_persist_theme_preference()` the WooCommerce Account Details save handler now also calls (extracted from `blueline_save_theme_preference()`, which previously inlined the clamp-and-`update_user_meta()` logic itself). | One persistence path, not two that could drift on what "clamp to a known-good value" means. Registering only the logged-in hook means admin-ajax.php itself refuses a guest's request before the handler ever runs -- the handler's own `is_user_logged_in()` check is defense in depth. |
| When the visible change applies (logged-in path) | Only after the AJAX call actually succeeds, not optimistically on click. | Matches this feature's own brief literally ("on success, the JS should also immediately update..."); a failed save leaves the toggle showing its previous, still-correct state rather than lying about what got saved. |
| Initial rendered state | Logged-in: `blueline_get_theme_preference()`, server-rendered (safe -- a logged-in page is never cached). Guest: always "system" pressed, server-rendered identically for everyone; assets/src/js/footer-theme-toggle.js re-syncs the pressed button to this browser's real localStorage value once the script runs. | Same cache-safety constraint as the guest bootstrap script -- the server cannot render a guest's real choice, so it renders the one value true for every guest alike and lets client JS correct it a moment later. This is a visible-pressed-state correction only; `data-theme` on `<html>` is already correct before paint via the bootstrap script above, so there is no colour flash, only a same-page button-highlight sync. |
| New z-index token | None added. | The toggle sits in normal document flow inside the footer bar -- no `position: fixed`/`absolute` stacking context to manage, unlike `--bl-z-floating-widget` or `--bl-z-drawer`. |
| CSS location | Added to assets/src/css/footer.css (`.bl-theme-toggle*`), not a new file. | Footer-scoped chrome; footer.css already owns everything else in `.bl-footer__bottom-inner`. |
| JS module | New file, assets/src/js/footer-theme-toggle.js, imported from index.js. | Matches this codebase's "one small file per feature" convention (announcement.js, floating-next-game.js, standings-tabs.js), even though it is related to the existing theme-preference feature. |

## 3. Testing

- PHPUnit (tests/ThemePreferenceTest.php): `blueline_persist_theme_preference()`
  directly; `blueline_save_theme_preference()` proven to route through it
  (mutation-verified); `blueline_render_theme_toggle()` in both guest and
  account modes; `blueline_render_guest_theme_bootstrap_script()` gated on
  `is_user_logged_in()`; `blueline_ajax_save_theme_preference()` covering
  nonce validation, clamping of an invalid submitted value, a logged-out
  request being rejected, and a valid save actually persisting.
  tests/bootstrap.php gained stand-ins for `check_ajax_referer()`,
  `wp_json_encode()`, `wp_send_json()`/`_success()`/`_error()` -- this is
  the first AJAX handler this theme has, so none of those existed yet.
- JS (assets/src/js/footer-theme-toggle.test.mjs): `clampPreference()` and
  `resolveToggleAction()` -- pure decision functions, no DOM -- covering
  each known value, an unrecognised value, and both the 'account'/ajax and
  guest/localStorage persistence branches.

## 4. Out of scope for this pass

- Arrow-key/roving-tabindex navigation within the toggle button group
  (see §2's markup-shape row) -- standard Tab-based focus is sufficient
  for a 3-button control and was not specified.
- Any change to the Account Details `<select>` field or its own save
  path beyond the internal extraction to `blueline_persist_theme_preference()`.
