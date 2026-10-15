/*
 * The core bundle: the chrome every page has (navigation, sticky header,
 * footer theme toggle, team flyout, sidebar, wide tables and their roles,
 * logo link names, new-tab warnings, calendar buttons). Feature scripts are
 * their own webpack entries (webpack.config.js), enqueued only where their
 * markup can render (blueline_theme_bundles(), inc/enqueue.php).
 */
import '../css/index.css';
import './navigation.js';
import './header-search.js';
import './sticky-header.js';
import './table-scroll.js';
import './calendar-links.js';
import './footer-theme-toggle.js';
import './team-flyout.js';
import './sidebar-jump-nav.js';
import './sidebar-scroll-cues.js';
import './team-logo-links.js';
import './table-semantics.js';
// After team-logo-links.js, which reads link text to decide whether an image
// link is already named: the hint this adds must not count as a name.
import './new-tab-links.js';
