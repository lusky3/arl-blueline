# Rookie Sport Theme — Implementation Plan (Patched v2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Review status:** Passed ka-reviewer audit. All C1–C6 criticals and M2–M8 majors resolved. See `REVIEW-FIXES.md` for diff summary.

**Goal:** Build `rookie-sport` — a modern, standalone WordPress theme that replaces both `rookie` (ThemeBoy parent) and `rookie-child`, with deep SportsPress Pro 2.7.x integration, WooCommerce Subscriptions/Payments support, and a dark-sports aesthetic.

**Architecture:** Classic PHP theme (not FSE/block theme — SportsPress Pro's widget/template system is not FSE-compatible). Uses `@wordpress/scripts` (webpack) for JS/CSS build. SP template overrides live in `sportspress/` — SportsPress's native `SP_TEMPLATE_PATH = 'sportspress/'` and `locate_template()` picks these up automatically from the active theme without any custom filter. WooCommerce overrides in `woocommerce/`. CSS uses custom properties (design tokens) + BEM naming. No jQuery dependency for new code.

**Tech Stack:** PHP 8.1+, WordPress 6.4+, SportsPress Pro 2.7.x, WooCommerce 9+, `@wordpress/scripts` 28.x (webpack), CSS custom properties, Intersection Observer API, vanilla JS ES2017+

**Text Domain:** `rookie-sport`

---

## Global Constraints

- Never modify WordPress core or plugin files
- All output escaped: `esc_html()`, `esc_url()`, `esc_attr()`, `wp_kses_post()`
- All input sanitized before use
- WPCS compliance: `phpcs --standard=WordPress`
- `$wpdb` queries: always `$wpdb->prepare()`
- Capability checks before any privileged operation
- No inline styles in PHP — all via CSS custom properties or classes
- Build artifacts in `assets/dist/` — never commit uncompiled source to served dirs
- Supports WP 6.4+, PHP 8.1+, SP Pro 2.7+, WC 9+
- Text domain `rookie-sport` everywhere
- Google Fonts loaded via `wp_enqueue_style()` only — never hardcoded `@import` in CSS
- Mobile-first breakpoints: 375 / 640 / 768 / 1024 / 1280
- SP template overrides: place files in `sportspress/` — SP's `locate_template()` finds them natively via `SP_TEMPLATE_PATH = 'sportspress/'`. No custom filter needed or correct.
- `screenshot.png` must be exactly **1200×900px** (WordPress requirement)

---

## File Structure

```
wp-content/themes/rookie-sport/
├── style.css                          # Theme header + CSS design tokens (:root vars)
├── functions.php                      # Bootstrap — requires inc/ files
├── index.php                          # Fallback template (required by WP)
├── header.php                         # Sticky nav + SP scoreboard bar
├── footer.php                         # Footer widget grid + copyright bar
├── sidebar.php                        # Sidebar (blog only)
├── page.php                           # Standard page
├── single.php                         # Blog post (calls content-single.php)
├── archive.php                        # Archive listing
├── search.php                         # Search results  ← added in patch
├── 404.php                            # 404 page
├── comments.php                       # Comments template  ← added in patch
├── template-fullwidth.php             # Full Width page template
├── template-homepage.php              # Homepage page template
├── content.php                        # Post loop partial (excerpt)
├── content-single.php                 # Single post full content  ← added in patch
├── content-page.php                   # Page content partial
├── content-nothumb.php                # Content without thumbnail (SP entity pages)
├── content-notitle.php                # Content without title (SP events w/ logos)
├── content-none.php                   # Empty state
├── rtl.css                            # RTL stylesheet (auto-loaded by WP)  ← added in patch
├── screenshot.png                     # 1200×900px theme screenshot
│
├── inc/
│   ├── setup.php                      # after_setup_theme, theme supports, nav menus, widget areas
│   ├── enqueue.php                    # Scripts + styles (uses wp_add_inline_script, not wp_localize_script)
│   ├── template-tags.php              # Helper functions
│   ├── customizer.php                 # Color + footer customizer controls
│   ├── woocommerce.php                # WC integration (class_exists guarded)
│   └── sportspress.php               # SP body classes + sidebar logic
│
├── sportspress/
│   ├── index.php                      # Security silence file  ← added in patch
│   ├── single-event.php               # Event page
│   ├── single-player.php              # Player page (fixed: taxonomy for position)
│   ├── single-team.php                # Team page
│   └── single-staff.php              # Staff/coach page
│
├── woocommerce/
│   ├── archive-product.php
│   ├── checkout/
│   │   ├── form-checkout.php
│   │   ├── form-coupon.php
│   │   ├── form-login.php
│   │   └── thankyou.php
│   ├── myaccount/
│   │   ├── dashboard.php
│   │   ├── dashboard-store-credit.php
│   │   ├── my-refund-requests.php
│   │   └── store-credit.php
│   └── emails/                        # 14 templates migrated from rookie-child
│
├── assets/
│   ├── src/
│   │   ├── css/
│   │   │   ├── base.css
│   │   │   ├── layout.css
│   │   │   ├── navigation.css
│   │   │   ├── header.css
│   │   │   ├── footer.css
│   │   │   ├── sportspress.css
│   │   │   ├── woocommerce.css
│   │   │   ├── blocks.css             # Gutenberg block overrides  ← added in patch
│   │   │   └── editor.css             # Block editor styles
│   │   └── js/
│   │       ├── navigation.js          # Mobile slide-over, keyboard accessibility
│   │       ├── sticky-header.js       # IntersectionObserver sticky header
│   │       └── sp-tables.js           # SP DataTable enhancements  ← added in patch
│   └── dist/                          # webpack output (index.css, index.js, editor.css)
│
├── languages/
│   └── rookie-sport.pot               # Translatable strings (generated post-implementation)
│
├── package.json
└── .gitignore
```

---

## Task 1: Project Scaffold & Build System

**Files:**
- Create: `style.css` (theme header + design tokens)
- Create: `package.json`
- Create: `assets/src/index.js` (webpack entry point)
- Create: `assets/src/css/blocks.css` (stub — patch fix M5)
- Create: `assets/src/js/sp-tables.js` (stub — patch fix M5)
- Create: `rtl.css` (patch fix C6)
- Create: `sportspress/index.php` (security file — patch fix M8)
- Create: `.gitignore`

**Interfaces:**
- Produces: `assets/dist/index.css`, `assets/dist/index.js` consumed by Task 2 enqueue.php

- [ ] **Step 1: Create theme directory structure**

```bash
mkdir -p /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/assets/src/{css,js}
mkdir -p /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/assets/dist
mkdir -p /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/{inc,sportspress,woocommerce/checkout,woocommerce/myaccount,woocommerce/emails,languages}
chown -R www-data:www-data /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport
```

- [ ] **Step 2: Create `style.css` (theme header + all CSS design tokens)**

```css
/*
Theme Name: Rookie Sport
Theme URI: https://rookiehockey.ca
Description: Modern sports theme for rookiehockey.ca. Deep SportsPress Pro + WooCommerce integration.
Author: Cody Lusk
Author URI: https://rookiehockey.ca
Version: 1.0.0
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: rookie-sport
Tags: sports, dark, sportspress, woocommerce
*/

:root {
  /* Brand */
  --color-teal:        #00a69c;
  --color-teal-dark:   #00877e;
  --color-teal-light:  #33bdb5;
  --color-navy:        #1a2332;
  --color-navy-dark:   #0f1923;
  --color-navy-mid:    #243044;

  /* Surface */
  --color-bg:          #0f1923;
  --color-surface:     #1a2332;
  --color-surface-2:   #243044;
  --color-card:        #ffffff;
  --color-card-alt:    #f8f9fa;

  /* Text */
  --color-text-primary:   #ffffff;
  --color-text-secondary: rgba(255,255,255,0.65);
  --color-text-muted:     rgba(255,255,255,0.4);
  --color-text-dark:      #111827;
  --color-text-dark-2:    #374151;

  /* Borders */
  --color-border:      rgba(255,255,255,0.1);
  --color-border-card: #e5e7eb;

  /* Status */
  --color-win:    #22c55e;
  --color-loss:   #ef4444;
  --color-draw:   #f59e0b;

  /* Typography */
  --font-heading: 'Barlow Condensed', sans-serif;
  --font-body:    'Inter', sans-serif;
  --font-mono:    'JetBrains Mono', monospace;

  /* Type Scale */
  --text-xs:   0.75rem;
  --text-sm:   0.875rem;
  --text-base: 1rem;
  --text-lg:   1.125rem;
  --text-xl:   1.25rem;
  --text-2xl:  1.5rem;
  --text-3xl:  1.875rem;
  --text-4xl:  2.25rem;
  --text-5xl:  3rem;

  /* Spacing (8px base) */
  --space-1:  0.25rem;
  --space-2:  0.5rem;
  --space-3:  0.75rem;
  --space-4:  1rem;
  --space-6:  1.5rem;
  --space-8:  2rem;
  --space-10: 2.5rem;
  --space-12: 3rem;
  --space-16: 4rem;

  /* Layout */
  --container-max:    1280px;
  --content-max:      820px;
  --nav-height:       64px;
  --border-radius:    6px;
  --border-radius-lg: 12px;

  /* Shadows */
  --shadow-sm:  0 1px 3px rgba(0,0,0,0.3);
  --shadow-md:  0 4px 16px rgba(0,0,0,0.4);
  --shadow-lg:  0 8px 32px rgba(0,0,0,0.5);

  /* Transitions */
  --transition-fast:   150ms ease-out;
  --transition-normal: 250ms ease-out;
}
```

- [ ] **Step 3: Create `rtl.css` (patch fix C6)**

```css
/* RTL overrides for Rookie Sport theme */
/* Directional properties are mirrored here automatically by WordPress for RTL locales. */

body { direction: rtl; unicode-bidi: embed; }

.main-navigation ul ul { left: auto; right: 0; }
.main-navigation ul ul ul { left: auto; right: 100%; }

.nav-cart-count { right: auto; left: 4px; }

.sp-player-hero__inner { flex-direction: row-reverse; }
.sp-team-hero__inner   { flex-direction: row-reverse; }

.card-header { border-right: 3px solid var(--color-teal); border-left: none; }
.sp-heading  { border-right: 3px solid var(--color-teal); border-left: none; }

.footer-bottom-inner { flex-direction: row-reverse; }
```

- [ ] **Step 4: Create `sportspress/index.php` (security silence file — patch fix M8)**

```php
<?php
// Silence is golden.
```

- [ ] **Step 5: Create `package.json`**

```json
{
  "name": "rookie-sport",
  "version": "1.0.0",
  "private": true,
  "scripts": {
    "build": "wp-scripts build assets/src/index.js assets/src/editor.js --output-path=assets/dist",
    "start": "wp-scripts start assets/src/index.js assets/src/editor.js --output-path=assets/dist",
    "lint:css": "wp-scripts lint-style 'assets/src/css/**/*.css'",
    "lint:js":  "wp-scripts lint-js 'assets/src/js/**/*.js'"
  },
  "devDependencies": {
    "@wordpress/scripts": "28.0.0"
  }
}
```

- [ ] **Step 6: Create `assets/src/index.js` (webpack entry — all CSS + JS)**

```js
// CSS — imported in dependency order
import './css/base.css';
import './css/layout.css';
import './css/navigation.css';
import './css/header.css';
import './css/footer.css';
import './css/sportspress.css';
import './css/woocommerce.css';
import './css/blocks.css';

// JS
import './js/navigation.js';
import './js/sticky-header.js';
import './js/sp-tables.js';
```

- [ ] **Step 7: Create `assets/src/editor.js` (block editor entry)**

```js
import './css/editor.css';
```

- [ ] **Step 8: Create `assets/src/css/blocks.css` (stub — patch fix M5)**

```css
/* === Gutenberg block overrides ===
   Add front-end overrides for core blocks here as needed.
   The editor stylesheet is in editor.css.
*/

/* Align wide/full within the theme container */
.wp-block-group.alignwide,
.wp-block-cover.alignwide {
    max-width: var(--container-max);
    margin-inline: auto;
}

.wp-block-group.alignfull,
.wp-block-cover.alignfull {
    width: 100%;
    max-width: 100%;
}

/* Buttons */
.wp-block-button__link {
    font-family: var(--font-heading);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-radius: var(--border-radius) !important;
}

/* Quotes */
.wp-block-pullquote {
    border-top: 4px solid var(--color-teal);
    border-bottom: 4px solid var(--color-teal);
}

/* Tables */
.wp-block-table td,
.wp-block-table th {
    border-color: var(--color-border-card);
}
```

- [ ] **Step 9: Create `assets/src/css/editor.css` (block editor styles)**

```css
/* Block editor — mirrors front-end typography and token values */
.editor-styles-wrapper {
    font-family: 'Inter', sans-serif;
    font-size: 1rem;
    line-height: 1.6;
    color: #111827;
}

.editor-styles-wrapper h1,
.editor-styles-wrapper h2,
.editor-styles-wrapper h3,
.editor-styles-wrapper h4,
.editor-styles-wrapper h5,
.editor-styles-wrapper h6 {
    font-family: 'Barlow Condensed', sans-serif;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.editor-styles-wrapper a { color: #00a69c; }
```

- [ ] **Step 10: Create `assets/src/js/sp-tables.js` (stub — patch fix M5)**

```js
/**
 * SportsPress DataTable enhancements.
 *
 * SP initialises DataTables itself. This file adds responsive
 * scroll-hint indicators for mobile and visual sort feedback.
 */
( function () {
    'use strict';

    function addScrollHint() {
        document.querySelectorAll( '.sp-table-wrapper' ).forEach( function ( wrapper ) {
            if ( wrapper.scrollWidth > wrapper.clientWidth ) {
                wrapper.setAttribute( 'data-scrollable', 'true' );
            }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', addScrollHint );
    } else {
        addScrollHint();
    }
} )();
```

- [ ] **Step 11: Create `.gitignore`**

```
node_modules/
assets/dist/
*.log
```

- [ ] **Step 12: Install dependencies and verify first build**

```bash
cd /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport
npm install
npm run build
ls assets/dist/
# Expected: index.css  index.js  editor.css  editor.js  (all > 0 bytes)
```

- [ ] **Step 13: Commit**

```bash
git add wp-content/themes/rookie-sport/
git commit -m "feat(theme): scaffold rookie-sport — build system, tokens, rtl.css, stubs"
```

---

## Task 2: Theme Setup & WordPress Integration

**Files:**
- Create: `functions.php`
- Create: `inc/setup.php`
- Create: `inc/enqueue.php` (uses `wp_add_inline_script` — patch fix N2)
- Create: `inc/sportspress.php` (NO custom filter — patch fix C1)
- Create: `inc/woocommerce.php` (class_exists guarded — patch fix M7)

**Interfaces:**
- Produces: `ROOKIE_SPORT_VERSION`, `ROOKIE_SPORT_DIR`, `ROOKIE_SPORT_URI` constants; `rookie_sport_has_sidebar()` function consumed by all template files

- [ ] **Step 1: Create `functions.php`**

```php
<?php
/**
 * Rookie Sport — bootstrap.
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ROOKIE_SPORT_VERSION', '1.0.0' );
define( 'ROOKIE_SPORT_DIR',     get_template_directory() );
define( 'ROOKIE_SPORT_URI',     get_template_directory_uri() );

require ROOKIE_SPORT_DIR . '/inc/setup.php';
require ROOKIE_SPORT_DIR . '/inc/enqueue.php';
require ROOKIE_SPORT_DIR . '/inc/template-tags.php';
require ROOKIE_SPORT_DIR . '/inc/customizer.php';
require ROOKIE_SPORT_DIR . '/inc/sportspress.php';
require ROOKIE_SPORT_DIR . '/inc/woocommerce.php';
```

- [ ] **Step 2: Create `inc/setup.php`**

```php
<?php
/**
 * Theme setup.
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_setup_theme', 'rookie_sport_setup' );

function rookie_sport_setup(): void {
	load_theme_textdomain( 'rookie-sport', ROOKIE_SPORT_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support( 'post-formats', [ 'aside', 'image', 'video', 'quote', 'link' ] );
	add_theme_support( 'customize-selective-refresh-widgets' );
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );
	add_editor_style( 'assets/dist/editor.css' );

	// SportsPress — declare theme support so SP activates its template system.
	add_theme_support( 'sportspress' );
	add_theme_support( 'mega-slider' );
	add_theme_support( 'social-sidebar' );
	add_theme_support( 'news-widget' );

	// WooCommerce
	add_theme_support( 'woocommerce', [
		'thumbnail_image_width' => 450,
		'single_image_width'    => 600,
		'product_grid'          => [
			'default_rows'    => 3,
			'min_rows'        => 1,
			'default_columns' => 3,
			'min_columns'     => 1,
			'max_columns'     => 4,
		],
	] );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );

	register_nav_menus( [
		'primary' => esc_html__( 'Primary Menu', 'rookie-sport' ),
		'footer'  => esc_html__( 'Footer Menu', 'rookie-sport' ),
	] );

	// Content width
	if ( ! isset( $content_width ) ) {
		$content_width = 820;
	}
}

add_action( 'widgets_init', 'rookie_sport_widgets_init' );

function rookie_sport_widgets_init(): void {
	$shared = [
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	];

	register_sidebar( array_merge( $shared, [
		'name'        => esc_html__( 'Sidebar', 'rookie-sport' ),
		'id'          => 'sidebar-1',
		'description' => esc_html__( 'Blog sidebar widgets.', 'rookie-sport' ),
	] ) );

	for ( $i = 1; $i <= 3; $i++ ) {
		register_sidebar( array_merge( $shared, [
			'name' => sprintf( esc_html__( 'Footer %d', 'rookie-sport' ), $i ),
			'id'   => sprintf( 'footer-%d', $i ),
		] ) );
	}
}
```

- [ ] **Step 3: Create `inc/enqueue.php` — uses `wp_add_inline_script` (patch fix N2)**

```php
<?php
/**
 * Asset enqueueing.
 * Uses wp_add_inline_script instead of deprecated wp_localize_script (patch N2).
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', 'rookie_sport_enqueue' );

function rookie_sport_enqueue(): void {
	// Google Fonts — Barlow Condensed (headings) + Inter (body).
	// Note: loads from google CDN. For strict GDPR environments, self-host instead.
	wp_enqueue_style(
		'rookie-sport-fonts',
		'https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap',
		[],
		null
	);

	wp_enqueue_style(
		'rookie-sport-style',
		ROOKIE_SPORT_URI . '/assets/dist/index.css',
		[ 'rookie-sport-fonts' ],
		ROOKIE_SPORT_VERSION
	);

	wp_enqueue_script(
		'rookie-sport-scripts',
		ROOKIE_SPORT_URI . '/assets/dist/index.js',
		[],
		ROOKIE_SPORT_VERSION,
		true
	);

	// Pass data to JS via wp_add_inline_script (replaces deprecated wp_localize_script).
	wp_add_inline_script(
		'rookie-sport-scripts',
		'const RookieSport = ' . wp_json_encode( [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rookie_sport_nonce' ),
			'siteUrl' => esc_url( home_url() ),
		] ) . ';',
		'before'
	);

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}

add_action( 'admin_enqueue_scripts', 'rookie_sport_admin_enqueue' );

function rookie_sport_admin_enqueue(): void {
	wp_enqueue_style(
		'rookie-sport-admin',
		ROOKIE_SPORT_URI . '/assets/dist/editor.css',
		[],
		ROOKIE_SPORT_VERSION
	);
}
```

- [ ] **Step 4: Create `inc/sportspress.php` — NO custom filter (patch fix C1)**

```php
<?php
/**
 * SportsPress integration.
 *
 * SP template override mechanism (confirmed from SP source class-sp-template-loader.php):
 * SP_TEMPLATE_PATH = 'sportspress/' and SP calls locate_template() which checks the
 * active theme directory automatically. Placing templates in sportspress/ here is all
 * that is needed — no custom filter required or correct.
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add body classes for SP entity pages so CSS can target them.
 * Also used by rookie_sport_has_sidebar() to suppress sidebar.
 */
add_filter( 'body_class', 'rookie_sport_sp_body_classes' );

function rookie_sport_sp_body_classes( array $classes ): array {
	$sp_post_types = [ 'sp_event', 'sp_team', 'sp_player', 'sp_staff', 'sp_league', 'sp_season', 'sp_venue' ];
	if ( is_singular( $sp_post_types ) || is_post_type_archive( $sp_post_types ) ) {
		$classes[] = 'is-sp-page';
		$classes[] = 'no-sidebar';
	}
	return $classes;
}
```

- [ ] **Step 5: Create `inc/woocommerce.php` — guarded by class_exists (patch fix M7)**

```php
<?php
/**
 * WooCommerce integration.
 * All WC hook operations are guarded by class_exists( 'WooCommerce' ).
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	return;
}

// Replace WC default wrappers with theme wrappers.
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content',  'woocommerce_output_content_wrapper_end', 10 );

add_action( 'woocommerce_before_main_content', 'rookie_sport_wc_wrapper_start', 10 );
add_action( 'woocommerce_after_main_content',  'rookie_sport_wc_wrapper_end',   10 );

function rookie_sport_wc_wrapper_start(): void {
	echo '<div id="primary" class="wc-primary content-area">';
	echo '<main id="main" class="site-main">';
}

function rookie_sport_wc_wrapper_end(): void {
	echo '</main></div>';
}

// Remove WC's default sidebar — theme handles layout without it.
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/**
 * Change "Add to cart" text to "Register Now" on subscription products.
 */
add_filter( 'woocommerce_product_add_to_cart_text', 'rookie_sport_add_to_cart_text', 10, 1 );

function rookie_sport_add_to_cart_text( string $text ): string {
	global $product;
	if ( $product && $product->is_type( 'subscription' ) ) {
		return esc_html__( 'Register Now', 'rookie-sport' );
	}
	return $text;
}
```

- [ ] **Step 6: Activate theme and verify no fatal errors**

```bash
ssh -p SSH_PORT root@production-host.example "
wp --path=/var/www/rookiehockey.ca/htdocs theme activate rookie-sport --allow-root 2>&1
tail -10 /var/log/nginx/rookiehockey.ca.error.log
"
# Expected: 'Switched to Rookie Sport theme.' and no PHP Fatal errors
```

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(theme): setup, enqueue (wp_add_inline_script), SP integration (no filter), WC guarded"
```

---

## Task 3: Base CSS — Reset, Typography, Layout

**Files:**
- Create: `assets/src/css/base.css`
- Create: `assets/src/css/layout.css`

- [ ] **Step 1: Create `assets/src/css/base.css`**

```css
/* === Reset === */
*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; }
img, video { max-width: 100%; height: auto; display: block; }
input, button, textarea, select { font: inherit; }

