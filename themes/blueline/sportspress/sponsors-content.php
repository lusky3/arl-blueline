<?php
/**
 * Theme override of SportsPress Sponsors' sponsors-content.php: the list of sponsor logos that
 * sponsors.php (the [sponsors] shortcode, the header and footer bands) prints directly, and that the
 * plugin's sp_sponsors AJAX loader prints for a randomised band. Both load it through sp_get_template(),
 * which checks this theme's sportspress/ directory first.
 *
 * The one change from stock: each sponsor's wrapper comes from blueline_sp_sponsor_tags()
 * (inc/sportspress/core.php). Stock printed an <a> with no href for a sponsor without a website, and
 * printed the stored URL unescaped; now that sponsor is a plain <span>, and a sponsor with a website is a
 * real, escaped, rel="sponsored" link. Otherwise stock behaviour, written to this theme's coding
 * standard (the logo-less sponsor name is escaped, the size test's operator precedence is spelled out).
 *
 * @package blueline
 * @version 2.6.5 (copied from SportsPress Pro 2.7.29: sportspress-pro/includes/sportspress-sponsors/templates/sponsors-content.php)
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

extract( $defaults, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- mirrors stock's own extract() of the args this template receives; EXTR_SKIP never overwrites one that is set.

if ( ( 'sportspress-fit-icon' === $size && (int) $height > 128 ) || (int) $width > 128 ) {
	$size = 'sportspress-fit-medium';
}

$args = array(
	'post_type'      => 'sp_sponsor',
	'numberposts'    => $limit,
	'posts_per_page' => $limit,
	'orderby'        => $orderby,
	'order'          => $order,
);

if ( $level ) {
	$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- stock's sponsor-level filter, a handful of posts.
		array(
			'taxonomy' => 'sp_level',
			'field'    => 'term_id',
			'terms'    => array( $level ),
		),
	);
}

$sponsors = get_posts( $args );

if ( $sponsors ) :
	foreach ( $sponsors as $sponsor ) :
		list( $blueline_sponsor_open, $blueline_sponsor_close ) = blueline_sp_sponsor_tags( $sponsor );
		echo $blueline_sponsor_open; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in blueline_sp_sponsor_tags().
		if ( has_post_thumbnail( $sponsor->ID ) ) :
			$blueline_logo_attr = array(
				'class' => 'sp-sponsor-logo',
				'title' => $sponsor->post_title,
			);
			if ( $width && $height ) :
				$blueline_logo_attr['style'] = 'max-width:' . $width . 'px; max-height:' . $height . 'px;';
			endif;
			echo get_the_post_thumbnail( $sponsor->ID, $size, $blueline_logo_attr ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-built image markup.
		else :
			echo esc_html( $sponsor->post_title );
		endif;
		sp_set_post_impressions( $sponsor->ID );
		echo $blueline_sponsor_close; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed closing tag.
	endforeach;
endif;
