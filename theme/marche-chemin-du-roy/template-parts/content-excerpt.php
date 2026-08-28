<?php
/**
 * Post summary in a list.
 *
 * @package MCR
 */

defined( 'ABSPATH' ) || exit;
?>
<article <?php post_class( 'panel post-card' ); ?>>
	<?php if ( has_post_thumbnail() ) : ?>
		<a class="post-card-media" href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
			<?php the_post_thumbnail( 'mcr-card' ); ?>
		</a>
	<?php endif; ?>

	<div>
		<p class="lbl">
			<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
		</p>
		<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
		<p class="post-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 28 ) ); ?></p>
	</div>
</article>