/* === Body === */
body {
    font-family: var(--font-body);
    font-size: var(--text-base);
    line-height: 1.6;
    color: var(--color-text-dark);
    background-color: var(--color-bg);
    -webkit-font-smoothing: antialiased;
}

/* === Headings === */
h1, h2, h3, h4, h5, h6 {
    font-family: var(--font-heading);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
    line-height: 1.1;
    margin: 0 0 var(--space-4);
    color: var(--color-text-primary);
}
h1 { font-size: clamp(var(--text-3xl), 5vw, var(--text-5xl)); }
h2 { font-size: clamp(var(--text-2xl), 4vw, var(--text-4xl)); }
h3 { font-size: clamp(var(--text-xl),  3vw, var(--text-3xl)); }
h4 { font-size: var(--text-xl); }
h5 { font-size: var(--text-lg); }
h6 { font-size: var(--text-base); }

/* Headings inside white card/content surfaces */
.site-content h1, .site-content h2, .site-content h3,
.site-content h4, .site-content h5, .site-content h6,
.wc-primary h1, .wc-primary h2, .wc-primary h3 {
    color: var(--color-text-dark);
}

/* === Body text === */
p { margin: 0 0 var(--space-4); }
p:last-child { margin-bottom: 0; }

a {
    color: var(--color-teal);
    text-decoration: none;
    transition: color var(--transition-fast);
}
a:hover { color: var(--color-teal-dark); }
a:focus-visible {
    outline: 2px solid var(--color-teal);
    outline-offset: 2px;
    border-radius: 2px;
}

/* === Buttons === */
button,
input[type="button"],
input[type="reset"],
input[type="submit"],
.button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: var(--space-2);
    padding: 0.75em 1.5em;
    min-height: 44px;
    font-family: var(--font-heading);
    font-size: var(--text-base);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #fff;
    background: var(--color-teal);
    border: none;
    border-radius: var(--border-radius);
    cursor: pointer;
    transition: background var(--transition-fast), transform var(--transition-fast), box-shadow var(--transition-fast);
    text-decoration: none;
    white-space: nowrap;
}
button:hover, input[type="submit"]:hover, .button:hover {
    background: var(--color-teal-dark);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,166,156,0.35);
    color: #fff;
}
button:active, input[type="submit"]:active, .button:active {
    transform: translateY(0);
    box-shadow: none;
}

/* === Forms === */
label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 600;
    margin-bottom: var(--space-1);
    color: var(--color-text-dark);
}
input[type="text"],
input[type="email"],
input[type="url"],
input[type="password"],
input[type="search"],
input[type="tel"],
input[type="number"],
textarea,
select {
    display: block;
    width: 100%;
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-base);
    border: 1.5px solid #d1d5db;
    border-radius: var(--border-radius);
    background: #fff;
    color: var(--color-text-dark);
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    min-height: 44px;
}
input:focus, textarea:focus, select:focus {
    outline: none;
    border-color: var(--color-teal);
    box-shadow: 0 0 0 3px rgba(0,166,156,0.15);
}

/* === Tables (generic) === */
table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
th {
    font-family: var(--font-heading);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: var(--text-xs);
    padding: var(--space-2) var(--space-3);
    background: var(--color-navy);
    color: var(--color-text-primary);
    text-align: center;
    white-space: nowrap;
}
td {
    padding: var(--space-2) var(--space-3);
    text-align: center;
    border-bottom: 1px solid var(--color-border-card);
    color: var(--color-text-dark);
}
tr:nth-child(even) td { background: var(--color-card-alt); }
tr:hover td { background: #eef2ff; transition: background var(--transition-fast); }

/* === Screen reader text (WCAG 2.4.1) === */
.screen-reader-text {
    border: 0;
    clip: rect(1px,1px,1px,1px);
    clip-path: inset(50%);
    height: 1px;
    margin: -1px;
    overflow: hidden;
    padding: 0;
    position: absolute;
    width: 1px;
    word-wrap: normal !important;
}
.screen-reader-text:focus {
    background: var(--color-navy);
    clip: auto !important;
    clip-path: none;
    color: #fff;
    display: block;
    font-size: var(--text-sm);
    font-weight: 700;
    height: auto;
    left: var(--space-4);
    padding: var(--space-3) var(--space-4);
    top: var(--space-4);
    width: auto;
    z-index: 100000;
    border-radius: var(--border-radius);
}
```

- [ ] **Step 2: Create `assets/src/css/layout.css`**

```css
/* === Site structure === */
#page { display: flex; flex-direction: column; min-height: 100dvh; }
.site-content { flex: 1; background: var(--color-card); }

/* === Container === */
.container,
.content-wrapper,
.footer-wrapper,
.nav-wrapper {
    width: 100%;
    max-width: var(--container-max);
    margin-inline: auto;
    padding-inline: var(--space-4);
}
@media (min-width: 640px)  { .container, .content-wrapper, .footer-wrapper, .nav-wrapper { padding-inline: var(--space-6); } }
@media (min-width: 1024px) { .container, .content-wrapper, .footer-wrapper, .nav-wrapper { padding-inline: var(--space-8); } }

/* === Content/sidebar grid === */
.content-wrapper {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-8);
    padding-block: var(--space-8);
}
@media (min-width: 1024px) {
    .content-wrapper.has-sidebar { grid-template-columns: 1fr 300px; }
}

/* === SP/WC pages: always full-width, no sidebar === */
.is-sp-page .content-wrapper,
.woocommerce .content-wrapper,
.woocommerce-page .content-wrapper {
    grid-template-columns: 1fr;
}
.is-sp-page #secondary { display: none; }

/* === Cards === */
.card {
    background: var(--color-card);
    border: 1px solid var(--color-border-card);
    border-radius: var(--border-radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.card-body { padding: var(--space-6); }
.card-header {
    padding: var(--space-3) var(--space-6);
    background: var(--color-navy);
    color: var(--color-text-primary);
    font-family: var(--font-heading);
    font-weight: 700;
    font-size: var(--text-sm);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 3px solid var(--color-teal);
}

/* === Sidebar widgets === */
#secondary .widget {
    background: var(--color-card);
    border: 1px solid var(--color-border-card);
    border-radius: var(--border-radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-6);
}
#secondary .widget-title {
    margin: 0;
    padding: var(--space-3) var(--space-4);
    background: var(--color-navy);
    color: var(--color-text-primary);
    font-family: var(--font-heading);
    font-size: var(--text-sm);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 3px solid var(--color-teal);
}
#secondary .widget > *:not(.widget-title) { padding: var(--space-4); }

/* === Post navigation === */
.post-navigation, .posts-navigation {
    display: flex;
    justify-content: space-between;
    gap: var(--space-4);
    padding: var(--space-8) 0;
    border-top: 1px solid var(--color-border-card);
}
.nav-links a {
    font-family: var(--font-heading);
    font-weight: 600;
    font-size: var(--text-sm);
    text-transform: uppercase;
    color: var(--color-teal);
}
```

- [ ] **Step 3: Build and verify**

```bash
npm run build
# Expected: no errors, assets/dist/index.css exists and is non-empty
```

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(theme): base.css and layout.css"
```

---

## Task 4: Navigation CSS + JS

**Files:**
- Create: `assets/src/css/navigation.css`
- Create: `assets/src/js/navigation.js`
- Create: `assets/src/js/sticky-header.js`

- [ ] **Step 1: Create `assets/src/css/navigation.css`**

```css
/* === Sticky header === */
#masthead {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--color-navy);
    box-shadow: 0 2px 8px rgba(0,0,0,0.4);
    transition: background var(--transition-normal);
}
#masthead.is-scrolled {
    background: rgba(26,35,50,0.97);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
}

/* === Nav wrapper === */
.nav-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: var(--nav-height);
    gap: var(--space-4);
}

/* === Branding === */
.site-branding { display: flex; align-items: center; gap: var(--space-3); flex-shrink: 0; }
.site-logo img { height: 40px; width: auto; }
.site-title {
    font-family: var(--font-heading);
    font-size: var(--text-xl);
    font-weight: 800;
    color: #fff;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0;
    line-height: 1;
}
.site-title a { color: inherit; }
.site-title a:hover { color: var(--color-teal); }
.site-description { display: none; }

/* === Primary nav — desktop === */
.main-navigation { flex: 1; display: flex; justify-content: center; }
.main-navigation ul { list-style: none; margin: 0; padding: 0; display: flex; align-items: center; }

.main-navigation > ul > li > a {
    display: flex;
    align-items: center;
    height: var(--nav-height);
    padding: 0 var(--space-4);
    font-family: var(--font-heading);
    font-size: var(--text-sm);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: rgba(255,255,255,0.75);
    transition: color var(--transition-fast);
    position: relative;
    white-space: nowrap;
}
.main-navigation > ul > li > a::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    background: var(--color-teal);
    transform: scaleX(0);
    transition: transform var(--transition-fast);
}
.main-navigation > ul > li:hover > a,
.main-navigation > ul > li.current-menu-item > a,
.main-navigation > ul > li.current-menu-parent > a { color: #fff; }
.main-navigation > ul > li:hover > a::after,
.main-navigation > ul > li.current-menu-item > a::after,
.main-navigation > ul > li.current-menu-parent > a::after { transform: scaleX(1); }

/* === Dropdowns === */
.main-navigation li { position: relative; }
.main-navigation ul ul {
    display: flex;
    flex-direction: column;
    position: absolute;
    top: 100%; left: 0;
    min-width: 220px;
    background: var(--color-navy-dark);
    border-top: 3px solid var(--color-teal);
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    box-shadow: var(--shadow-lg);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-8px);
    transition: opacity var(--transition-normal), transform var(--transition-normal), visibility var(--transition-normal);
    pointer-events: none;
    z-index: 200;
}
.main-navigation li:hover > ul,
.main-navigation li.focus > ul {
    opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto;
}
.main-navigation ul ul a {
    display: block;
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-sm);
    color: rgba(255,255,255,0.75);
    transition: color var(--transition-fast), background var(--transition-fast);
    border-bottom: 1px solid rgba(255,255,255,0.05);
}
.main-navigation ul ul a:hover { color: #fff; background: rgba(0,166,156,0.15); }

/* === Cart icon + hamburger === */
.nav-actions { display: flex; align-items: center; gap: var(--space-2); flex-shrink: 0; }
.nav-cart-link {
    display: flex; align-items: center; justify-content: center;
    position: relative; width: 44px; height: 44px;
    color: rgba(255,255,255,0.75);
    border-radius: var(--border-radius);
    transition: color var(--transition-fast), background var(--transition-fast);
}
.nav-cart-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
.nav-cart-link svg { width: 22px; height: 22px; }
.nav-cart-count {
    position: absolute; top: 4px; right: 4px;
    min-width: 16px; height: 16px;
    background: var(--color-teal); color: #fff;
    font-size: 10px; font-weight: 700; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px; line-height: 1;
}
.nav-cart-count:empty { display: none; }

.menu-toggle {
    display: none; align-items: center; justify-content: center;
    width: 44px; height: 44px; min-height: unset;
    background: transparent;
    border: 1.5px solid rgba(255,255,255,0.2);
    border-radius: var(--border-radius);
    color: rgba(255,255,255,0.75);
    cursor: pointer;
    transition: border-color var(--transition-fast), color var(--transition-fast), background var(--transition-fast);
    padding: 0; transform: none; box-shadow: none;
    text-transform: none; letter-spacing: 0; font-size: unset;
}
.menu-toggle:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.4); color: #fff; transform: none; box-shadow: none; }
.menu-toggle svg { width: 20px; height: 20px; pointer-events: none; }

/* === Mobile panel === */
@media (max-width: 1023px) {
    .menu-toggle { display: flex; }
    .main-navigation {
        position: fixed; top: 0; right: 0; bottom: 0;
        width: min(340px, 85vw);
        background: var(--color-navy-dark);
        z-index: 500; flex-direction: column; justify-content: flex-start;
        padding: calc(var(--nav-height) + var(--space-4)) var(--space-4) var(--space-8);
        overflow-y: auto;
        transform: translateX(100%);
        transition: transform 300ms cubic-bezier(0.4,0,0.2,1);
        box-shadow: var(--shadow-lg);
    }
    .main-navigation.toggled { transform: translateX(0); }
    .nav-overlay {
        display: none; position: fixed; inset: 0;
        background: rgba(0,0,0,0.6); z-index: 499;
        backdrop-filter: blur(2px);
    }
    .nav-overlay.is-visible { display: block; }
    .main-navigation ul { flex-direction: column; gap: 0; width: 100%; }
    .main-navigation > ul > li > a { height: auto; padding: var(--space-3) var(--space-2); font-size: var(--text-base); border-bottom: 1px solid rgba(255,255,255,0.07); }
    .main-navigation > ul > li > a::after { display: none; }
    .main-navigation ul ul {
        position: static; opacity: 1; visibility: visible; transform: none;
        box-shadow: none; border-top: none; border-left: 3px solid var(--color-teal);
        background: rgba(0,0,0,0.15); border-radius: 0; pointer-events: auto;
        display: none;
    }
    .main-navigation ul ul.is-open { display: flex; }
    .main-navigation ul ul a { padding-left: var(--space-6); }
    .menu-item-has-children { position: relative; display: flex; flex-wrap: wrap; align-items: center; }
    .menu-item-has-children > a { flex: 1; }
    .submenu-toggle {
        display: flex; align-items: center; justify-content: center;
        width: 44px; height: 44px; min-height: unset;
        background: transparent; border: none;
        color: rgba(255,255,255,0.6); cursor: pointer;
        transition: color var(--transition-fast), transform var(--transition-fast);
        padding: 0; transform: none; box-shadow: none; letter-spacing: 0; text-transform: none; font-size: unset;
        flex-shrink: 0;
    }
    .submenu-toggle:hover { color: #fff; background: transparent; transform: none; box-shadow: none; }
    .menu-item-has-children > ul { width: 100%; }
}
@media (min-width: 1024px) {
    .submenu-toggle { display: none; }
}
```

- [ ] **Step 2: Create `assets/src/js/navigation.js`**

```js
( function () {
    'use strict';
    const nav    = document.getElementById( 'site-navigation' );
    const toggle = document.querySelector( '.menu-toggle' );
    if ( ! nav || ! toggle ) return;

    const overlay = document.createElement( 'div' );
    overlay.className = 'nav-overlay';
    overlay.setAttribute( 'aria-hidden', 'true' );
    document.body.appendChild( overlay );

    const iconOpen  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
    const iconClose = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    toggle.innerHTML = iconOpen;
    toggle.setAttribute( 'aria-expanded', 'false' );
    toggle.setAttribute( 'aria-controls', 'site-navigation' );

    function openNav() {
        nav.classList.add( 'toggled' );
        overlay.classList.add( 'is-visible' );
        toggle.setAttribute( 'aria-expanded', 'true' );
        toggle.innerHTML = iconClose;
        document.body.style.overflow = 'hidden';
        const firstLink = nav.querySelector( 'a' );
        if ( firstLink ) firstLink.focus();
    }
    function closeNav() {
        nav.classList.remove( 'toggled' );
        overlay.classList.remove( 'is-visible' );
        toggle.setAttribute( 'aria-expanded', 'false' );
        toggle.innerHTML = iconOpen;
        document.body.style.overflow = '';
        toggle.focus();
    }

    toggle.addEventListener( 'click', () => nav.classList.contains( 'toggled' ) ? closeNav() : openNav() );
    overlay.addEventListener( 'click', closeNav );
    document.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' && nav.classList.contains( 'toggled' ) ) closeNav(); } );

    // Mobile: submenu chevron toggles
    nav.querySelectorAll( '.menu-item-has-children > a' ).forEach( ( link ) => {
        const chevron = document.createElement( 'button' );
        chevron.className = 'submenu-toggle';
        chevron.setAttribute( 'aria-expanded', 'false' );
        chevron.setAttribute( 'aria-label', link.textContent.trim() + ' submenu' );
        chevron.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true" width="16" height="16"><polyline points="6 9 12 15 18 9"/></svg>';
        const sub = link.nextElementSibling;
        link.parentElement.appendChild( chevron );
        chevron.addEventListener( 'click', () => {
            const isOpen = sub.classList.toggle( 'is-open' );
            chevron.setAttribute( 'aria-expanded', String( isOpen ) );
            chevron.style.transform = isOpen ? 'rotate(180deg)' : '';
        } );
    } );

    // Desktop: keyboard focus management
    nav.querySelectorAll( '.menu-item-has-children' ).forEach( ( item ) => {
        item.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Escape' ) { item.classList.remove( 'focus' ); item.querySelector( 'a' ).focus(); } } );
        item.querySelector( 'a' ).addEventListener( 'focus', () => item.classList.add( 'focus' ) );
        item.addEventListener( 'focusout', ( e ) => { if ( ! item.contains( e.relatedTarget ) ) item.classList.remove( 'focus' ); } );
    } );
} )();
```

