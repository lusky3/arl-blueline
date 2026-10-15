/*
 * The core bundle: the chrome every page has (navigation, sticky header,
 * footer theme toggle, team flyout, sidebar, wide tables, logo link names,
 * calendar buttons). Feature scripts are their own webpack entries
 * (webpack.config.js), enqueued only where their markup can render
 * (blueline_theme_bundles(), inc/enqueue.php).
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
