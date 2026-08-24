/**
 * Shared target for the Playwright browser specs.
 *
 * All of tests-browser/*.spec.js exercise the same deployed site rather than
 * a hand-assembled fixture (see e.g. sticky-header.spec.js's top-of-file
 * comment for why), so they all need the same overridable base URL.
 */

const SITE = process.env.BLUELINE_SITE_URL || 'https://staging.rookiehockey.ca';

module.exports = { SITE };