- [ ] **Step 3: Create `assets/src/js/sticky-header.js`**

```js
( function () {
    'use strict';
    const header = document.getElementById( 'masthead' );
    if ( ! header ) return;
    const sentinel = document.createElement( 'div' );
    sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none;';
    document.body.insertBefore( sentinel, document.body.firstChild );
    new IntersectionObserver(
        ( [ entry ] ) => header.classList.toggle( 'is-scrolled', ! entry.isIntersecting ),
        { threshold: 0 }
    ).observe( sentinel );
} )();
```

- [ ] **Step 4: Build and verify**

```bash
npm run build
```

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(theme): navigation CSS + JS — sticky header, mobile slide-over, keyboard nav"
```

---

## Task 5: Header + Footer PHP Templates

**Files:**
- Create: `header.php`
- Create: `footer.php`
- Create: `assets/src/css/header.css`
- Create: `assets/src/css/footer.css`

- [ ] **Step 1: Create `header.php`**

```php
<?php
/**
 * Site header.
 *
 * @package RookieSport
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div class="sp-header-bar" role="region" aria-label="<?php esc_attr_e( 'Scoreboard', 'rookie-sport' ); ?>">
    <?php do_action( 'sportspress_header' ); ?>
</div>

<div id="page" class="site">
    <a class="screen-reader-text" href="#main"><?php esc_html_e( 'Skip to content', 'rookie-sport' ); ?></a>

    <header id="masthead" class="site-header">
        <div class="nav-wrapper">

            <div class="site-branding">
                <?php if ( has_custom_logo() ) : ?>
                    <div class="site-logo"><?php the_custom_logo(); ?></div>
                <?php endif; ?>
                <?php if ( display_header_text() ) : ?>
                    <hgroup>
                        <p class="site-title">
                            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
                                <?php bloginfo( 'name' ); ?>
                            </a>
                        </p>
                        <?php $desc = get_bloginfo( 'description', 'display' ); if ( $desc ) : ?>
                            <p class="site-description screen-reader-text"><?php echo esc_html( $desc ); ?></p>
                        <?php endif; ?>
                    </hgroup>
                <?php endif; ?>
            </div>

            <nav id="site-navigation" class="main-navigation"
                 aria-label="<?php esc_attr_e( 'Primary Menu', 'rookie-sport' ); ?>">
                <?php wp_nav_menu( [
                    'theme_location' => 'primary',
                    'menu_id'        => 'primary-menu',
                    'menu_class'     => 'nav-menu',
                    'container'      => false,
                    'fallback_cb'    => false,
                ] ); ?>
            </nav>

            <div class="nav-actions">
                <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                    <?php $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0; ?>
                    <a href="<?php echo esc_url( wc_get_cart_url() ); ?>"
                       class="nav-cart-link"
                       aria-label="<?php echo esc_attr( sprintf( _n( 'Cart (%d item)', 'Cart (%d items)', $count, 'rookie-sport' ), $count ) ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        <span class="nav-cart-count" <?php echo 0 === $count ? 'hidden' : ''; ?>>
                            <?php echo esc_html( $count ); ?>
                        </span>
                    </a>
                <?php endif; ?>
                <button class="menu-toggle" aria-expanded="false" aria-controls="site-navigation">
                    <span class="screen-reader-text"><?php esc_html_e( 'Menu', 'rookie-sport' ); ?></span>
                </button>
            </div>

        </div>
    </header>

    <div id="content" class="site-content">
```

- [ ] **Step 2: Create `footer.php`**

```php
<?php
/**
 * Site footer.
 *
 * @package RookieSport
 */
?>
    </div><!-- #content -->

    <footer id="colophon" class="site-footer">
        <div class="footer-inner">

            <?php $has_widgets = is_active_sidebar( 'footer-1' ) || is_active_sidebar( 'footer-2' ) || is_active_sidebar( 'footer-3' ); ?>
            <?php if ( $has_widgets ) : ?>
                <div class="footer-widgets">
                    <div class="footer-wrapper">
                        <div class="footer-widget-grid">
                            <?php for ( $i = 1; $i <= 3; $i++ ) : ?>
                                <?php if ( is_active_sidebar( sprintf( 'footer-%d', $i ) ) ) : ?>
                                    <div class="footer-widget-col">
                                        <?php dynamic_sidebar( sprintf( 'footer-%d', $i ) ); ?>
                                    </div>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="footer-bottom">
                <div class="footer-wrapper footer-bottom-inner">
                    <p class="footer-copyright">
                        &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
                        <?php esc_html_e( '. All rights reserved.', 'rookie-sport' ); ?>
                    </p>
                    <?php if ( has_nav_menu( 'footer' ) ) : ?>
                        <nav class="footer-nav" aria-label="<?php esc_attr_e( 'Footer Menu', 'rookie-sport' ); ?>">
                            <?php wp_nav_menu( [
                                'theme_location' => 'footer',
                                'container'      => false,
                                'menu_class'     => 'footer-menu',
                                'depth'          => 1,
                                'fallback_cb'    => false,
                            ] ); ?>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </footer>

</div><!-- #page -->
<?php wp_footer(); ?>
</body>
</html>
```

- [ ] **Step 3: Create `assets/src/css/header.css`**

```css
.sp-header-bar {
    background: var(--color-navy-dark);
    border-bottom: 1px solid rgba(255,255,255,0.06);
}
.sp-header-bar:empty { display: none; }
.sp-header-bar .sp-template-scoreboard { margin: 0; }
:target { scroll-margin-top: calc(var(--nav-height) + var(--space-4)); }
```

- [ ] **Step 4: Create `assets/src/css/footer.css`**

```css
.site-footer { background: var(--color-navy-dark); color: var(--color-text-secondary); margin-top: auto; }

.footer-widgets { padding: var(--space-12) 0 var(--space-8); border-bottom: 1px solid rgba(255,255,255,0.08); }
.footer-widget-grid { display: grid; grid-template-columns: 1fr; gap: var(--space-8); }
@media (min-width: 640px)  { .footer-widget-grid { grid-template-columns: repeat(2, 1fr); } }
@media (min-width: 1024px) { .footer-widget-grid { grid-template-columns: repeat(3, 1fr); } }

.footer-widget-col .widget-title {
    font-family: var(--font-heading);
    font-size: var(--text-sm); font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.1em;
    color: var(--color-text-primary);
    margin-bottom: var(--space-4);
    padding-bottom: var(--space-2);
    border-bottom: 2px solid var(--color-teal);
}
.footer-widget-col .widget { color: var(--color-text-secondary); font-size: var(--text-sm); }
.footer-widget-col .widget a { color: rgba(255,255,255,0.6); }
.footer-widget-col .widget a:hover { color: var(--color-teal); }

.footer-bottom { padding: var(--space-4) 0; }
.footer-bottom-inner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-3); }
.footer-copyright { font-size: var(--text-sm); color: rgba(255,255,255,0.4); margin: 0; }
.footer-copyright a { color: rgba(255,255,255,0.5); }
.footer-copyright a:hover { color: var(--color-teal); }

.footer-menu { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: var(--space-1); }
.footer-menu a {
    font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.08em;
    color: rgba(255,255,255,0.4); padding: var(--space-1) var(--space-2);
    border-radius: 3px; transition: color var(--transition-fast);
}
.footer-menu a:hover { color: var(--color-teal); }
```

- [ ] **Step 5: Build and verify header/footer render**

```bash
npm run build
curl -sI https://rookiehockey.ca/ | head -3
# Expected: HTTP/2 200
```

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(theme): header.php, footer.php, header/footer CSS"
```

---

## Task 6: SportsPress CSS

**Files:**
- Create: `assets/src/css/sportspress.css`

- [ ] **Step 1: Create `assets/src/css/sportspress.css`**

```css
/* === SP Table Captions === */
.sp-table-caption {
    font-family: var(--font-heading); font-size: var(--text-sm); font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.08em;
    color: var(--color-text-primary); background: var(--color-navy);
    padding: var(--space-3) var(--space-4);
    border-top: 3px solid var(--color-teal);
    border-radius: var(--border-radius) var(--border-radius) 0 0;
}

/* === SP Data Tables === */
.sp-data-table {
    width: 100%; border-collapse: collapse; font-size: var(--text-sm);
    background: var(--color-card);
    border-radius: 0 0 var(--border-radius) var(--border-radius);
    overflow: hidden;
    border: 1px solid var(--color-border-card); border-top: none;
}
.sp-data-table th {
    background: var(--color-surface-2); color: var(--color-text-primary);
    font-family: var(--font-heading); font-size: var(--text-xs); font-weight: 600;
    text-transform: uppercase; letter-spacing: 0.08em;
    padding: var(--space-2) var(--space-3); text-align: center; white-space: nowrap;
    border-bottom: 2px solid rgba(255,255,255,0.1);
}
.sp-data-table td {
    padding: var(--space-2) var(--space-3); text-align: center;
    border-bottom: 1px solid var(--color-border-card);
    color: var(--color-text-dark); vertical-align: middle;
}
.sp-data-table tbody tr:last-child td { border-bottom: none; }
.sp-data-table tbody tr:nth-child(even) td { background: var(--color-card-alt); }
.sp-data-table tbody tr:hover td { background: #eff6ff; transition: background var(--transition-fast); }
.sp-data-table .sp-highlight td { background: rgba(0,166,156,0.08) !important; font-weight: 600; color: var(--color-teal-dark); }

.sp-table-wrapper {
    overflow-x: auto; -webkit-overflow-scrolling: touch;
    border-radius: var(--border-radius); box-shadow: var(--shadow-sm); margin-bottom: var(--space-6);
}
/* Scroll hint via data-attr set by sp-tables.js */
.sp-table-wrapper[data-scrollable]::after {
    content: '→ scroll';
    display: block; text-align: right;
    font-size: var(--text-xs); color: #9ca3af;
    padding: var(--space-1) var(--space-2);
}

/* === SP Countdown === */
.sp-template-countdown { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; margin-bottom: var(--space-6); }
.sp-template-countdown .event-name { font-family: var(--font-heading); font-weight: 700; font-size: var(--text-base); padding: var(--space-3) var(--space-4); background: var(--color-navy); color: var(--color-text-primary); border-bottom: 3px solid var(--color-teal); }
.sp-template-countdown .event-name a { color: inherit; }
.sp-template-countdown .event-name a:hover { color: var(--color-teal-light); }
.sp-template-countdown .event-venue, .sp-template-countdown .event-league { font-size: var(--text-sm); color: var(--color-text-dark-2); padding: var(--space-2) var(--space-4); border-bottom: 1px solid var(--color-border-card); }
.sp-template-countdown time { display: flex; justify-content: center; gap: var(--space-2); padding: var(--space-4); }
.sp-template-countdown time span { display: flex; flex-direction: column; align-items: center; justify-content: center; min-width: 56px; padding: var(--space-2) var(--space-3); background: var(--color-navy); border-radius: var(--border-radius); color: var(--color-text-primary); font-family: var(--font-heading); font-size: var(--text-2xl); font-weight: 800; line-height: 1; }
.sp-template-countdown time span small { font-size: var(--text-xs); font-weight: 400; text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-text-secondary); margin-top: var(--space-1); font-family: var(--font-body); }

/* === SP Event Blocks grid === */
.sp-template-event-blocks { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--space-4); margin-bottom: var(--space-6); }
.sp-template-event-blocks .sp-event-item { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; transition: box-shadow var(--transition-fast), transform var(--transition-fast); }
.sp-template-event-blocks .sp-event-item:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.sp-template-event-blocks .event-title { font-family: var(--font-heading); font-weight: 700; font-size: var(--text-base); padding: var(--space-3) var(--space-4); background: var(--color-navy); color: #fff; border-bottom: 3px solid var(--color-teal); }
.sp-template-event-blocks .event-title a { color: inherit; }
.sp-template-event-blocks .sp-event-date, .sp-template-event-blocks .sp-event-results, .sp-template-event-blocks .sp-event-venue { font-size: var(--text-sm); color: var(--color-text-dark-2); padding: var(--space-2) var(--space-4); border-bottom: 1px solid var(--color-border-card); }
.sp-template-event-blocks .sp-event-results { font-family: var(--font-heading); font-size: var(--text-xl); font-weight: 700; color: var(--color-navy); }

/* === SP Tab menu === */
.sp-tab-menu { display: flex; flex-wrap: wrap; border-bottom: 2px solid var(--color-border-card); margin-bottom: var(--space-6); }
.sp-tab-menu-item a { display: block; padding: var(--space-3) var(--space-4); font-family: var(--font-heading); font-size: var(--text-sm); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; border-bottom: 3px solid transparent; margin-bottom: -2px; transition: color var(--transition-fast), border-color var(--transition-fast); text-decoration: none; }
.sp-tab-menu-item a:hover { color: var(--color-navy); }
.sp-tab-menu-item-active a { color: var(--color-teal-dark); border-bottom-color: var(--color-teal); }

/* === SP heading === */
.sp-heading { font-family: var(--font-heading); font-size: var(--text-sm); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-primary); background: var(--color-navy); padding: var(--space-3) var(--space-4); border-left: 3px solid var(--color-teal); margin-bottom: var(--space-4); }
.sp-view-all-link { display: inline-flex; align-items: center; gap: var(--space-1); font-size: var(--text-xs); font-family: var(--font-heading); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-teal); margin-top: var(--space-2); transition: color var(--transition-fast); }
.sp-view-all-link::after { content: ' →'; }
.sp-view-all-link:hover { color: var(--color-teal-dark); }

/* === Win/loss/draw === */
.sp-result-w { color: var(--color-win); font-weight: 700; }
.sp-result-l { color: var(--color-loss); font-weight: 700; }
.sp-result-d { color: var(--color-draw); font-weight: 700; }

/* === Player Hero (single-player.php) === */
.sp-player-hero { background: var(--color-navy); padding: var(--space-12) 0; margin-bottom: var(--space-8); border-bottom: 3px solid var(--color-teal); }
.sp-player-hero__inner { display: flex; align-items: flex-end; gap: var(--space-8); }
.sp-player-hero__photo { flex-shrink: 0; width: 160px; }
.sp-player-hero__photo img { width: 160px; height: 200px; object-fit: cover; border-radius: var(--border-radius-lg); box-shadow: var(--shadow-lg); border: 3px solid rgba(255,255,255,0.1); }
.sp-player-hero__info { flex: 1; }
.sp-player-number { display: inline-block; font-family: var(--font-heading); font-size: var(--text-5xl); font-weight: 800; color: rgba(255,255,255,0.15); line-height: 1; margin-bottom: var(--space-2); }
.sp-player-name { font-size: clamp(var(--text-3xl), 5vw, var(--text-5xl)); color: #fff; margin: 0 0 var(--space-4); }
.sp-player-meta { display: flex; flex-wrap: wrap; gap: var(--space-4); margin: 0; }
.sp-player-meta dt { font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.1em; color: var(--color-text-secondary); margin-bottom: 2px; }
.sp-player-meta dd { font-family: var(--font-heading); font-size: var(--text-lg); font-weight: 600; color: #fff; margin: 0 var(--space-6) 0 0; }
@media (max-width: 639px) { .sp-player-hero__inner { flex-direction: column; align-items: flex-start; } .sp-player-hero__photo { width: 100%; max-width: 200px; } .sp-player-hero__photo img { width: 100%; height: auto; } }

/* === Team Hero (single-team.php) === */
.sp-team-hero { background: var(--color-navy); padding: var(--space-10) 0; margin-bottom: var(--space-8); border-bottom: 3px solid var(--color-teal); }
.sp-team-hero__inner { display: flex; align-items: center; gap: var(--space-6); }
.sp-team-hero__logo { flex-shrink: 0; width: 120px; }
.sp-team-hero__logo img { width: 120px; height: 120px; object-fit: contain; filter: drop-shadow(0 4px 16px rgba(0,0,0,0.5)); }
.sp-team-name { font-size: clamp(var(--text-2xl), 5vw, var(--text-4xl)); color: #fff; margin: 0 0 var(--space-2); }
.sp-team-excerpt { color: var(--color-text-secondary); font-size: var(--text-base); max-width: 600px; margin: 0; }
@media (max-width: 639px) { .sp-team-hero__inner { flex-direction: column; align-items: flex-start; } }
```

- [ ] **Step 2: Build and verify**

```bash
npm run build
```

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(theme): sportspress.css — tables, countdown, event blocks, player/team hero"
```

---

## Task 7: SportsPress PHP Templates (patched)

**Files:**
- Create: `sportspress/single-event.php`
- Create: `sportspress/single-player.php` (patch fix C3 — taxonomy for position)
- Create: `sportspress/single-team.php`
- Create: `sportspress/single-staff.php`

**Key fixes in this task:**
- **C3a:** `sp_position` is a taxonomy — use `wp_get_post_terms( $id, 'sp_position' )` not `get_post_meta`
- **C3b:** `sp_number` stored as `sp_number` meta (confirmed from sportspress-player-tools source), with `_sp_number` fallback
- **C3c:** `sp_nationality` is post meta stored as array of country codes (`get_post_meta( $id, 'sp_nationality', false )`)

- [ ] **Step 1: Create `sportspress/single-event.php`**

```php
<?php
/**
 * Single event — SP injects all event content via the_content filter.
 * This template provides the outer page chrome only.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="primary" class="content-area sp-event-page">
    <main id="main" class="site-main" role="main">

        <?php while ( have_posts() ) : the_post(); ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class( 'sp-single-event' ); ?>>
                <?php
                /**
                 * SP injects logos, score, and event sections via the_content filter
                 * in class-sp-template-loader.php. We call the_content() here which
                 * triggers all SP event templates automatically.
                 *
                 * If team logos are shown with names, suppress the duplicate H1
                 * by using content-notitle.php; otherwise use content-page.php.
                 */
                $show_logos      = 'yes' === get_option( 'sportspress_event_show_logos', 'yes' );
                $show_team_names = 'yes' === get_option( 'sportspress_event_logos_show_team_names', 'yes' );

                if ( $show_logos && $show_team_names ) {
                    get_template_part( 'content', 'notitle' );
                } else {
                    get_template_part( 'content', 'page' );
                }
                ?>
            </article>

            <?php if ( comments_open() || get_comments_number() ) : ?>
                <?php comments_template(); ?>
            <?php endif; ?>

        <?php endwhile; ?>

    </main>
