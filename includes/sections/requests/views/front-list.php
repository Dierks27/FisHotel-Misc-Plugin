<?php
/**
 * "My requests": a person's own requests in plain words, each open one
 * with Cancel. Used by the personal link's page and by My Account →
 * Requests.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var array $fh_list heading, intro, requests (Front::my_rows()), flash, action, nonce, hidden (name →
 *                     value), plain (bool: the section's own page rather than My Account).
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Front;
?>
<div class="fh-rq-list<?php echo $fh_list['plain'] ? ' fh-rq-card' : ' fh-rq-list--account'; ?>">
	<?php if ( '' !== $fh_list['heading'] ) : ?>
		<h1><?php echo esc_html( $fh_list['heading'] ); ?></h1>
	<?php endif; ?>
	<?php if ( '' !== $fh_list['intro'] ) : ?>
		<p class="fh-rq-small"><?php echo esc_html( $fh_list['intro'] ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $fh_list['flash'] ) : ?>
		<p class="fh-rq-flash" role="status"><?php echo esc_html( $fh_list['flash'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! $fh_list['requests'] ) : ?>
		<p><?php esc_html_e( 'There are no requests here at the moment.', 'fishotel-misc-plugin' ); ?></p>
	<?php else : ?>
		<ul class="fh-rq-mine">
			<?php foreach ( $fh_list['requests'] as $fh_request ) : ?>
				<li class="fh-rq-mine__item<?php echo $fh_request['open'] ? '' : ' is-closed'; ?>">
					<div class="fh-rq-mine__what">
						<strong><?php echo esc_html( $fh_request['fish'] ); ?></strong>
						<span class="fh-rq-mine__state"><?php echo esc_html( $fh_request['words'] ); ?></span>
						<span class="fh-rq-small"><?php echo esc_html( $fh_request['asked'] ); ?></span>
					</div>
					<?php if ( $fh_request['can_cancel'] ) : ?>
						<form method="post" action="<?php echo esc_url( $fh_list['action'] ); ?>" onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( __( 'Cancel this request?', 'fishotel-misc-plugin' ) ) ); ?> );">
							<input type="hidden" name="<?php echo esc_attr( Front::NONCE_FIELD ); ?>" value="<?php echo esc_attr( $fh_list['nonce'] ); ?>">
							<?php foreach ( $fh_list['hidden'] as $fh_key => $fh_value ) : ?>
								<input type="hidden" name="<?php echo esc_attr( $fh_key ); ?>" value="<?php echo esc_attr( $fh_value ); ?>">
							<?php endforeach; ?>
							<button type="submit" class="fh-rq-button fh-rq-button--quiet" name="cancel" value="<?php echo esc_attr( $fh_request['id'] ); ?>"><?php esc_html_e( 'Cancel', 'fishotel-misc-plugin' ); ?></button>
						</form>
					<?php elseif ( $fh_request['open'] && $fh_request['owed'] ) : ?>
						<span class="fh-rq-small"><?php esc_html_e( 'Already paid for — to change it, please contact us.', 'fishotel-misc-plugin' ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
