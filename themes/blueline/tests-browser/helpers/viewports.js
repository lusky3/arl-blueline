/**
 * Shared viewport presets for the Playwright browser specs.
 *
 * mobile-drawer.spec.js and ux-fixes.spec.js each declared their own
 * `PHONE` constant with a different height (780 vs 800). Neither file's
 * assertions depend on that specific height -- they check horizontal
 * overflow, hit-testing, and element geometry that a phone-width viewport
 * exercises regardless of exactly how tall it is -- so the difference was
 * incidental duplication, not two genuinely distinct presets. One shared
 * preset replaces both.
 */

const PHONE = { width: 390, height: 800 };

module.exports = { PHONE };