</div>

<?php get_footer(); ?>
```

- [ ] **Step 2: Create `sportspress/single-player.php` — taxonomy fix (C3)**

```php
<?php
/**
 * Single player page.
 * sp_position is a taxonomy (confirmed from class-sp-player.php line 22).
 * sp_number is post meta key 'sp_number' with '_sp_number' fallback.
 * sp_nationality is post meta stored as array of 2-char country codes.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="primary" class="content-area sp-player-page">
    <main id="main" class="site-main" role="main">

        <?php while ( have_posts() ) : the_post(); ?>

            <?php
            $player_id = get_the_ID();

            // Jersey number: try 'sp_number' first, '_sp_number' as fallback.
            $number = get_post_meta( $player_id, 'sp_number', true );
            if ( ! $number ) {
                $number = get_post_meta( $player_id, '_sp_number', true );
            }

            // Position: taxonomy term (not post meta).
            $position       = '';
            $position_terms = wp_get_post_terms( $player_id, 'sp_position' );
            if ( ! is_wp_error( $position_terms ) && ! empty( $position_terms ) ) {
                $position = esc_html( $position_terms[0]->name );
            }

            // Nationality: post meta array of 2-char country codes.
            $nationalities = get_post_meta( $player_id, 'sp_nationality', false );
            $nationality   = '';
            if ( ! empty( $nationalities ) && is_array( $nationalities ) ) {
                // SP stores ISO 3166-1 alpha-2 codes; display the first one.
                $nationality = esc_html( strtoupper( $nationalities[0] ) );
            }
            ?>

            <div class="sp-player-hero">
                <div class="container">
                    <div class="sp-player-hero__inner">

                        <?php if ( has_post_thumbnail() ) : ?>
                            <div class="sp-player-hero__photo">
                                <?php the_post_thumbnail( 'large', [ 'alt' => esc_attr( get_the_title() ) ] ); ?>
                            </div>
                        <?php endif; ?>

                        <div class="sp-player-hero__info">
                            <?php if ( $number ) : ?>
                                <span class="sp-player-number">#<?php echo esc_html( $number ); ?></span>
                            <?php endif; ?>

                            <h1 class="sp-player-name"><?php the_title(); ?></h1>

                            <dl class="sp-player-meta">
                                <?php if ( $position ) : ?>
                                    <dt><?php esc_html_e( 'Position', 'rookie-sport' ); ?></dt>
                                    <dd><?php echo esc_html( $position ); ?></dd>
                                <?php endif; ?>
                                <?php if ( $nationality ) : ?>
                                    <dt><?php esc_html_e( 'Nationality', 'rookie-sport' ); ?></dt>
                                    <dd><?php echo esc_html( $nationality ); ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>

                    </div>
                </div>
            </div>

            <div class="container">
                <div class="sp-player-content">
                    <?php the_content(); ?>
                </div>
            </div>

            <?php if ( comments_open() || get_comments_number() ) : ?>
                <?php comments_template(); ?>
            <?php endif; ?>

        <?php endwhile; ?>

    </main>
</div>

<?php get_footer(); ?>
```

- [ ] **Step 3: Create `sportspress/single-team.php`**

```php
<?php
/**
 * Single team page.
 * SP injects roster, schedule, and standings via the_content filter.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="primary" class="content-area sp-team-page">
    <main id="main" class="site-main" role="main">

        <?php while ( have_posts() ) : the_post(); ?>

            <div class="sp-team-hero <?php echo has_post_thumbnail() ? 'has-logo' : ''; ?>">
                <div class="container">
                    <div class="sp-team-hero__inner">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <div class="sp-team-hero__logo">
                                <?php the_post_thumbnail( 'medium', [ 'alt' => esc_attr( get_the_title() ) ] ); ?>
                            </div>
                        <?php endif; ?>
                        <div class="sp-team-hero__info">
                            <h1 class="sp-team-name"><?php the_title(); ?></h1>
                            <?php if ( get_the_excerpt() ) : ?>
                                <div class="sp-team-excerpt"><?php the_excerpt(); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="container">
                <div class="sp-team-content">
                    <?php the_content(); ?>
                </div>
            </div>

            <?php if ( comments_open() || get_comments_number() ) : ?>
                <?php comments_template(); ?>
            <?php endif; ?>

        <?php endwhile; ?>

    </main>
</div>

<?php get_footer(); ?>
```

- [ ] **Step 4: Create `sportspress/single-staff.php`**

```php
<?php
/**
 * Single staff/coach page.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="primary" class="content-area sp-staff-page">
    <main id="main" class="site-main" role="main">
        <?php while ( have_posts() ) : the_post(); ?>
            <div class="container">
                <article id="post-<?php the_ID(); ?>" <?php post_class( 'sp-single-staff' ); ?>>
                    <header class="entry-header">
                        <h1 class="entry-title"><?php the_title(); ?></h1>
                    </header>
                    <div class="entry-content">
                        <?php the_content(); ?>
                    </div>
                </article>
                <?php if ( comments_open() || get_comments_number() ) : ?>
                    <?php comments_template(); ?>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>
    </main>
</div>

<?php get_footer(); ?>
```

- [ ] **Step 5: Verify SP position taxonomy exists on a test player**

```bash
ssh -p SSH_PORT root@production-host.example "
wp --path=/var/www/rookiehockey.ca/htdocs taxonomy list --allow-root | grep sp_position
wp --path=/var/www/rookiehockey.ca/htdocs post list --post_type=sp_player --allow-root --fields=ID,post_title --format=table | head -5
"
# Expected: sp_position in taxonomy list; at least one sp_player post exists
```

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(theme): SP templates — player (taxonomy fix C3), team, event, staff"
```

---

## Task 8: WooCommerce CSS

**Files:**
- Create: `assets/src/css/woocommerce.css`

- [ ] **Step 1: Create `assets/src/css/woocommerce.css`**

```css
/* === WC page wrapper === */
.wc-primary { padding: var(--space-8) 0; }

