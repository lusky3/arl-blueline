<?php
/**
 * Stand-ins for the template tags a Loop template (content.php) prints, so the template itself can be rendered.
 *
 * "The current post" is post ID 0 in blueline_test_state(): the shared stand-ins for get_the_title(),
 * get_permalink(), has_post_thumbnail() and get_post_type() already read that entry when called with no
 * argument, so a test seeds `$state['posts'][0]` and the template sees it as the post in the Loop.
 *
 * Loaded ONLY inside #[RunInSeparateProcess] tests: it defines blueline_entry_meta(), which the theme's own
 * template-tags.php defines for real.
 *
 * @package blueline
 */

if ( ! function_exists( 'the_ID' ) ) {
	/**
	 * Print the current post's ID.
	 *
	 * @return void
	 */
	function the_ID() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- core's own name.
		echo '0';
	}
}

if ( ! function_exists( 'post_class' ) ) {
	/**
	 * Print a class attribute carrying the given classes and the post type.
	 *
	 * @param string $extra_classes Extra classes.
	 * @return void
	 */
	function post_class( $extra_classes = '' ) {
		echo 'class="' . esc_attr( trim( $extra_classes . ' type-' . get_post_type() ) ) . '"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a test stand-in for core's own output function.
	}
}

if ( ! function_exists( 'the_permalink' ) ) {
	/**
	 * Print the current post's permalink.
	 *
	 * @return void
	 */
	function the_permalink() {
		echo esc_url( (string) get_permalink() );
	}
}

if ( ! function_exists( 'the_title' ) ) {
	/**
	 * Print the current post's title between the given markup.
	 *
	 * @param string $before Markup before the title.
	 * @param string $after  Markup after the title.
	 * @return void
	 */
	function the_title( $before = '', $after = '' ) {
		echo $before . esc_html( get_the_title() ) . $after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a test stand-in for core's own output function; the title is escaped, the markup is the caller's.
	}
}

if ( ! function_exists( 'has_excerpt' ) ) {
	/**
	 * Whether the current post carries a hand-written excerpt (state `excerpt`).
	 *
	 * @return bool
	 */
	function has_excerpt() {
		$state = &blueline_test_state();

		return '' !== ( $state['posts'][0]['excerpt'] ?? '' );
	}
}

if ( ! function_exists( 'the_excerpt' ) ) {
	/**
	 * Print the current post's excerpt (state `excerpt`).
	 *
	 * @return void
	 */
	function the_excerpt() {
		$state = &blueline_test_state();

		echo '<p>' . esc_html( (string) ( $state['posts'][0]['excerpt'] ?? 'Generated excerpt.' ) ) . '</p>';
	}
}

if ( ! function_exists( 'the_post_thumbnail' ) ) {
	/**
	 * Print the current post's thumbnail.
	 *
	 * @param string $size Image size.
	 * @return void
	 */
	function the_post_thumbnail( $size = 'post-thumbnail' ) {
		echo get_the_post_thumbnail( 0, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a test stand-in for core's own output function.
	}
}

if ( ! function_exists( 'blueline_entry_meta' ) ) {
	/**
	 * The byline/date block; its own tests live elsewhere, so a marker stands in.
	 *
	 * @return void
	 */
	function blueline_entry_meta() {
		echo '<div class="bl-entry-meta">byline</div>';
	}
}
