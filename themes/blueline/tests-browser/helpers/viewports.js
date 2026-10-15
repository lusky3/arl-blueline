/**
 * Shared viewport presets for the Playwright browser specs.
 *
 * One shared `PHONE` preset for mobile-drawer.spec.js and ux-fixes.spec.js.
 * Neither file's assertions depend on a specific height -- they check
 * horizontal overflow, hit-testing, and element geometry that a phone-width
 * viewport exercises regardless of exactly how tall it is.
 */

const PHONE = { width: 390, height: 800 };

module.exports = { PHONE };