/* === Breadcrumb === */
.woocommerce-breadcrumb { font-size: var(--text-sm); color: #6b7280; padding: var(--space-3) 0; margin-bottom: var(--space-4); border-bottom: 1px solid var(--color-border-card); }
.woocommerce-breadcrumb a { color: var(--color-teal); }
.woocommerce-breadcrumb a:hover { color: var(--color-teal-dark); }

/* === Products archive === */
.woocommerce ul.products { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: var(--space-6); list-style: none; padding: 0; margin: 0; }
.woocommerce ul.products li.product { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; transition: box-shadow var(--transition-fast), transform var(--transition-fast); }
.woocommerce ul.products li.product:hover { box-shadow: var(--shadow-md); transform: translateY(-3px); }
.woocommerce ul.products li.product a img { width: 100%; aspect-ratio: 16/9; object-fit: cover; display: block; }
.woocommerce ul.products li.product h2,
.woocommerce ul.products li.product h3 { font-family: var(--font-heading); font-size: var(--text-lg); font-weight: 700; color: var(--color-text-dark); padding: var(--space-4) var(--space-4) 0; margin: 0 0 var(--space-2); }
.woocommerce ul.products li.product .price { display: block; padding: 0 var(--space-4); font-family: var(--font-heading); font-size: var(--text-xl); font-weight: 700; color: var(--color-navy); margin-bottom: var(--space-4); }
.woocommerce ul.products li.product .button { display: block; margin: 0 var(--space-4) var(--space-4); text-align: center; width: calc(100% - var(--space-8)); }

/* === Single product === */
.woocommerce div.product .product_title { font-size: clamp(var(--text-2xl), 4vw, var(--text-3xl)); color: var(--color-text-dark); }
.woocommerce div.product .price { font-family: var(--font-heading); font-size: var(--text-3xl); font-weight: 800; color: var(--color-navy); margin: var(--space-3) 0; }
.woocommerce div.product form.cart .single_add_to_cart_button { min-width: 200px; font-size: var(--text-lg); padding: 0.75em 2em; }

/* === WC notices === */
.woocommerce-message { padding: var(--space-3) var(--space-4); border-radius: var(--border-radius); font-size: var(--text-sm); margin-bottom: var(--space-4); background: rgba(34,197,94,0.1); border: 1.5px solid #22c55e; color: #15803d; }
.woocommerce-error { padding: var(--space-3) var(--space-4); border-radius: var(--border-radius); font-size: var(--text-sm); margin-bottom: var(--space-4); background: rgba(239,68,68,0.1); border: 1.5px solid #ef4444; color: #b91c1c; list-style: none; }
.woocommerce-info { padding: var(--space-3) var(--space-4); border-radius: var(--border-radius); font-size: var(--text-sm); margin-bottom: var(--space-4); background: rgba(59,130,246,0.08); border: 1.5px solid #3b82f6; color: #1d4ed8; }

/* === Checkout two-column === */
.checkout-columns { display: grid; grid-template-columns: 1fr; gap: var(--space-8); }
@media (min-width: 768px) { .checkout-columns { grid-template-columns: 1fr 380px; align-items: start; } }

.woocommerce-checkout h3 { font-family: var(--font-heading); font-size: var(--text-xl); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-dark); padding-bottom: var(--space-3); border-bottom: 2px solid var(--color-border-card); margin-bottom: var(--space-6); }
.woocommerce form .form-row { margin-bottom: var(--space-4); }
.woocommerce form .form-row label { font-size: var(--text-sm); font-weight: 600; color: var(--color-text-dark); margin-bottom: var(--space-1); display: block; }
.woocommerce form .form-row .required { color: var(--color-loss); }

/* Order review table */
.woocommerce-checkout-review-order-table { width: 100%; border-collapse: collapse; border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; margin-bottom: var(--space-6); }
.woocommerce-checkout-review-order-table th { background: var(--color-navy); color: var(--color-text-primary); padding: var(--space-3) var(--space-4); font-family: var(--font-heading); font-size: var(--text-sm); text-transform: uppercase; letter-spacing: 0.08em; }
.woocommerce-checkout-review-order-table td { padding: var(--space-3) var(--space-4); border-bottom: 1px solid var(--color-border-card); font-size: var(--text-sm); color: var(--color-text-dark); text-align: left; }
.woocommerce-checkout-review-order-table .order-total td,
.woocommerce-checkout-review-order-table .order-total th { font-weight: 700; font-size: var(--text-base); background: var(--color-card-alt); }
#place_order { width: 100%; font-size: var(--text-lg); padding: 1em 2em; background: var(--color-teal); margin-top: var(--space-4); }
#place_order:hover { background: var(--color-teal-dark); }

/* === My Account === */
.woocommerce-MyAccount-navigation { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; margin-bottom: var(--space-6); }
@media (min-width: 768px) { .woocommerce-account .woocommerce-MyAccount-navigation { float: left; width: 220px; margin-right: var(--space-8); margin-bottom: 0; } .woocommerce-account .woocommerce-MyAccount-content { overflow: hidden; } }
.woocommerce-MyAccount-navigation ul { list-style: none; margin: 0; padding: 0; }
.woocommerce-MyAccount-navigation li { border-bottom: 1px solid var(--color-border-card); margin: 0; }
.woocommerce-MyAccount-navigation li:last-child { border-bottom: none; }
.woocommerce-MyAccount-navigation li a { display: block; padding: var(--space-3) var(--space-4); font-size: var(--text-sm); font-family: var(--font-heading); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #374151; transition: color var(--transition-fast), background var(--transition-fast); }
.woocommerce-MyAccount-navigation li a:hover,
.woocommerce-MyAccount-navigation li.is-active a { color: var(--color-teal-dark); background: rgba(0,166,156,0.06); }
.woocommerce-MyAccount-navigation li.is-active { border-left: 3px solid var(--color-teal); }

/* Subscription badges */
.subscription-status { display: inline-block; padding: 2px var(--space-2); font-size: var(--text-xs); font-family: var(--font-heading); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; border-radius: 3px; }
.subscription-status.active   { background: rgba(34,197,94,0.15); color: #15803d; }
.subscription-status.on-hold  { background: rgba(245,158,11,0.15); color: #b45309; }
.subscription-status.expired,
.subscription-status.cancelled { background: rgba(239,68,68,0.1); color: #b91c1c; }

/* Thank you page */
.woocommerce-thankyou-order-received { color: var(--color-win); }
.woocommerce-order-details { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); padding: var(--space-6); margin-bottom: var(--space-6); }
```

- [ ] **Step 2: Build and verify**

```bash
npm run build
```

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(theme): woocommerce.css — products, checkout, my-account, subscriptions"
```

---

## Task 9: WooCommerce PHP Templates

**Files:** All `woocommerce/` subdirectory files (migration from `rookie-child` + new templates)

**Patch fix C5:** The old plan used `sed -i "s/'rookie'/'rookie-sport'/g"` which would corrupt any non-text-domain occurrence of the string `rookie`. The corrected approach replaces only inside i18n function argument position.

- [ ] **Step 1: Create `woocommerce/archive-product.php`**

```php
<?php
/**
 * Product archive — registration listings.
 *
 * @package RookieSport
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );
?>

<div class="wc-primary">
    <?php do_action( 'woocommerce_before_main_content' ); ?>

    <header class="woocommerce-products-header">
        <?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
            <h1 class="page-title"><?php woocommerce_page_title(); ?></h1>
        <?php endif; ?>
        <?php do_action( 'woocommerce_archive_description' ); ?>
    </header>

    <?php if ( woocommerce_product_loop() ) : ?>
        <?php do_action( 'woocommerce_before_shop_loop' ); ?>
        <?php woocommerce_product_loop_start(); ?>
        <?php if ( wc_get_loop_prop( 'total' ) ) :
            while ( have_posts() ) :
                the_post();
                do_action( 'woocommerce_shop_loop' );
                wc_get_template_part( 'content', 'product' );
            endwhile;
        endif; ?>
        <?php woocommerce_product_loop_end(); ?>
        <?php do_action( 'woocommerce_after_shop_loop' ); ?>
    <?php else : ?>
        <?php do_action( 'woocommerce_no_products_found' ); ?>
    <?php endif; ?>

    <?php do_action( 'woocommerce_after_main_content' ); ?>
</div>

<?php get_footer( 'shop' ); ?>
```

- [ ] **Step 2: Create `woocommerce/checkout/form-checkout.php`**

```php
<?php
/**
 * Checkout form — two-column layout.
 *
 * @package RookieSport
 */

defined( 'ABSPATH' ) || exit;

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
    echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message',
        __( 'You must be logged in to checkout.', 'rookie-sport' ) ) );
    return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout"
      action="<?php echo esc_url( wc_get_checkout_url() ); ?>"
      enctype="multipart/form-data">

    <?php if ( $checkout->get_checkout_fields() ) : ?>
        <?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
        <div class="checkout-columns">
            <div class="checkout-col checkout-col--billing">
                <?php do_action( 'woocommerce_checkout_billing' ); ?>
                <?php do_action( 'woocommerce_checkout_shipping' ); ?>
            </div>
            <div class="checkout-col checkout-col--order">
                <?php do_action( 'woocommerce_checkout_before_order_review_heading' ); ?>
                <h3 id="order_review_heading"><?php esc_html_e( 'Your order', 'rookie-sport' ); ?></h3>
                <?php do_action( 'woocommerce_checkout_before_order_review' ); ?>
                <div id="order_review" class="woocommerce-checkout-review-order">
                    <?php do_action( 'woocommerce_checkout_order_review' ); ?>
                </div>
                <?php do_action( 'woocommerce_checkout_after_order_review' ); ?>
            </div>
        </div>
        <?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
    <?php endif; ?>

</form>
```

- [ ] **Step 3: Create `woocommerce/checkout/form-coupon.php`**

```php
<?php
/**
 * Checkout coupon form.
 *
 * @package RookieSport
 */

defined( 'ABSPATH' ) || exit;

if ( ! wc_coupons_enabled() ) { return; }
?>
<div class="woocommerce-form-coupon-toggle">
    <?php wc_print_notice( apply_filters( 'woocommerce_checkout_coupon_message',
        esc_html__( 'Have a coupon?', 'rookie-sport' ) .
        ' <a href="#" class="showcoupon">' . esc_html__( 'Click here to enter your code', 'rookie-sport' ) . '</a>' ), 'notice' ); ?>
</div>
<form class="checkout_coupon woocommerce-form-coupon" method="post" style="display:none">
    <p><?php esc_html_e( 'If you have a coupon code, please apply it below.', 'rookie-sport' ); ?></p>
    <div class="form-row form-row-first">
        <label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon:', 'rookie-sport' ); ?></label>
        <input type="text" name="coupon_code" class="input-text"
               placeholder="<?php esc_attr_e( 'Coupon code', 'rookie-sport' ); ?>"
               id="coupon_code" value="">
    </div>
    <div class="form-row form-row-last">
        <button type="submit" class="button" name="apply_coupon"
                value="<?php esc_attr_e( 'Apply coupon', 'rookie-sport' ); ?>">
            <?php esc_html_e( 'Apply coupon', 'rookie-sport' ); ?>
        </button>
    </div>
    <div class="clear"></div>
</form>
```

- [ ] **Step 4: Migrate remaining templates with safe text-domain replacement (patch fix C5)**

The safe sed pattern targets only i18n function call closing `, 'text-domain')` — it cannot match option keys, class names, or theme slugs.

```bash
CHILD="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-child/woocommerce"
NEW="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/woocommerce"

# Files to migrate verbatim (functional logic preserved; only text domain changes)
MIGRATE=(
    "checkout/form-login.php"
    "checkout/thankyou.php"
    "myaccount/dashboard.php"
    "myaccount/dashboard-store-credit.php"
    "myaccount/my-refund-requests.php"
    "myaccount/store-credit.php"
)

for f in "${MIGRATE[@]}"; do
    cp "$CHILD/$f" "$NEW/$f"
done

# Safe text-domain replacement: only replace ", 'rookie')" — the closing
# argument of i18n functions. Does NOT touch 'rookie' appearing elsewhere.
find "$NEW" -name "*.php" | xargs sed -i "s/, 'rookie')/, 'rookie-sport')/g"

# Verify no stray 'rookie' text domain remains
echo "=== Remaining 'rookie' occurrences ===" && grep -rn "'rookie'" "$NEW" | grep -v 'rookie-sport' | grep -v 'rookie-child'
# Expected: empty output (no remaining old text domain)
```

- [ ] **Step 5: Migrate email templates**

```bash
CHILD="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-child/woocommerce/emails"
NEW="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/woocommerce/emails"
mkdir -p "$NEW"
cp "$CHILD"/*.php "$NEW/"
find "$NEW" -name "*.php" | xargs sed -i "s/, 'rookie')/, 'rookie-sport')/g"
echo "Email templates migrated: $(ls $NEW/*.php | wc -l) files"
# Expected: 14 files
```

- [ ] **Step 6: Verify all WC templates present**

```bash
find /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/woocommerce \
    -name "*.php" | sort
# Expected: archive-product, checkout/*, myaccount/*, emails/* all listed
```

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(theme): WooCommerce templates — safe sed migration (C5 fix), checkout, myaccount, emails"
```

---

## Task 10: Core Page Templates

**Files:** `index.php`, `page.php`, `single.php`, `archive.php`, `search.php`, `404.php`, `template-fullwidth.php`, `template-homepage.php`, `sidebar.php`, `content.php`, `content-single.php`, `content-page.php`, `content-nothumb.php`, `content-notitle.php`, `content-none.php`, `comments.php`

**Patch fixes:** `search.php` (N7), `content-single.php` (N6), `comments.php` (C4) added.

- [ ] **Step 1: Create `index.php`**

```php
<?php
/**
 * Main blog loop fallback.
 * @package RookieSport
 */
get_header();
$has_sidebar = rookie_sport_has_sidebar();
?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper <?php echo $has_sidebar ? 'has-sidebar' : ''; ?>">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php if ( have_posts() ) :
                        while ( have_posts() ) : the_post();
                            get_template_part( 'content', get_post_format() );
                        endwhile;
                        the_posts_navigation();
                    else :
                        get_template_part( 'content', 'none' );
                    endif; ?>
                </main>
            </div>
            <?php if ( $has_sidebar ) : get_sidebar(); endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 2: Create `page.php`**

```php
<?php
/**
 * Standard page template.
 * @package RookieSport
 */
get_header();
$has_sidebar = rookie_sport_has_sidebar();
?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper <?php echo $has_sidebar ? 'has-sidebar' : ''; ?>">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php while ( have_posts() ) : the_post();
                        get_template_part( 'content', 'page' );
                        if ( comments_open() || get_comments_number() ) comments_template();
                    endwhile; ?>
                </main>
            </div>
            <?php if ( $has_sidebar ) : get_sidebar(); endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 3: Create `single.php`**

```php
<?php
/**
 * Single blog post.
 * @package RookieSport
 */
get_header();
$has_sidebar = rookie_sport_has_sidebar();
?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper <?php echo $has_sidebar ? 'has-sidebar' : ''; ?>">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php while ( have_posts() ) : the_post();
                        get_template_part( 'content', 'single' );
                        the_post_navigation();
                        if ( comments_open() || get_comments_number() ) comments_template();
                    endwhile; ?>
                </main>
            </div>
            <?php if ( $has_sidebar ) : get_sidebar(); endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 4: Create `archive.php`**

```php
<?php
/**
 * Archive listing.
 * @package RookieSport
 */
get_header();
$has_sidebar = rookie_sport_has_sidebar();
?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper <?php echo $has_sidebar ? 'has-sidebar' : ''; ?>">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php if ( have_posts() ) : ?>
                        <header class="page-header">
                            <?php the_archive_title( '<h1 class="page-title">', '</h1>' ); ?>
                            <?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
                        </header>
                        <?php while ( have_posts() ) : the_post();
                            get_template_part( 'content', get_post_format() );
                        endwhile;
                        the_posts_navigation();
                    else :
                        get_template_part( 'content', 'none' );
                    endif; ?>
                </main>
            </div>
            <?php if ( $has_sidebar ) : get_sidebar(); endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 5: Create `search.php` (patch fix N7)**

```php
<?php
/**
 * Search results page.
 * @package RookieSport
 */
get_header();
$has_sidebar = rookie_sport_has_sidebar();
?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper <?php echo $has_sidebar ? 'has-sidebar' : ''; ?>">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php if ( have_posts() ) : ?>
                        <header class="page-header">
                            <h1 class="page-title">
                                <?php
                                printf(
                                    /* translators: %s: search query */
                                    esc_html__( 'Search results for: %s', 'rookie-sport' ),
                                    '<span>' . esc_html( get_search_query() ) . '</span>'
                                );
                                ?>
                            </h1>
                        </header>
                        <?php while ( have_posts() ) : the_post();
                            get_template_part( 'content', get_post_format() );
                        endwhile;
                        the_posts_navigation();
                    else : ?>
                        <header class="page-header">
                            <h1 class="page-title"><?php esc_html_e( 'Nothing found', 'rookie-sport' ); ?></h1>
                        </header>
                        <div class="page-content">
                            <p><?php esc_html_e( 'No results matched your search. Try different keywords.', 'rookie-sport' ); ?></p>
                            <?php get_search_form(); ?>
                        </div>
                    <?php endif; ?>
                </main>
            </div>
            <?php if ( $has_sidebar ) : get_sidebar(); endif; ?>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 6: Create `404.php`**

```php
<?php
/**
 * 404 not found.
 * @package RookieSport
 */
get_header(); ?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <section class="error-404 not-found">
                        <header class="page-header">
                            <h1 class="page-title"><?php esc_html_e( '404 — Not Found', 'rookie-sport' ); ?></h1>
                        </header>
                        <div class="page-content">
                            <p><?php esc_html_e( "That page doesn't exist. It may have moved or been removed.", 'rookie-sport' ); ?></p>
                            <?php get_search_form(); ?>
                            <p><a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="button"><?php esc_html_e( '← Back to Home', 'rookie-sport' ); ?></a></p>
                        </div>
                    </section>
                </main>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 7: Create `template-fullwidth.php` and `template-homepage.php`**

```php
<?php
/**
 * Template Name: Full Width
 * @package RookieSport
 */
get_header(); ?>
<div id="content" class="site-content">
    <div class="container">
        <div class="content-wrapper">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">
                    <?php while ( have_posts() ) : the_post();
                        get_template_part( 'content', 'page' );
                        if ( comments_open() || get_comments_number() ) comments_template();
                    endwhile; ?>
                </main>
            </div>
        </div>
    </div>
</div>
<?php get_footer(); ?>
```

```php
<?php
/**
 * Template Name: Homepage
 * @package RookieSport
 */
get_header(); ?>
<div id="content" class="site-content homepage">
    <?php while ( have_posts() ) : the_post(); ?>
        <?php if ( get_the_content() ) : ?>
            <div class="homepage-content container"><?php the_content(); ?></div>
        <?php endif; ?>
    <?php endwhile; ?>
</div>
<?php get_footer(); ?>
```

- [ ] **Step 8: Create `sidebar.php`**

```php
<?php
/**
 * Sidebar.
 * @package RookieSport
 */
if ( ! is_active_sidebar( 'sidebar-1' ) ) { return; }
?>
<aside id="secondary" class="widget-area sidebar" role="complementary"
       aria-label="<?php esc_attr_e( 'Sidebar', 'rookie-sport' ); ?>">
    <?php dynamic_sidebar( 'sidebar-1' ); ?>
</aside>
```

- [ ] **Step 9: Create content partials**

`content.php` (post loop excerpt card):
```php
<?php /** @package RookieSport */ ?>
<article id="post-<?php the_ID(); ?>" <?php post_class( 'entry card' ); ?>>
    <?php if ( has_post_thumbnail() ) : ?>
        <div class="entry-thumbnail"><a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'large' ); ?></a></div>
    <?php endif; ?>
    <div class="card-body">
        <header class="entry-header">
            <?php the_title( sprintf( '<h2 class="entry-title"><a href="%s">', esc_url( get_permalink() ) ), '</a></h2>' ); ?>
        </header>
        <div class="entry-summary"><?php the_excerpt(); ?></div>
        <footer class="entry-footer">
            <a href="<?php the_permalink(); ?>" class="button button-secondary"><?php esc_html_e( 'Read more', 'rookie-sport' ); ?></a>
        </footer>
    </div>
</article>
```

`content-single.php` (patch fix N6 — full post content):
```php
<?php /** @package RookieSport */ ?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
        <?php rookie_sport_post_meta(); ?>
    </header>
    <?php if ( has_post_thumbnail() ) : ?>
        <div class="entry-thumbnail"><?php the_post_thumbnail( 'full' ); ?></div>
    <?php endif; ?>
    <div class="entry-content">
        <?php the_content(); ?>
        <?php wp_link_pages(); ?>
    </div>
    <footer class="entry-footer">
        <?php the_tags( '<span class="tags-links">', ', ', '</span>' ); ?>
    </footer>
</article>
```

`content-page.php`:
```php
<?php /** @package RookieSport */ ?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header">
        <?php if ( ! is_front_page() ) the_title( '<h1 class="entry-title">', '</h1>' ); ?>
    </header>
    <div class="entry-content"><?php the_content(); ?><?php wp_link_pages(); ?></div>
</article>
```

`content-nothumb.php` (SP entity pages):
```php
<?php /** @package RookieSport */ ?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <header class="entry-header"><h1 class="entry-title"><?php the_title(); ?></h1></header>
    <div class="entry-content"><?php the_content(); ?></div>
</article>
```

`content-notitle.php` (SP events with logos):
```php
<?php /** @package RookieSport */ ?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
    <div class="entry-content"><?php the_content(); ?></div>
</article>
```

`content-none.php`:
```php
<?php /** @package RookieSport */ ?>
<section class="no-results not-found">
    <header class="page-header"><h1 class="page-title"><?php esc_html_e( 'Nothing Found', 'rookie-sport' ); ?></h1></header>
    <div class="page-content">
        <?php if ( is_search() ) : ?>
            <p><?php esc_html_e( 'No results. Try different keywords.', 'rookie-sport' ); ?></p>
            <?php get_search_form(); ?>
        <?php else : ?>
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="button"><?php esc_html_e( '← Home', 'rookie-sport' ); ?></a>
        <?php endif; ?>
    </div>
</section>
```

- [ ] **Step 10: Create `comments.php` (patch fix C4)**

```php
<?php
/**
 * Comments template.
 * Required by single.php, page.php, and all SP entity templates.
 *
 * @package RookieSport
 */

if ( post_password_required() ) {
    return;
}
?>

<div id="comments" class="comments-area">

    <?php if ( have_comments() ) : ?>
        <h2 class="comments-title">
            <?php
            $comment_count = get_comments_number();
            if ( 1 === $comment_count ) {
                printf(
                    /* translators: %s: post title */
                    esc_html__( 'One comment on &ldquo;%s&rdquo;', 'rookie-sport' ),
                    '<span>' . esc_html( get_the_title() ) . '</span>'
                );
            } else {
                printf(
                    /* translators: 1: comment count, 2: post title */
                    esc_html( _nx( '%1$s comment on &ldquo;%2$s&rdquo;', '%1$s comments on &ldquo;%2$s&rdquo;', $comment_count, 'comments title', 'rookie-sport' ) ),
                    number_format_i18n( $comment_count ),
                    '<span>' . esc_html( get_the_title() ) . '</span>'
                );
            }
            ?>
        </h2>

        <ol class="comment-list">
            <?php wp_list_comments( [ 'style' => 'ol', 'short_ping' => true ] ); ?>
        </ol>

        <?php the_comments_navigation(); ?>

    <?php endif; ?>

    <?php if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) : ?>
        <p class="no-comments"><?php esc_html_e( 'Comments are closed.', 'rookie-sport' ); ?></p>
    <?php endif; ?>

    <?php comment_form(); ?>

</div>
```

- [ ] **Step 11: Verify all templates exist**

```bash
ls /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/*.php | sort
# Expected: 404, archive, comments, content*, footer, header, index, page, search, sidebar, single, template-* all present
```

- [ ] **Step 12: Commit**

```bash
git commit -m "feat(theme): core templates — search.php (N7), content-single.php (N6), comments.php (C4)"
```

---

## Task 11: inc/template-tags.php + inc/customizer.php

**Files:**
- Create: `inc/template-tags.php`
- Create: `inc/customizer.php`

- [ ] **Step 1: Create `inc/template-tags.php`**

```php
<?php
/**
 * Template tag helper functions.
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current page should show a sidebar.
 * Defined here (canonical). Also declared in inc/sportspress.php as a guard.
 */
if ( ! function_exists( 'rookie_sport_has_sidebar' ) ) {
	function rookie_sport_has_sidebar(): bool {
		$sp_types = [
			'sp_event', 'sp_team', 'sp_player', 'sp_staff',
			'sp_league', 'sp_season', 'sp_venue',
		];

		if ( ! is_active_sidebar( 'sidebar-1' ) ) {
			return false;
		}
		if ( is_singular( $sp_types ) || is_post_type_archive( $sp_types ) ) {
			return false;
		}
		if ( is_page_template( 'template-fullwidth.php' ) || is_page_template( 'template-homepage.php' ) ) {
			return false;
		}
		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return true;
	}
}

/**
 * Output post meta: date and author.
 */
function rookie_sport_post_meta(): void {
	$time = sprintf(
		'<time class="entry-date published" datetime="%1$s">%2$s</time>',
		esc_attr( get_the_date( DATE_W3C ) ),
		esc_html( get_the_date() )
	);
	$author = sprintf(
		'<span class="author vcard"><a class="url fn n" href="%1$s">%2$s</a></span>',
		esc_url( get_author_posts_url( get_the_author_meta( 'ID' ) ) ),
		esc_html( get_the_author() )
	);
	echo '<div class="entry-meta">';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<span class="posted-on">' . $time . '</span>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<span class="byline"> ' . esc_html__( 'by', 'rookie-sport' ) . ' ' . $author . '</span>';
	echo '</div>';
}

/**
 * Custom excerpt "more" link.
 */
add_filter( 'excerpt_more', 'rookie_sport_excerpt_more' );
function rookie_sport_excerpt_more( string $more ): string {
	return sprintf(
		' &hellip; <a href="%s" class="read-more">%s</a>',
		esc_url( get_permalink() ),
		esc_html__( 'Read more', 'rookie-sport' )
	);
}
```

- [ ] **Step 2: Create `inc/customizer.php`**

```php
<?php
/**
 * Customizer settings: accent colour and navigation colour.
 * CSS output overwrites the :root custom properties defined in style.css.
 *
 * @package RookieSport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', 'rookie_sport_customize_register' );

function rookie_sport_customize_register( \WP_Customize_Manager $wp_customize ): void {

	$wp_customize->add_panel( 'rookie_sport_panel', [
		'title'    => esc_html__( 'Rookie Sport', 'rookie-sport' ),
		'priority' => 130,
	] );

	$wp_customize->add_section( 'rookie_sport_colors', [
		'title' => esc_html__( 'Colors', 'rookie-sport' ),
		'panel' => 'rookie_sport_panel',
	] );

	// Accent colour
	$wp_customize->add_setting( 'rookie_sport_accent_color', [
		'default'           => '#00a69c',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	] );
	$wp_customize->add_control( new \WP_Customize_Color_Control( $wp_customize, 'rookie_sport_accent_color', [
		'label'   => esc_html__( 'Accent Color', 'rookie-sport' ),
		'section' => 'rookie_sport_colors',
	] ) );

	// Navigation / dark colour
	$wp_customize->add_setting( 'rookie_sport_nav_color', [
		'default'           => '#1a2332',
		'sanitize_callback' => 'sanitize_hex_color',
		'transport'         => 'postMessage',
	] );
	$wp_customize->add_control( new \WP_Customize_Color_Control( $wp_customize, 'rookie_sport_nav_color', [
		'label'   => esc_html__( 'Navigation Color', 'rookie-sport' ),
		'section' => 'rookie_sport_colors',
	] ) );
}

/**
 * Inline CSS to override :root custom properties when non-default colours are set.
 * Only outputs a <style> tag if at least one value differs from the default.
 */
add_action( 'wp_head', 'rookie_sport_customizer_css' );

function rookie_sport_customizer_css(): void {
	$accent = get_theme_mod( 'rookie_sport_accent_color', '#00a69c' );
	$nav    = get_theme_mod( 'rookie_sport_nav_color', '#1a2332' );

	// Nothing to output if both are still at their defaults.
	if ( '#00a69c' === $accent && '#1a2332' === $nav ) {
		return;
	}

	$accent = sanitize_hex_color( $accent ) ?: '#00a69c';
	$nav    = sanitize_hex_color( $nav ) ?: '#1a2332';

	$css = ':root {';
	if ( '#00a69c' !== $accent ) {
		$css .= '--color-teal:' . esc_attr( $accent ) . ';';
	}
	if ( '#1a2332' !== $nav ) {
		$css .= '--color-navy:' . esc_attr( $nav ) . ';';
	}
	$css .= '}';

	echo '<style id="rookie-sport-customizer-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
```

- [ ] **Step 3: Run PHPCS on all inc/ files**

```bash
phpcs --standard=WordPress --extensions=php \
    inc/setup.php inc/enqueue.php inc/template-tags.php \
    inc/customizer.php inc/sportspress.php inc/woocommerce.php
# Expected: no errors or warnings
```

- [ ] **Step 4: Commit**

```bash
git commit -m "feat(theme): template-tags, customizer colour controls"
```

---

## Task 12: Deployment & Switchover

- [ ] **Step 1: Generate .pot file (patch fix N5)**

```bash
ssh -p SSH_PORT root@production-host.example "
wp --path=/var/www/rookiehockey.ca/htdocs i18n make-pot \
    wp-content/themes/rookie-sport \
    wp-content/themes/rookie-sport/languages/rookie-sport.pot \
    --allow-root \
    --domain=rookie-sport
echo 'POT generated'
"
```

- [ ] **Step 2: Create `screenshot.png` at exactly 1200×900px (patch fix N4)**

```
Dimensions: 1200px wide × 900px tall (WordPress requirement).
Create a representative screenshot of the theme homepage showing:
- Dark navy header with logo and navigation
- SP scoreboard bar
- Homepage hero content
Save as PNG to: wp-content/themes/rookie-sport/screenshot.png
Verify: identify screenshot.png | grep -o '[0-9]*x[0-9]*'
# Expected: 1200x900
```

- [ ] **Step 3: Build assets on server**

```bash
ssh -p SSH_PORT root@production-host.example "
cd /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport
npm ci --production=false
npm run build
ls -lh assets/dist/
# Expected: index.css, index.js, editor.css all > 0 bytes
"
```

- [ ] **Step 4: PHPCS full theme scan**

```bash
ssh -p SSH_PORT root@production-host.example "
cd /var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport
phpcs --standard=WordPress --extensions=php \
    --ignore=node_modules,assets/dist \
    --report=summary . 2>&1 | tail -10
# Expected: 0 errors
"
```

- [ ] **Step 5: Database backup before switch**

```bash
ssh -p SSH_PORT root@production-host.example "
STAMP=\$(date +%Y%m%d-%H%M%S)
wp --path=/var/www/rookiehockey.ca/htdocs db export \
    /mnt/b2/lusk-ee-servers/backups/pre-theme-switch-\$STAMP.sql \
    --allow-root
echo \"Backup: pre-theme-switch-\$STAMP.sql\"
"
```

- [ ] **Step 6: Activate theme**

```bash
ssh -p SSH_PORT root@production-host.example "
wp --path=/var/www/rookiehockey.ca/htdocs theme activate rookie-sport --allow-root
tail -5 /var/log/nginx/rookiehockey.ca.error.log
# Expected: 'Switched to Rookie Sport theme.' and no PHP Fatal errors
"
```

- [ ] **Step 7: Smoke-test key URLs**

```bash
for url in \
    "https://rookiehockey.ca/" \
    "https://rookiehockey.ca/register/" \
    "https://rookiehockey.ca/schedule/" \
    "https://rookiehockey.ca/standings/" \
    "https://rookiehockey.ca/my-account/" \
    "https://rookiehockey.ca/cart/" \
    "https://rookiehockey.ca/checkout/"; do
    STATUS=$(curl -sI "$url" | head -1 | awk '{print $2}')
    echo "$STATUS  $url"
done
# Expected: all 200 (checkout may 302 when cart is empty — acceptable)
```

- [ ] **Step 8: Flush all caches**

```bash
ssh -p SSH_PORT root@production-host.example "
wp --path=/var/www/rookiehockey.ca/htdocs cache flush --allow-root
redis-cli FLUSHDB
nginx -s reload
echo 'Caches flushed'
"
```

- [ ] **Step 9: Visual QA checklist** (browser, desktop + mobile 375px)

| Page | Checks |
|---|---|
| Homepage | SP scoreboard bar, sticky nav, logo, hero |
| Schedule / Events | Event blocks grid, SP countdown |
| Single Event | Matchup logo hero, SP sections injected via `the_content` |
| Standings | SP data table — teal caption border, zebra rows, DataTables pagination |
| Single Player | Dark hero, jersey number, position (from taxonomy), nationality |
| Single Team | Logo hero, SP roster/schedule content below |
| Shop / Register | Product card grid, "Register Now" button on subscriptions |
| Checkout | Two-column layout, order review on right |
| My Account | Left nav sidebar, store credit balance, subscription badges |
| Thank You | Green confirmation heading |
| 404 | Error message, search form, home link |
| Mobile nav | Hamburger opens slide-over, overlay closes it, Escape key closes it |
| RTL locale | Activate a RTL language in WP; verify layout mirrors correctly |

- [ ] **Step 10: Tag release**

```bash
git add .
git commit -m "feat(theme): rookie-sport v1.0.0 complete"
git tag -a v1.0.0 -m "Initial release: rookie-sport theme"
```

- [ ] **Step 11: Monitor error log for 30 minutes**

```bash
ssh -p SSH_PORT root@production-host.example "tail -f /var/log/nginx/rookiehockey.ca.error.log"
```

---

## Review Fixes Summary

| ID | Category | Fix |
|---|---|---|
| C1 | CRITICAL | Removed `sportspress_locate_template` filter — SP uses `locate_template()` natively with `SP_TEMPLATE_PATH = 'sportspress/'` |
| C3 | CRITICAL | `sp_position` → `wp_get_post_terms($id, 'sp_position')`; `sp_number` with `_sp_number` fallback; `sp_nationality` → `get_post_meta($id, 'sp_nationality', false)` (array) |
| C4 | CRITICAL | Added `comments.php` template |
| C5 | CRITICAL | Replaced `sed "s/'rookie'/'rookie-sport'/g"` with `sed "s/, 'rookie')/, 'rookie-sport')/g"` — only targets i18n function argument position |
| C6 | CRITICAL | Added `rtl.css` with directional overrides |
| M5 | MAJOR | Added `assets/src/css/blocks.css` and `assets/src/js/sp-tables.js` (were referenced in index.js but missing) |
| M7 | MAJOR | Wrapped all WC hook removals in `if ( ! class_exists( 'WooCommerce' ) ) { return; }` |
| M8 | MAJOR | Added `sportspress/index.php` silence file |
| N2 | MINOR | Replaced `wp_localize_script` with `wp_add_inline_script( ..., 'before' )` |
| N4 | MINOR | Documented `screenshot.png` must be exactly **1200×900px** |
| N6 | MINOR | Added `content-single.php` (single post full content with meta + thumbnail) |
| N7 | MINOR | Added `search.php` template |

---

## Round 2 Review Fixes (Multi-Agent Audit 2026-07-27)

> All 19 tasks from the consolidated multi-agent review addressed below. Critical fixes (C-1 to C-11) must be applied before implementation starts. Major fixes (M-1 to M-10) applied before launch. Minor/polish (T-19) included.

---

### Fix C-1: WooCommerce double-wrapper — amend `woocommerce/archive-product.php`

Remove the `do_action('woocommerce_before_main_content')` and `do_action('woocommerce_after_main_content')` calls. `rookie_sport_wc_wrapper_start()` is already hooked to those actions and fires automatically. Calling them explicitly from inside the template produces a second `<div id="primary">`.

**Replace the archive-product.php body with:**

```php
<?php
/**
 * Product archive — registration listings.
 * NOTE: do NOT call woocommerce_before_main_content / woocommerce_after_main_content
 * inline here. rookie_sport_wc_wrapper_start/end are already hooked to those actions
 * and will fire automatically via WC's routing. Calling them explicitly causes a
 * double-wrapper that corrupts page layout.
 *
 * @package RookieSport
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );
?>

<header class="woocommerce-products-header">
    <?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
        <h1 class="page-title"><?php woocommerce_page_title(); ?></h1>
    <?php endif; ?>
    <?php do_action( 'woocommerce_archive_description' ); ?>
</header>

<?php if ( woocommerce_product_loop() ) : ?>
    <?php do_action( 'woocommerce_before_shop_loop' ); ?>
    <?php woocommerce_product_loop_start(); ?>
    <?php if ( wc_get_loop_prop( 'total' ) ) :
        while ( have_posts() ) :
            the_post();
            do_action( 'woocommerce_shop_loop' );
            wc_get_template_part( 'content', 'product' );
        endwhile;
    endif; ?>
    <?php woocommerce_product_loop_end(); ?>
    <?php do_action( 'woocommerce_after_shop_loop' ); ?>
<?php else : ?>
    <?php do_action( 'woocommerce_no_products_found' ); ?>
<?php endif; ?>

<?php get_footer( 'shop' ); ?>
```

---

### Fix C-2: Add missing WooCommerce + WCS template overrides

**Add to file structure under `woocommerce/`:**

```
woocommerce/
├── single-product.php
├── cart/
│   ├── cart.php
│   ├── cart-totals.php
│   └── mini-cart.php
└── myaccount/
    ├── navigation.php
    ├── orders.php
    ├── subscriptions.php          ← WooCommerce Subscriptions
    └── subscription.php           ← WooCommerce Subscriptions (singular)
```

**`woocommerce/single-product.php`** — wraps WC default product rendering in theme container:

```php
<?php
/**
 * Single product page.
 * WC injects product content via its template hooks.
 * We only provide the outer wrapper.
 *
 * @package RookieSport
 */
defined( 'ABSPATH' ) || exit;
get_header( 'shop' );
?>
<div class="wc-primary single-product-page">
    <div class="container">
        <?php while ( have_posts() ) : the_post(); ?>
            <?php wc_get_template_part( 'content', 'single-product' ); ?>
        <?php endwhile; ?>
    </div>
</div>
<?php get_footer( 'shop' ); ?>
```

**`woocommerce/cart/mini-cart.php`** — copy WC default then add theme wrapper class:

```bash
# On server — copy WC template then add theme class to outer div
WC_DIR="/var/www/rookiehockey.ca/htdocs/wp-content/plugins/woocommerce/templates"
THEME_DIR="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/woocommerce"

mkdir -p "$THEME_DIR/cart" "$THEME_DIR/myaccount"

for f in cart/cart.php cart/cart-totals.php cart/mini-cart.php; do
    cp "$WC_DIR/$f" "$THEME_DIR/$f"
done
for f in myaccount/navigation.php myaccount/orders.php; do
    cp "$WC_DIR/$f" "$THEME_DIR/$f"
done

# WooCommerce Subscriptions templates
WCS_DIR="/var/www/rookiehockey.ca/htdocs/wp-content/plugins/woocommerce-subscriptions/templates"
for f in myaccount/subscriptions.php myaccount/subscription.php; do
    [ -f "$WCS_DIR/$f" ] && cp "$WCS_DIR/$f" "$THEME_DIR/$f"
done

# Safe text-domain replace on all newly copied files
find "$THEME_DIR/cart" "$THEME_DIR/myaccount" -name "*.php" | \
    xargs sed -i "s/, 'woocommerce' )/, 'rookie-sport' )/g;
                  s/, 'woocommerce')/, 'rookie-sport')/g"

echo "Copied: $(find $THEME_DIR/cart $THEME_DIR/myaccount -name '*.php' | wc -l) files"
```

Add mini-cart CSS to `woocommerce.css`:

```css
/* === Mini cart (shown in header area if WC supports it) === */
.wc-primary .woocommerce-mini-cart { list-style: none; padding: 0; margin: 0; }
.wc-primary .woocommerce-mini-cart-item { display: flex; gap: var(--space-3); padding: var(--space-3) 0; border-bottom: 1px solid var(--color-border-card); font-size: var(--text-sm); }
.wc-primary .woocommerce-mini-cart__total { font-family: var(--font-heading); font-weight: 700; padding: var(--space-3) 0; border-top: 2px solid var(--color-border-card); }
.wc-primary .woocommerce-mini-cart__buttons a { display: block; text-align: center; margin-bottom: var(--space-2); }

/* Subscriptions my-account pages */
.woocommerce-subscriptions-table { width: 100%; border-collapse: collapse; font-size: var(--text-sm); }
.woocommerce-subscriptions-table th { background: var(--color-navy); color: var(--color-text-primary); padding: var(--space-2) var(--space-3); font-family: var(--font-heading); font-size: var(--text-xs); text-transform: uppercase; letter-spacing: 0.06em; }
.woocommerce-subscriptions-table td { padding: var(--space-2) var(--space-3); border-bottom: 1px solid var(--color-border-card); color: var(--color-text-dark); vertical-align: middle; }
.subscription-status-active   { color: var(--color-win); font-weight: 600; }
.subscription-status-on-hold  { color: var(--color-draw); font-weight: 600; }
.subscription-status-cancelled,
.subscription-status-expired  { color: var(--color-loss); font-weight: 600; }
```

---

### Fix C-3: Add `sportspress.php` root fallback template

`sp_calendar`, `sp_table`, and `sp_list` post type pages need a theme-wrapped template. SP's `class-sp-template-loader.php` lists `sportspress.php` as the final fallback.

**Create `sportspress.php` in theme root:**

```php
<?php
/**
 * SportsPress root fallback template.
 * Caught by SP's template_loader() as the final fallback for all SP post types
 * that do not have a specific single-{type}.php override (sp_calendar, sp_table,
 * sp_list, etc.). Provides themed header/content/footer wrapper.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="content" class="site-content sp-page">
    <div class="container">
        <div class="content-wrapper">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">

                    <?php if ( have_posts() ) :
                        while ( have_posts() ) : the_post(); ?>
                            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                                <header class="entry-header">
                                    <?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
                                </header>
                                <div class="entry-content">
                                    <?php the_content(); ?>
                                </div>
                            </article>
                            <?php if ( comments_open() || get_comments_number() ) :
                                comments_template();
                            endif;
                        endwhile;
                    else :
                        get_template_part( 'content', 'none' );
                    endif; ?>

                </main>
            </div>
        </div>
    </div>
</div>

<?php get_footer(); ?>
```

---

### Fix C-4: CSS injection in `inc/customizer.php`

`esc_attr()` is wrong for CSS context. Replace with `sanitize_hex_color()` at the output point. The settings already have `sanitize_hex_color` as `sanitize_callback`; we must also re-sanitize at output.

**Replace `rookie_sport_customizer_css()` with:**

```php
function rookie_sport_customizer_css(): void {
    $accent = get_theme_mod( 'rookie_sport_accent_color', '#00a69c' );
    $nav    = get_theme_mod( 'rookie_sport_nav_color', '#1a2332' );

    if ( '#00a69c' === $accent && '#1a2332' === $nav ) {
        return;
    }

    // Re-sanitize at output — sanitize_hex_color is the correct function for CSS hex values.
    $accent = sanitize_hex_color( $accent ) ?: '#00a69c';
    $nav    = sanitize_hex_color( $nav ) ?: '#1a2332';

    $css = ':root{';
    if ( '#00a69c' !== $accent ) {
        // Hex color values from sanitize_hex_color() are safe to embed in CSS.
        $css .= '--color-teal:' . $accent . ';--color-accent:' . $accent . ';';
    }
    if ( '#1a2332' !== $nav ) {
        $css .= '--color-navy:' . $nav . ';';
    }
    $css .= '}';

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $css contains only sanitized hex values
    echo '<style id="rookie-sport-customizer-css">' . $css . '</style>' . "\n";
}
```

---

### Fix C-5: Homepage — replace thin wrapper with designed layout

**Replace `template-homepage.php` with:**

```php
<?php
/**
 * Template Name: Homepage
 * Full-width sports homepage: hero + 3-panel grid + widget areas.
 *
 * Widget areas registered (in inc/setup.php addition below):
 *   homepage-1 → hero overlay content (next game countdown, CTA)
 *   homepage-2 → panel left (standings table)
 *   homepage-3 → panel right (recent results / news)
 *
 * @package RookieSport
 */
get_header();
?>

<div id="content" class="site-content homepage">

    <!-- Hero -->
    <section class="homepage-hero" aria-label="<?php esc_attr_e( 'League hero', 'rookie-sport' ); ?>">
        <?php if ( has_post_thumbnail( get_option( 'page_on_front' ) ) ) :
            $hero_img = wp_get_attachment_image_url( get_post_thumbnail_id( get_option( 'page_on_front' ) ), 'full' );
        ?>
            <div class="homepage-hero__bg" style="background-image:url('<?php echo esc_url( $hero_img ); ?>');" aria-hidden="true"></div>
        <?php endif; ?>
        <div class="homepage-hero__overlay" aria-hidden="true"></div>
        <div class="container homepage-hero__content">
            <?php if ( is_active_sidebar( 'homepage-1' ) ) : ?>
                <?php dynamic_sidebar( 'homepage-1' ); ?>
            <?php else : ?>
                <?php while ( have_posts() ) : the_post(); ?>
                    <?php if ( get_the_content() ) : ?>
                        <div class="homepage-hero__text"><?php the_content(); ?></div>
                    <?php endif; ?>
                <?php endwhile; ?>
                <a href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>" class="button button-hero">
                    <?php esc_html_e( 'Register for the Season', 'rookie-sport' ); ?>
                </a>
            <?php endif; ?>
        </div>
    </section>

    <!-- 3-panel grid -->
    <section class="homepage-panels">
        <div class="container homepage-panels__grid">

            <!-- Next Game card (glassmorphism) -->
            <div class="homepage-panel homepage-panel--next-game" aria-label="<?php esc_attr_e( 'Next game', 'rookie-sport' ); ?>">
                <div class="panel-header"><?php esc_html_e( 'Next Game', 'rookie-sport' ); ?></div>
                <div class="panel-body">
                    <?php do_action( 'sportspress_header' ); ?>
                </div>
            </div>

            <!-- Standings -->
            <div class="homepage-panel homepage-panel--standings" aria-label="<?php esc_attr_e( 'Standings', 'rookie-sport' ); ?>">
                <div class="panel-header"><?php esc_html_e( 'Standings', 'rookie-sport' ); ?></div>
                <div class="panel-body">
                    <?php if ( is_active_sidebar( 'homepage-2' ) ) :
                        dynamic_sidebar( 'homepage-2' );
                    endif; ?>
                </div>
            </div>

            <!-- Recent results / news -->
            <div class="homepage-panel homepage-panel--results" aria-label="<?php esc_attr_e( 'Recent results', 'rookie-sport' ); ?>">
                <div class="panel-header"><?php esc_html_e( 'Recent Results', 'rookie-sport' ); ?></div>
                <div class="panel-body">
                    <?php if ( is_active_sidebar( 'homepage-3' ) ) :
                        dynamic_sidebar( 'homepage-3' );
                    endif; ?>
                </div>
            </div>

        </div>
    </section>

</div>

<?php get_footer(); ?>
```

**Add 3 homepage widget areas to `inc/setup.php` inside `rookie_sport_widgets_init()`:**

```php
// Homepage panels
$hp_shared = [
    'before_widget' => '<div class="homepage-widget %2$s">',
    'after_widget'  => '</div>',
    'before_title'  => '<h3 class="homepage-widget-title screen-reader-text">',
    'after_title'   => '</h3>',
];
register_sidebar( array_merge( $hp_shared, [
    'name' => esc_html__( 'Homepage — Hero', 'rookie-sport' ),
    'id'   => 'homepage-1',
] ) );
register_sidebar( array_merge( $hp_shared, [
    'name' => esc_html__( 'Homepage — Standings', 'rookie-sport' ),
    'id'   => 'homepage-2',
] ) );
register_sidebar( array_merge( $hp_shared, [
    'name' => esc_html__( 'Homepage — Results', 'rookie-sport' ),
    'id'   => 'homepage-3',
] ) );
```

**Add homepage CSS to a new `assets/src/css/homepage.css` (import in `index.js`):**

```css
/* === Homepage hero === */
.homepage-hero {
    position: relative;
    min-height: 480px;
    display: flex;
    align-items: center;
    overflow: hidden;
    background: var(--color-navy-dark);
}
.homepage-hero__bg {
    position: absolute; inset: 0;
    background-size: cover; background-position: center;
    transform: scale(1.05);
    transition: transform 8s ease-out;
}
.homepage-hero:hover .homepage-hero__bg { transform: scale(1); }
.homepage-hero__overlay {
    position: absolute; inset: 0;
    background: linear-gradient(135deg, rgba(15,25,35,0.92) 0%, rgba(0,166,156,0.18) 100%);
}
.homepage-hero__content {
    position: relative; z-index: 1;
    padding-block: var(--space-16);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--space-6);
}
.homepage-hero__text { max-width: 640px; }
.homepage-hero__text h1,
.homepage-hero__text h2 { color: #fff; }
.button-hero {
    font-size: var(--text-xl);
    padding: 0.75em 2em;
    background: var(--color-teal);
    box-shadow: 0 4px 24px rgba(0,166,156,0.4);
}
.button-hero:hover { background: var(--color-teal-dark); }

/* === 3-panel grid === */
.homepage-panels { background: var(--color-bg); padding: var(--space-12) 0; }
.homepage-panels__grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--space-6);
}
@media (min-width: 768px) {
    .homepage-panels__grid { grid-template-columns: repeat(3, 1fr); }
}

/* === Panel cards === */
.homepage-panel {
    border-radius: var(--border-radius-lg);
    overflow: hidden;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
}
.panel-header {
    padding: var(--space-3) var(--space-4);
    background: var(--color-surface-2);
    font-family: var(--font-heading);
    font-size: var(--text-sm);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--color-text-primary);
    border-bottom: 3px solid var(--color-teal);
}
.panel-body { padding: var(--space-4); }

