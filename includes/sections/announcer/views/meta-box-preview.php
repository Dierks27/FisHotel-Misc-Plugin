<?php
/**
 * Live preview meta box.
 *
 * @package FisHotel\Misc\Sections\Announcer
 * @var \WP_Post $post The current post.
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="ancr-preview-wrap">
	<p class="ancr-desc"><?php esc_html_e( 'Save the announcement and visit your site to see it live, or click the button below.', 'fishotel-misc-plugin' ); ?></p>
	<?php
	$home = home_url( '/' );
	?>
	<a href="<?php echo esc_url( $home ); ?>" target="_blank" class="button" id="ancr-preview-btn">
		<?php esc_html_e( 'Preview on Site', 'fishotel-misc-plugin' ); ?> &#8599;
	</a>
</div>
