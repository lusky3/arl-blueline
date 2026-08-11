<?php
/**
 * Comment list and comment form.
 *
 * @package blueline
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<div id="comments" class="bl-comments">

	<?php if ( have_comments() ) : ?>

		<h2 class="bl-comments__title">
			<?php
			/* translators: %s: number of comments. */
			$comments_title = _nx(
				'%s comment',
				'%s comments',
				get_comments_number(),
				'comments title',
				'blueline'
			);

			printf(
				esc_html( $comments_title ),
				esc_html( number_format_i18n( get_comments_number() ) )
			);
			?>
		</h2>

		<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
			<nav class="bl-comments__nav" aria-label="<?php esc_attr_e( 'Comments', 'blueline' ); ?>">
				<div class="bl-comments__nav-previous"><?php previous_comments_link( esc_html__( '&larr; Older comments', 'blueline' ) ); ?></div>
				<div class="bl-comments__nav-next"><?php next_comments_link( esc_html__( 'Newer comments &rarr;', 'blueline' ) ); ?></div>
			</nav>
		<?php endif; ?>

		<ol class="bl-comments__list">
			<?php
			wp_list_comments(
				array(
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 48,
				)
			);
			?>
		</ol>

		<?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
			<nav class="bl-comments__nav" aria-label="<?php esc_attr_e( 'Comments', 'blueline' ); ?>">
				<div class="bl-comments__nav-previous"><?php previous_comments_link( esc_html__( '&larr; Older comments', 'blueline' ) ); ?></div>
				<div class="bl-comments__nav-next"><?php next_comments_link( esc_html__( 'Newer comments &rarr;', 'blueline' ) ); ?></div>
			</nav>
		<?php endif; ?>

	<?php endif; ?>

	<?php if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) : ?>
		<p class="bl-comments__closed"><?php esc_html_e( 'Comments are closed.', 'blueline' ); ?></p>
	<?php endif; ?>

	<?php
	comment_form(
		array(
			'title_reply' => 0 === (int) get_comments_number()
				? __( 'Start the conversation', 'blueline' )
				: __( 'Join the conversation', 'blueline' ),
		)
	);
	?>
</div>