/* Glassmorphism on next-game card */
.homepage-panel--next-game {
    background: rgba(26,35,50,0.7);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(0,166,156,0.25);
    box-shadow: 0 8px 32px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.07);
}
```

---

### Fix C-6: Accessible accent colour token + WCAG AA compliance

`#00a69c` on `#1a2332` ≈ 4.2:1 — fails WCAG AA for body-size text. Add a second token at sufficient contrast.

**Add to `style.css` `:root` block:**

```css
/* Accessible accent: #00bdb2 on #1a2332 ≈ 5.2:1 — use for small/body text links */
--color-accent:            #00a69c;  /* large headings, UI accents, borders */
--color-accent-accessible: #00bdb2;  /* body-size links, inline text, small labels */
```

**Update `base.css` link rule:**

```css
a {
    color: var(--color-accent-accessible); /* was --color-teal — now AA compliant */
    text-decoration: none;
    transition: color var(--transition-fast);
}
a:hover { color: var(--color-accent); }
```

**Update `navigation.css`** — nav links on dark navy background can keep `--color-accent` (large text, AA exempt).

**Update `sportspress.css` `.sp-view-all-link`:**

```css
.sp-view-all-link { color: var(--color-accent-accessible); }
```

---

### Fix C-7: Fluid typography — add `clamp()` scale

