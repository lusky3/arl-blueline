<?php
/**
 * Theme override of SportsPress Sponsors' own [sponsors] shortcode template
 * (sportspress-pro/includes/sportspress-sponsors/templates/sponsors.php --
 * loaded via that same file's own sp_get_template() call, which already
 * checks this theme's sportspress/ directory first).
 *
 * The one change from stock: the sponsor-block title ("The ARL extends a
 * very special thanks to...", SportsPress_Sponsors::footer()'s own
 * `sportspress_footer_sponsors_title` option) hardcodes <h3>, unconditionally,
 * on every page it renders on -- confirmed live, 2026-09-04 UX audit, as an
 * h1 -> h3 skip sitewide (this footer block sits directly after the page's
 * own h1/h2 content with nothing between). Same context-aware level as
 * league-table.php/event-list.php/team-lists.php/player-statistics-league.php/
 * countdown.php (blueline_sp_caption_heading_level()'s own docblock) --
 * everything else in this file is an unmodified copy of stock.
 *
 * Overrides SportsPress Sponsors templates/sponsors.php, core template version 2.6.5 as of
 * SportsPress Pro 2.7.29; re-check this override when that version changes.
 *
 * @package blueline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$defaults = array(
	'title'   => null,
	'level'   => 0,
	'limit'   => -1,
	'width'   => 256,
	'height'  => 128,
	'orderby' => 'menu_order',
	'order'   => 'ASC',
	'size'    => 'sportspress-fit-icon',
);

extract( $defaults, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors stock sponsors.php's own extract() convention for the args this template receives; EXTR_SKIP never overwrites an already-set variable.

$blueline_title_level = blueline_sp_sponsors_title_level( doing_action( 'get_footer' ) );

if ( 'rand' === $orderby ) :
	?>
	<div class="sp-sponsors">
		<?php if ( ! empty( $title ) ) : ?>
			<?php printf( '<h%1$d class="sp-sponsors-title">%2$s</h%1$d>', $blueline_title_level, esc_html( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $blueline_title_level is always the int 2 or 3 blueline_sp_sponsors_title_level() returns, never user input; $title is escaped via esc_html() above. ?>
		<?php endif; ?>
		<div class="sp-sponsors-loader"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'sp_sponsors' ) ); ?>"
			data-ajaxurl="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-level="<?php echo esc_attr( $level ); ?>"
			data-limit="<?php echo esc_attr( $limit ); ?>"
			data-width="<?php echo esc_attr( $width ); ?>"
			data-height="<?php echo esc_attr( $height ); ?>"
			data-size="<?php echo esc_attr( $size ); ?>"></div>
	</div>
	<?php
	return;
else :
	?>
<div class="sp-sponsors">
	<?php if ( ! empty( $title ) ) : ?>
		<?php printf( '<h%1$d class="sp-sponsors-title">%2$s</h%1$d>', $blueline_title_level, esc_html( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see above. ?>
	<?php endif; ?>
	<?php
	sp_get_template(
		'sponsors-content.php',
		array(
			'level'   => $level,
			'limit'   => $limit,
			'width'   => $width,
			'height'  => $height,
			'orderby' => $orderby,
			'order'   => $order,
			'size'    => $size,
		),
		'',
		SP_SPONSORS_DIR . 'templates/'
	);
	?>
</div>
	<?php
endif;
