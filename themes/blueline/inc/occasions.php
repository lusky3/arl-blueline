<?php
/**
 * Occasions: the seasonal and commemorative theming layer, server side.
 *
 * Everything the feature owns outside the stylesheet is loaded from this
 * file, split by concern into inc/occasions/: model.php, resolver.php,
 * render.php, cron.php and editor.php. Kept as the single entry point so
 * functions.php and the tests that require this file keep loading the whole
 * feature. Together they hold:
 *
 *   - The Occasion model. Its three closed enumerations (types, motifs,
 *     activation modes), the annually-recurring `MM-DD` shape check, and
 *     blueline_sanitize_occasions(), the validator inc/settings/sanitize-callback.php
 *     runs the reserved `occasions` key through on every write that
 *     reaches update_option().
 *   - blueline_occasion_presets(): the four shipped templates, as a
 *     READ-ONLY catalog for a future "add from preset" affordance. Never
 *     pre-populated into the stored value, and never read by the resolver.
 *   - The resolution engine. Window matching in SITE timezone, the
 *     precedence comparator, and the AA-override gate, which fails closed
 *     on an accent that fails contrast without a live acknowledgement.
 *   - blueline_occasion_accent_default(), which resolves
 *     --bl-occasion-accent's own declared default (`var(--bl-ice)`) to a
 *     literal hex value by reading style.css's :root block. Deliberately
 *     NOT a general :root parser; it follows exactly one var() hop for exactly one
 *     token. See
 *     docs/superpowers/specs/2026-08-20-blueline-p2-occasions-design.md
 *     §4.1: a recursive :root parser was descoped as premature generality
 *     for a need that does not exist yet.
 *   - The shipped motif SVG set, and the clamp that drops the announcement
 *     banner from `urgent` to `info` while a commemorative occasion is
 *     active.
 *   - The single WP-Cron event that purges the page cache at the next
 *     window boundary. Purge TIMING only, never the correctness mechanism:
 *     the resolver recomputes from scratch on every request regardless.
 *   - The block-editor filter that carries the resolved accent into the
 *     editor canvas, so the editor and the front end agree.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/occasions/model.php';
require_once __DIR__ . '/occasions/resolver.php';
require_once __DIR__ . '/occasions/render.php';
require_once __DIR__ . '/occasions/cron.php';
require_once __DIR__ . '/occasions/editor.php';