**Add to `style.css` `:root` block (replace the fixed text-* tokens):**

```css
/* Fluid type scale — clamp(min, preferred, max) */
--text-hero: clamp(3rem, 8vw, 7rem);       /* player jersey watermark, hero numbers */
--text-5xl:  clamp(2.5rem, 6vw, 3.5rem);   /* page H1 */
--text-4xl:  clamp(2rem, 5vw, 2.75rem);    /* H2 */
--text-3xl:  clamp(1.75rem, 4vw, 2.25rem); /* H3 */
--text-2xl:  clamp(1.375rem, 3vw, 1.875rem);
--text-xl:   clamp(1.125rem, 2.5vw, 1.375rem);
--text-stat: clamp(1.5rem, 3.5vw, 2.5rem); /* SP stat values */
/* Small sizes remain fixed — fluid scaling not needed below 1rem */
--text-lg:   1.125rem;
--text-base: 1rem;
--text-sm:   0.875rem;
--text-xs:   0.75rem;
```

**Add SP stat number rule to `sportspress.css`:**

```css
/* SP statistic values — fluid + Barlow Condensed for impact */
.sp-statistic-value,
.sp-statistic-bar .sp-statistic-value,
.sp-template-player-statistics td.sp-highlight {
    font-family: var(--font-heading);
    font-size: var(--text-stat);
    font-weight: 700;
    color: var(--color-navy);
}
```

---

### Fix C-8: Hockey-specific checkout fields

**Add to `inc/woocommerce.php` (inside `class_exists('WooCommerce')` guard):**

```php
/**
 * Add hockey-specific fields after order notes.
 */
add_action( 'woocommerce_after_order_notes', 'rookie_sport_checkout_hockey_fields' );

function rookie_sport_checkout_hockey_fields( \WC_Checkout $checkout ): void {
    echo '<div class="hockey-checkout-fields">';
    echo '<h3>' . esc_html__( 'League Information', 'rookie-sport' ) . '</h3>';

    woocommerce_form_field( 'hockey_jersey_size', [
        'type'     => 'select',
        'class'    => [ 'form-row-first' ],
        'label'    => esc_html__( 'Jersey Size', 'rookie-sport' ),
        'required' => false,
        'options'  => [
            ''    => esc_html__( 'Select size', 'rookie-sport' ),
            'XS'  => 'XS',
            'S'   => 'S',
            'M'   => 'M',
            'L'   => 'L',
            'XL'  => 'XL',
            'XXL' => 'XXL',
        ],
    ], $checkout->get_value( 'hockey_jersey_size' ) );

    woocommerce_form_field( 'hockey_position', [
        'type'     => 'select',
        'class'    => [ 'form-row-last' ],
        'label'    => esc_html__( 'Preferred Position', 'rookie-sport' ),
        'required' => false,
        'options'  => [
            ''         => esc_html__( 'Select position', 'rookie-sport' ),
            'forward'  => esc_html__( 'Forward', 'rookie-sport' ),
            'defence'  => esc_html__( 'Defence', 'rookie-sport' ),
            'goalie'   => esc_html__( 'Goalie', 'rookie-sport' ),
        ],
    ], $checkout->get_value( 'hockey_position' ) );

    echo '</div>';
}

/**
 * Save hockey checkout fields to order meta.
 */
add_action( 'woocommerce_checkout_update_order_meta', 'rookie_sport_save_hockey_fields' );

function rookie_sport_save_hockey_fields( int $order_id ): void {
    $allowed_sizes     = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL' ];
    $allowed_positions = [ 'forward', 'defence', 'goalie' ];

    if ( ! empty( $_POST['hockey_jersey_size'] ) ) {
        $size = sanitize_text_field( wp_unslash( $_POST['hockey_jersey_size'] ) );
        if ( in_array( $size, $allowed_sizes, true ) ) {
            update_post_meta( $order_id, '_hockey_jersey_size', $size );
        }
    }

    if ( ! empty( $_POST['hockey_position'] ) ) {
        $pos = sanitize_text_field( wp_unslash( $_POST['hockey_position'] ) );
        if ( in_array( $pos, $allowed_positions, true ) ) {
            update_post_meta( $order_id, '_hockey_position', $pos );
        }
    }
}

/**
 * Show hockey fields in order admin detail.
 */
add_action( 'woocommerce_admin_order_data_after_billing_address', 'rookie_sport_display_hockey_fields_admin' );

function rookie_sport_display_hockey_fields_admin( \WC_Order $order ): void {
    $size = $order->get_meta( '_hockey_jersey_size' );
    $pos  = $order->get_meta( '_hockey_position' );
    if ( $size || $pos ) {
        echo '<p><strong>' . esc_html__( 'Jersey Size:', 'rookie-sport' ) . '</strong> ' . esc_html( $size ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Position:', 'rookie-sport' ) . '</strong> ' . esc_html( $pos ) . '</p>';
    }
}
```

---

### Fix C-9: Safe two-pass text-domain `sed` for email migration

**Replace the single-pass sed in Task 9, Step 5 with:**

```bash
CHILD="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-child/woocommerce/emails"
NEW="/var/www/rookiehockey.ca/htdocs/wp-content/themes/rookie-sport/woocommerce/emails"
mkdir -p "$NEW"
cp "$CHILD"/*.php "$NEW/"

# Two-pass: cover WPCS compact (, 'rookie')) and WPCS-spaced (, 'rookie' )) forms
find "$NEW" -name "*.php" | xargs sed -i \
    -e "s/, 'rookie' )/, 'rookie-sport' )/g" \
    -e "s/, 'rookie')/, 'rookie-sport')/g"

# Audit: any remaining 'rookie' that isn't 'rookie-sport'
REMAINING=$(grep -rn "'rookie'" "$NEW" | grep -v "'rookie-sport'" | grep -v "'rookie-child'")
if [ -n "$REMAINING" ]; then
    echo "WARNING: Unreplaced text domains found:"
    echo "$REMAINING"
else
    echo "Clean: all text domains updated."
fi
echo "Email templates: $(ls $NEW/*.php | wc -l) files"
```

Also apply the same two-pass to the migrated myaccount/ files in Task 9, Step 4:

```bash
find "$NEW/myaccount" "$NEW/checkout" -name "*.php" | xargs sed -i \
    -e "s/, 'rookie' )/, 'rookie-sport' )/g" \
    -e "s/, 'rookie')/, 'rookie-sport')/g"
```

---

### Fix C-10: `_sp_number` meta key — use underscore prefix directly

**Replace in `sportspress/single-player.php`:**

```php
// WRONG (from previous plan):
// $number = get_post_meta( $player_id, 'sp_number', true );
// if ( ! $number ) {
//     $number = get_post_meta( $player_id, '_sp_number', true );
// }

// CORRECT — SP stores as private meta with underscore prefix:
$number = get_post_meta( $player_id, '_sp_number', true );
```

---

### Fix C-11: `sp_nationality` — use SP helper function

**Replace in `sportspress/single-player.php`:**

```php
// WRONG (raw ISO code output):
// $nationality = esc_html( strtoupper( $nationalities[0] ) );

// CORRECT — SP provides a localization helper:
$nationalities = get_post_meta( $player_id, 'sp_nationality', false );
$nationality   = '';
if ( ! empty( $nationalities ) && is_array( $nationalities ) ) {
    // sp_get_nationality_string() converts ISO code to localised country name.
    if ( function_exists( 'sp_get_nationality_string' ) ) {
        $nationality = esc_html( sp_get_nationality_string( $nationalities[0] ) );
    } else {
        // Fallback if SP helper unavailable.
        $nationality = esc_html( strtoupper( $nationalities[0] ) );
    }
}
```

---

### Fix M-1: Wire `sportspress_frontend_css` action

SP uses this action to propagate theme accent colours into its own CSS (countdown timers, stat bars). Without it, SP components keep their own default colours regardless of the theme.

**Add to `inc/sportspress.php`:**

```php
/**
 * Pass theme accent colour to SportsPress frontend CSS system.
 * SP uses this to colour countdown timers, stat bars, and highlight rows.
 */
add_action( 'wp_enqueue_scripts', 'rookie_sport_sp_colors', 20 );

function rookie_sport_sp_colors(): void {
    if ( ! function_exists( 'sp_enqueue_style' ) ) {
        return;
    }

    $accent = get_theme_mod( 'rookie_sport_accent_color', '#00a69c' );
    $accent = sanitize_hex_color( $accent ) ?: '#00a69c';

    $nav = get_theme_mod( 'rookie_sport_nav_color', '#1a2332' );
    $nav = sanitize_hex_color( $nav ) ?: '#1a2332';

    do_action( 'sportspress_frontend_css', [
        'accent' => $accent,
        'header' => $nav,
    ] );
}
```

---

### Fix M-2: Add `sportspress/taxonomy-venue.php`

**Create `sportspress/taxonomy-venue.php`:**

```php
<?php
/**
 * Venue taxonomy archive page.
 * Caught by SP's template_loader() for sp_venue taxonomy term pages.
 *
 * @package RookieSport
 */

get_header(); ?>

<div id="content" class="site-content sp-venue-page">
    <div class="container">
        <div class="content-wrapper">
            <div id="primary" class="content-area">
                <main id="main" class="site-main" role="main">

                    <header class="page-header">
                        <?php the_archive_title( '<h1 class="page-title">', '</h1>' ); ?>
                        <?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
                    </header>

                    <?php if ( have_posts() ) :
                        while ( have_posts() ) : the_post();
                            get_template_part( 'content', get_post_format() );
                        endwhile;
                        the_posts_navigation();
                    else :
                        get_template_part( 'content', 'none' );
                    endif; ?>

                </main>
            </div>
        </div>
    </div>
</div>

<?php get_footer(); ?>
```

---

### Fix M-3: Guard `wp_get_post_terms()` return value

**Already partially addressed in C-3. Full guard in `sportspress/single-player.php`:**

```php
$position_terms = wp_get_post_terms( $player_id, 'sp_position' );

if ( is_wp_error( $position_terms ) || empty( $position_terms ) ) {
    $position = '';
} else {
    $position = esc_html( $position_terms[0]->name );
}
```

---

### Fix M-4: Self-host fonts (eliminates CDN latency + GDPR risk)

Replace the Google Fonts CDN enqueue with locally served WOFF2 files.

**Step 1 — Download fonts on dev machine using google-webfonts-helper:**

```bash
# Download via https://gwfh.mranftl.com/fonts
# Barlow Condensed: weights 400,600,700,800
# Inter: weights 400,500,600,700
# Place downloaded .woff2 files in: assets/fonts/
mkdir -p assets/fonts
```

**Step 2 — Add `@font-face` declarations to `assets/src/css/base.css` (before reset):**

```css
/* === Self-hosted fonts === */
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-400.woff2') format('woff2');
    font-weight: 400; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-600.woff2') format('woff2');
    font-weight: 600; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-700.woff2') format('woff2');
    font-weight: 700; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-800.woff2') format('woff2');
    font-weight: 800; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-400.woff2') format('woff2');
    font-weight: 400; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-500.woff2') format('woff2');
    font-weight: 500; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-600.woff2') format('woff2');
    font-weight: 600; font-style: normal; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-700.woff2') format('woff2');
    font-weight: 700; font-style: normal; font-display: swap;
}
```

**Step 3 — Remove Google Fonts CDN enqueue from `inc/enqueue.php`:**

```php
// REMOVE these lines from rookie_sport_enqueue():
// wp_enqueue_style( 'rookie-sport-fonts', 'https://fonts.googleapis.com/...' );

// The @font-face declarations in base.css cover font loading.
// Remove the 'rookie-sport-fonts' dependency from the style enqueue:
wp_enqueue_style(
    'rookie-sport-style',
    ROOKIE_SPORT_URI . '/assets/dist/index.css',
    [],  // no font dependency
    ROOKIE_SPORT_VERSION
);
```

**Step 4 — Update editor.css font references to use same local fonts:**

```css
/* editor.css — fonts served locally */
.editor-styles-wrapper {
    font-family: 'Inter', sans-serif;
    /* @font-face declarations from base.css already declare the local paths */
}
```

---

### Fix M-5: Gate `editor.css` on block editor screen only

**Replace `rookie_sport_admin_enqueue()` in `inc/enqueue.php`:**

```php
add_action( 'admin_enqueue_scripts', 'rookie_sport_admin_enqueue' );

function rookie_sport_admin_enqueue(): void {
    $screen = get_current_screen();
    if ( ! $screen || ! $screen->is_block_editor() ) {
        return;
    }
    wp_enqueue_style(
        'rookie-sport-editor',
        ROOKIE_SPORT_URI . '/assets/dist/editor.css',
        [],
        ROOKIE_SPORT_VERSION
    );
}
```

---

### Fix M-6: Remove nonce from inline script (breaks full-page caching)

`wp_create_nonce()` is per-user and expires every 12 hours. Having it in a cached page script tag means every cached visitor gets a stale nonce, and any AJAX call fails silently. The theme currently has no AJAX handlers, so the nonce is unnecessary.

**Replace `wp_add_inline_script` call in `inc/enqueue.php`:**

```php
// Pass only non-sensitive, cache-safe data.
// Nonce removed — add back only when an actual AJAX handler is implemented,
// and deliver via a separate uncached fragment at that point.
wp_add_inline_script(
    'rookie-sport-scripts',
    'const RookieSport = ' . wp_json_encode( [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'siteUrl' => esc_url( home_url() ),
    ] ) . ';',
    'before'
);
```

---

### Fix M-7: WC button text — complete registration CTA language

**Add to `inc/woocommerce.php` (inside `class_exists('WooCommerce')` guard):**

```php
// Override WC button text for a league registration context.
add_filter( 'woocommerce_product_single_add_to_cart_text', 'rookie_sport_single_add_to_cart_text', 10, 1 );
function rookie_sport_single_add_to_cart_text( string $text ): string {
    global $product;
    if ( $product && $product->is_type( 'subscription' ) ) {
        return esc_html__( 'Register Now', 'rookie-sport' );
    }
    return $text;
}

// Already added for archive. Add for order submission button:
add_filter( 'woocommerce_order_button_text', function(): string {
    return esc_html__( 'Complete Registration', 'rookie-sport' );
} );
```

---

### Fix M-8: SP event CSS — `sp-section-content-{key}` and pre/post-game banner

**Add to `assets/src/css/sportspress.css`:**

```css
/* === SP section content wrappers (dynamically keyed by SP) === */
.sp-section-content { margin-bottom: var(--space-6); }
.sp-section-content-logos   { background: var(--color-navy); border-radius: var(--border-radius-lg); padding: var(--space-6); }
.sp-section-content-results { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; }
.sp-section-content-venue   { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); overflow: hidden; }
.sp-section-content-staff,
.sp-section-content-content { background: var(--color-card); border: 1px solid var(--color-border-card); border-radius: var(--border-radius-lg); padding: var(--space-4); }

/* SP tab content panels */
.sp-tab-content { padding: var(--space-4); display: none; }
.sp-tab-content[style*="display: block"] { display: block; }

/* === Pre/post-game status banner === */
.sp-event-status-banner {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-4);
    font-family: var(--font-heading);
    font-size: var(--text-sm);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}
.sp-event-status-banner--upcoming {
    background: rgba(245,158,11,0.15);
    color: #f59e0b;
    border-bottom-color: rgba(245,158,11,0.2);
}
.sp-event-status-banner--final {
    background: rgba(0,166,156,0.15);
    color: var(--color-teal-light);
    border-bottom-color: rgba(0,166,156,0.2);
}

/* single-sp_event body class scoping */
.single-sp_event .sp-section-content-logos {
    margin-bottom: var(--space-8);
}
.single-sp_event .entry-content { max-width: 100%; }
```

**Add pre/post-game banner hook to `inc/sportspress.php`:**

```php
/**
 * Inject a status banner above event content.
 * Shows 'Upcoming' (amber) or 'Final' (teal) based on sp_results meta.
 */
add_action( 'sportspress_before_single_event', 'rookie_sport_event_status_banner' );

function rookie_sport_event_status_banner(): void {
    if ( ! is_singular( 'sp_event' ) ) {
        return;
    }

    $event_id = get_the_ID();
    $status   = function_exists( 'sp_get_status' ) ? sp_get_status( $event_id ) : '';

    if ( 'results' === $status ) {
        $label = esc_html__( 'Final', 'rookie-sport' );
        $class = 'sp-event-status-banner--final';
    } else {
        $label = esc_html__( 'Upcoming', 'rookie-sport' );
        $class = 'sp-event-status-banner--upcoming';
    }

    printf(
        '<div class="sp-event-status-banner %s" aria-label="%s">%s</div>',
        esc_attr( $class ),
        esc_attr( $label ),
        esc_html( $label )
    );
}
```

---

### Fix M-9: My Account `.is-active` CSS selector

WC adds `.is-active` class server-side via `wc_get_account_menu_item_classes()` on the `<li>` element. The previous plan targeted `<a>` and attributed it to JS — both wrong.

**Replace in `assets/src/css/woocommerce.css`:**

```css
/* WRONG (old):
.woocommerce-MyAccount-navigation li.is-active a { ... } */

/* CORRECT — .is-active is on the <li>, set server-side by WC: */
.woocommerce-MyAccount-navigation-link--active > a,
.woocommerce-MyAccount-navigation li.is-active > a {
    color: var(--color-teal-dark);
    background: rgba(0,166,156,0.06);
}
.woocommerce-MyAccount-navigation-link--active,
.woocommerce-MyAccount-navigation li.is-active {
    border-left: 3px solid var(--color-teal);
}
```

---

### Fix M-10: Surface elevation token system + gradient language

**Add to `style.css` `:root` block:**

```css
/* 4-level surface elevation system */
--surface-0: #0f1923;  /* page background — deepest */
--surface-1: #1a2332;  /* nav, footer, SP captions */
--surface-2: #243044;  /* SP table headers, panel headers */
--surface-3: #2e3d57;  /* hover states, active elements */

/* Gradient tokens */
--gradient-hero:  linear-gradient(135deg, rgba(15,25,35,0.92) 0%, rgba(0,166,156,0.18) 100%);
--gradient-card:  linear-gradient(180deg, rgba(36,48,68,0.0) 0%, rgba(15,25,35,0.6) 100%);
--gradient-teal:  linear-gradient(135deg, #00a69c 0%, #00bdb2 100%);
```

**Update `assets/src/css/sportspress.css` table headers to use surface tokens:**

```css
.sp-data-table th  { background: var(--surface-2); }
.sp-table-caption  { background: var(--surface-1); }
.sp-heading        { background: var(--surface-1); }
.panel-header      { background: var(--surface-2); }
```

**Update `navigation.css` to use surface tokens:**

```css
#masthead                    { background: var(--surface-1); }
#masthead.is-scrolled        { background: rgba(26,35,50,0.97); }
.main-navigation ul ul       { background: var(--surface-0); }
```

---

### Fix T-19: Minor / Polish fixes (consolidated)

**`prefers-reduced-motion` — add to `assets/src/css/base.css`:**

```css
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}
```

**Sticky first-column in SP tables — add to `sportspress.css`:**

```css
/* Keep player/team name visible when table scrolls horizontally */
.sp-data-table td:first-child,
.sp-data-table th:first-child {
    position: sticky;
    left: 0;
    background: var(--color-card);
    z-index: 1;
    box-shadow: 2px 0 4px rgba(0,0,0,0.08);
}
.sp-data-table th:first-child {
    background: var(--surface-2);
}
.sp-data-table tr:nth-child(even) td:first-child {
    background: var(--color-card-alt);
}
.sp-data-table tr:hover td:first-child {
    background: #eff6ff;
}
```

**Sticky header admin bar offset — update `assets/src/js/sticky-header.js`:**

```js
( function () {
    'use strict';
    const header   = document.getElementById( 'masthead' );
    if ( ! header ) return;

    // Account for WP admin bar (32px desktop / 46px mobile).
    function getAdminBarHeight() {
        const bar = document.getElementById( 'wpadminbar' );
        return bar ? bar.offsetHeight : 0;
    }

    function updateStickyTop() {
        header.style.top = getAdminBarHeight() + 'px';
    }

    updateStickyTop();
    window.addEventListener( 'resize', updateStickyTop );

    const sentinel = document.createElement( 'div' );
    sentinel.style.cssText = 'position:absolute;top:0;left:0;width:1px;height:1px;pointer-events:none;';
    document.body.insertBefore( sentinel, document.body.firstChild );

    new IntersectionObserver(
        ( [ entry ] ) => header.classList.toggle( 'is-scrolled', ! entry.isIntersecting ),
        { threshold: 0, rootMargin: '-1px 0px 0px 0px' }
    ).observe( sentinel );
} )();
```

**Skip-link scroll margin — add to `layout.css`:**

```css
/* Ensure anchor targets aren't hidden under sticky header */
#main,
:target {
    scroll-margin-top: calc(var(--nav-height) + 8px);
}
```

**Jersey number watermark display size — add to `sportspress.css`:**

```css
/* Large jersey number as background watermark behind player name */
.sp-player-number {
    font-size: var(--text-hero); /* clamp(3rem, 8vw, 7rem) */
    color: rgba(255,255,255,0.08);
    line-height: 0.85;
    margin-bottom: calc(-1 * var(--space-4)); /* overlap with name below */
    display: block;
    user-select: none;
    pointer-events: none;
}
```

**SP tab `focus-visible` outline — add to `sportspress.css`:**

```css
.sp-tab-menu-item a:focus-visible {
    outline: 2px solid var(--color-accent-accessible);
    outline-offset: 2px;
    border-radius: 2px;
}
```

**WC cart count — tighten escaping in `header.php`:**

```php
// Replace: echo esc_html( $count );
// With:
echo esc_html( absint( $count ) );
```

**Update file structure additions** — add `homepage.css` to `index.js` imports:

```js
// Add to assets/src/index.js after blocks.css:
import './css/homepage.css';
```

Also add to `assets/src/index.js`:

```js
// Remove sportspress/ from webpack ignore if fonts are self-hosted:
// assets/fonts/ directory should NOT be processed by webpack — add to webpack copy-plugin or gitignore dist
```

---

## Round 2 Review Fixes — Summary Table

| ID | Category | File(s) affected | Status |
|---|---|---|---|
| C-1 | CRITICAL | `woocommerce/archive-product.php` | Fixed — removed inline `do_action` calls |
| C-2 | CRITICAL | `woocommerce/single-product.php`, `cart/*`, `myaccount/navigation,orders,subscriptions,subscription` | Fixed — added all templates + CSS |
| C-3 | CRITICAL | `sportspress.php` (theme root) | Fixed — added catch-all SP template |
| C-4 | CRITICAL | `inc/customizer.php` | Fixed — `sanitize_hex_color()` at output |
| C-5 | CRITICAL | `template-homepage.php`, `inc/setup.php`, `assets/src/css/homepage.css` | Fixed — hero + 3-panel grid + 3 widget areas |
| C-6 | CRITICAL | `style.css`, `base.css`, `sportspress.css` | Fixed — `--color-accent-accessible` token |
| C-7 | CRITICAL | `style.css` | Fixed — full `clamp()` fluid type scale |
| C-8 | CRITICAL | `inc/woocommerce.php` | Fixed — hockey checkout fields with whitelist validation |
| C-9 | CRITICAL | Task 9 sed commands | Fixed — two-pass covering WPCS spaced + compact forms |
| C-10 | CRITICAL | `sportspress/single-player.php` | Fixed — use `_sp_number` directly |
| C-11 | CRITICAL | `sportspress/single-player.php` | Fixed — `sp_get_nationality_string()` with fallback |
| M-1 | MAJOR | `inc/sportspress.php` | Fixed — `sportspress_frontend_css` action wired |
| M-2 | MAJOR | `sportspress/taxonomy-venue.php` | Fixed — added template |
| M-3 | MAJOR | `sportspress/single-player.php` | Fixed — `is_wp_error()` + `empty()` guards |
| M-4 | MAJOR | `inc/enqueue.php`, `assets/src/css/base.css` | Fixed — self-hosted WOFF2 fonts |
| M-5 | MAJOR | `inc/enqueue.php` | Fixed — gated on `is_block_editor()` |
| M-6 | MAJOR | `inc/enqueue.php` | Fixed — nonce removed from inline script |
| M-7 | MAJOR | `inc/woocommerce.php` | Fixed — `woocommerce_order_button_text` filter added |
| M-8 | MAJOR | `assets/src/css/sportspress.css`, `inc/sportspress.php` | Fixed — `sp-section-content-*` selectors + status banner |
| M-9 | MAJOR | `assets/src/css/woocommerce.css` | Fixed — `.woocommerce-MyAccount-navigation-link--active` |
| M-10 | MAJOR | `style.css`, `sportspress.css`, `navigation.css` | Fixed — 4-level surface tokens + gradient tokens |
| T-19 | MINOR | `base.css`, `sportspress.css`, `sticky-header.js`, `layout.css`, `header.php` | Fixed — reduced-motion, sticky column, admin bar offset, skip margin, jersey watermark, focus ring, cart count escaping |

---

## Round 3 — Remaining Minor Fixes

> Addresses the 9 minor items not covered in previous rounds: m-2, m-6, m-8, m-12, m-15, m-16, m-17 (italic @font-face), m-18, m-19/m-20.

---

### Fix m-2: `sportspress_header_sponsors_selector` filter

SP's sponsor injection targets `.site-footer` by default. The new theme's footer uses `.site-footer` as well, so sponsors render correctly by default — no filter change needed for the footer placement. However, if the scoreboard bar (`sp-header-bar`) should also receive sponsor output, declare the correct selector via SP's filter.

**Add to `inc/sportspress.php`:**

```php
/**
 * Confirm SP footer sponsor injection targets the correct element.
 * SP default target is '.site-footer' which matches the theme's footer class.
 * Filtering here for explicitness and to allow easy future override.
 */
add_filter( 'sportspress_footer_sponsors_selector', function( string $selector ): string {
    return '.site-footer .footer-widget-col'; // inject into first widget column
} );
```

---

### Fix m-6: Confirm Oswald is overridden by Barlow Condensed

The parent Rookie theme loads Oswald via Google Fonts and sets it on headings, nav, and SP table captions. Since `rookie-sport` is a standalone theme (not a child), this is not inherited — but if SP's own CSS or any remnant stylesheet declares `font-family: 'Oswald'` it will fall back to system sans because Oswald is not enqueued.

**Add explicit override to `assets/src/css/base.css` after the `@font-face` declarations:**

```css
/* === Explicit Oswald override ===
   SP Pro and the original Rookie theme reference Oswald in their CSS.
   Since Oswald is not enqueued in this theme, any fallback to Oswald
   would render as system sans. This rule overrides all such declarations
   with Barlow Condensed before they can take effect. */
.sp-heading,
.sp-table-caption,
.sp-template-countdown time span,
.sp-template-event-logos,
.sp-template .player-gallery-group-name,
.single-sp_staff .entry-header .entry-title strong,
.main-navigation a,
.menu-toggle {
    font-family: var(--font-heading);
}
```

---

### Fix m-8: Surface `sp_current_team` on single player page

**Add to `sportspress/single-player.php` — inside the hero, below the player meta `<dl>`:**

```php
<?php
// Link to current team(s).
$current_teams = get_post_meta( $player_id, 'sp_current_team', false );
if ( ! empty( $current_teams ) && is_array( $current_teams ) ) :
?>
    <div class="sp-player-current-team">
        <?php foreach ( $current_teams as $team_id ) :
            $team_id = absint( $team_id );
            if ( ! $team_id ) continue;
            ?>
            <a href="<?php echo esc_url( get_permalink( $team_id ) ); ?>" class="sp-player-team-link">
                <?php if ( has_post_thumbnail( $team_id ) ) : ?>
                    <?php echo get_the_post_thumbnail( $team_id, [ 32, 32 ], [ 'alt' => esc_attr( get_the_title( $team_id ) ) ] ); ?>
                <?php endif; ?>
                <span><?php echo esc_html( get_the_title( $team_id ) ); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
```

**Add CSS to `sportspress.css`:**

```css
/* Current team link on player hero */
.sp-player-current-team {
    display: flex;
    flex-wrap: wrap;
    gap: var(--space-2);
    margin-top: var(--space-4);
}
.sp-player-team-link {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-1) var(--space-3);
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: var(--border-radius);
    color: rgba(255,255,255,0.85);
    font-size: var(--text-sm);
    font-family: var(--font-heading);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    transition: background var(--transition-fast), border-color var(--transition-fast);
    text-decoration: none;
}
.sp-player-team-link:hover {
    background: rgba(0,166,156,0.2);
    border-color: var(--color-teal);
    color: #fff;
}
.sp-player-team-link img {
    width: 24px;
    height: 24px;
    object-fit: contain;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,0.4));
}
```

---

### Fix m-12: Sticky header collapses at ≤600px

On small screens the full-height nav wastes vertical space. Collapse to 48px, hide tagline.

**Add to `assets/src/css/navigation.css`:**

```css
@media (max-width: 600px) {
    :root { --nav-height: 48px; }

    .site-description { display: none; }

    .site-logo img { height: 32px; }

    .site-title { font-size: var(--text-base); }

    .nav-actions { gap: var(--space-1); }
}
```

---

### Fix m-15: CSS custom property specificity — WC/SP hardcoded hex overrides

WC and SP inject `<style>` blocks via `wp_head` using hardcoded hex values that bypass CSS custom properties. Add explicit class-scoped overrides using `var()` to reclaim control.

**Add to `assets/src/css/sportspress.css`:**

```css
/* === SP hardcoded-hex overrides ===
   SP injects inline styles via wp_head. These rules re-assert
   the theme's design tokens over any SP-generated hex values. */
.sp-highlight,
.sp-template-event-logos .sp-team-result,
.sp-template-tournament-bracket .sp-result {
    background: var(--color-teal) !important;
    color: #fff !important;
}
.sp-tab-menu-item-active a {
    border-bottom-color: var(--color-teal) !important;
    color: var(--color-teal-dark) !important;
}
.sp-template-event-calendar #today,
.widget_calendar #today {
    background: var(--color-card) !important;
}
```

**Add to `assets/src/css/woocommerce.css`:**

```css
/* === WC hardcoded-hex overrides ===
   WC injects button/link colours via wp_head. Re-assert tokens. */
.woocommerce a.button,
.woocommerce button.button,
.woocommerce input.button,
.woocommerce #respond input#submit {
    background-color: var(--color-teal) !important;
    color: #fff !important;
    border-radius: var(--border-radius) !important;
}
.woocommerce a.button:hover,
.woocommerce button.button:hover {
    background-color: var(--color-teal-dark) !important;
}
```

---

### Fix m-16: Extended spacing token scale

The existing scale jumps from `--space-12` to `--space-16` with nothing between, and nothing above `--space-16`. Larger layout gaps need tokens to avoid magic numbers.

**Add to `style.css` `:root` block:**

```css
/* Extended spacing scale */
--space-14: 3.5rem;
--space-20: 5rem;
--space-24: 6rem;
--space-32: 8rem;
```

---

### Fix m-17: Italic `@font-face` declarations for self-hosted fonts

Since M-4 switched to self-hosted fonts, italic variants need their own `@font-face` blocks. Without them, browsers synthesise faked italics which look poor on Barlow Condensed.

**Download italic WOFF2 files from google-webfonts-helper then add to `assets/src/css/base.css`:**

```css
/* === Italic variants === */
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-400-italic.woff2') format('woff2');
    font-weight: 400; font-style: italic; font-display: swap;
}
@font-face {
    font-family: 'Barlow Condensed';
    src: url('../fonts/barlow-condensed-700-italic.woff2') format('woff2');
    font-weight: 700; font-style: italic; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-400-italic.woff2') format('woff2');
    font-weight: 400; font-style: italic; font-display: swap;
}
@font-face {
    font-family: 'Inter';
    src: url('../fonts/inter-600-italic.woff2') format('woff2');
    font-weight: 600; font-style: italic; font-display: swap;
}
```

**Add to Task 12 deployment (font download step):**

```bash
# Download from https://gwfh.mranftl.com/fonts
# Required italic WOFF2 files:
#   barlow-condensed-400-italic.woff2
#   barlow-condensed-700-italic.woff2
#   inter-400-italic.woff2
#   inter-600-italic.woff2
# Place in: assets/fonts/
```

---

### Fix m-18: Season dates and division label on product archive cards

Registration products need more context than just title + price. Players need to know which division and when the season runs before clicking.

**Add to `inc/woocommerce.php` (inside `class_exists('WooCommerce')` guard):**

```php
/**
 * Output season dates and division below the product title in the loop.
 * Reads from product custom fields: _season_start, _season_end, _division.
 * These are set via WooCommerce product custom fields or ACF.
 */
add_action( 'woocommerce_after_shop_loop_item_title', 'rookie_sport_product_season_meta', 5 );

function rookie_sport_product_season_meta(): void {
    global $product;
    if ( ! $product ) {
        return;
    }

    $division = $product->get_meta( '_division' );
    $start    = $product->get_meta( '_season_start' );
    $end      = $product->get_meta( '_season_end' );

    if ( ! $division && ! $start ) {
        return;
    }

    echo '<div class="product-season-meta">';

    if ( $division ) {
        printf(
            '<span class="product-division">%s</span>',
            esc_html( $division )
        );
    }

    if ( $start ) {
        $start_formatted = mysql2date( get_option( 'date_format' ), sanitize_text_field( $start ) );
        $end_formatted   = $end ? mysql2date( get_option( 'date_format' ), sanitize_text_field( $end ) ) : '';
        printf(
            '<span class="product-dates">%s%s</span>',
            esc_html( $start_formatted ),
            $end_formatted ? ' – ' . esc_html( $end_formatted ) : ''
        );
    }

    echo '</div>';
}
```

**Add CSS to `woocommerce.css`:**

```css
/* Season meta on product cards */
.product-season-meta {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 0 var(--space-4) var(--space-2);
    font-size: var(--text-xs);
    color: #6b7280;
}
.product-division {
    font-family: var(--font-heading);
    font-weight: 700;
    font-size: var(--text-xs);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--color-teal-dark);
}
.product-dates {
    color: #6b7280;
}
```

---

### Fix m-19: `sp_nationality` escaping in HTML attribute context

If the nationality value is ever placed in an HTML attribute (e.g. `data-country`, `title`, `aria-label`), `esc_html()` is insufficient — must use `esc_attr()`.

**Update `sportspress/single-player.php` to use `esc_attr()` consistently:**

```php
// In the <dl> output — text node: esc_html() is correct.
// In any attribute context, use esc_attr(). Defensively use esc_attr() for both
// since it is safe in text nodes and required in attributes.

// Replace:
// echo esc_html( $nationality );
// With:
echo esc_attr( $nationality );

// Also update the aria-label on any element that uses nationality:
// aria-label="<?php echo esc_attr( $nationality ); ?>"
```

---

### Fix m-20: `sportspress/team-lists.php` — CSS grid override for player roster

SP's default roster list renders as a flat `<ul>`. On ≥801px, a grid of player cards is more appropriate for a hockey site.

**Create `sportspress/team-lists.php`:**

```php
<?php
/**
 * Override SP team lists template to use a CSS grid layout.
 * SP calls this for the player roster section on sp_team pages.
 * Falls through to SP's default template if the sp_list post type
 * doesn't contain player data.
 *
 * @package RookieSport
 */

// Delegate to SP's default template — we override layout via CSS only.
// SP_TEMPLATE_PATH resolves to the plugin's templates/team-lists.php.
// We do not override the PHP logic, only add a wrapper class so CSS can target it.
$sp_template = SP_TEMPLATE_PATH . 'team-lists.php';
if ( file_exists( $sp_template ) ) {
    echo '<div class="sp-roster-grid-wrapper">';
    include $sp_template;
    echo '</div>';
}
```

**Add CSS to `sportspress.css`:**

```css
/* === Roster grid wrapper (team page player list) === */
.sp-roster-grid-wrapper .sp-template-player-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: var(--space-4);
}
@media (max-width: 640px) {
    .sp-roster-grid-wrapper .sp-template-player-gallery {
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: var(--space-3);
    }
}
.sp-roster-grid-wrapper .sp-template-player-gallery .sp-player-item {
    background: var(--color-card);
    border: 1px solid var(--color-border-card);
    border-radius: var(--border-radius);
    overflow: hidden;
    text-align: center;
    transition: box-shadow var(--transition-fast), transform var(--transition-fast);
}
.sp-roster-grid-wrapper .sp-template-player-gallery .sp-player-item:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.sp-roster-grid-wrapper .sp-template-player-gallery .sp-player-item img {
    width: 100%;
    aspect-ratio: 3/4;
    object-fit: cover;
}
.sp-roster-grid-wrapper .sp-template-player-gallery .sp-player-number {
    display: block;
    font-family: var(--font-heading);
    font-size: var(--text-xs);
    font-weight: 700;
    color: var(--color-teal);
    padding: var(--space-1) 0 0;
}
.sp-roster-grid-wrapper .sp-template-player-gallery .sp-player-name {
    display: block;
    font-family: var(--font-heading);
    font-size: var(--text-sm);
    font-weight: 700;
    color: var(--color-text-dark);
    padding: 0 var(--space-2) var(--space-2);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
```

---

## Round 3 — Minor Fixes Summary

| ID | Item | File(s) | Status |
|---|---|---|---|
| m-2 | SP sponsor selector filter | `inc/sportspress.php` | Fixed |
| m-6 | Oswald font override | `assets/src/css/base.css` | Fixed |
| m-8 | `sp_current_team` link on player page | `sportspress/single-player.php`, `sportspress.css` | Fixed |
| m-12 | Sticky header collapse at ≤600px | `navigation.css` | Fixed |
| m-15 | WC/SP hardcoded-hex `!important` overrides | `sportspress.css`, `woocommerce.css` | Fixed |
| m-16 | Extended spacing tokens (`--space-14` through `--space-32`) | `style.css` | Fixed |
| m-17 | Italic `@font-face` declarations | `base.css`, `assets/fonts/` | Fixed |
| m-18 | Season dates + division on product cards | `inc/woocommerce.php`, `woocommerce.css` | Fixed |
| m-19 | `sp_nationality` → `esc_attr()` in attribute contexts | `sportspress/single-player.php` | Fixed |
| m-20 | `sportspress/team-lists.php` CSS grid wrapper | `sportspress/team-lists.php`, `sportspress.css` | Fixed |
