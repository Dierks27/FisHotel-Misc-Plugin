<?php
/**
 * The private links: create one (a label, an optional fee, expiry and
 * maximum uses), copy it, switch it off. The request form exists only
 * behind such a link.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string  $tab   Current tab.
 * @var array   $ctx   Admin::context().
 * @var array   $flash Admin::take_flash().
 * @var array   $needs Listing::needs_you().
 * @var array   $keep  A refused form's values.
 * @var array[] $links Store::links().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Urls;

$fh_value  = static function ( $key ) use ( $keep ) {
	return isset( $keep[ $key ] ) && is_scalar( $keep[ $key ] ) ? (string) $keep[ $key ] : '';
};
$fh_now    = (int) $ctx['now'];
$fh_symbol = Urls::currency_symbol();
$fh_here   = Admin::page_url( 'links' );
$fh_words  = array(
	''         => __( 'works', 'fishotel-misc-plugin' ),
	'inactive' => __( 'switched off', 'fishotel-misc-plugin' ),
	'expired'  => __( 'expired', 'fishotel-misc-plugin' ),
	'used_up'  => __( 'used up', 'fishotel-misc-plugin' ),
);
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-rq-card fh-rq-form">
		<?php wp_nonce_field( Admin::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
		<input type="hidden" name="op" value="link_create">
		<input type="hidden" name="back" value="<?php echo esc_attr( $fh_here ); ?>">

		<div class="fishotel-misc-card-header">
			<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-admin-links"></span></div>
			<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'A new private link', 'fishotel-misc-plugin' ); ?></h2>
		</div>
		<p class="fishotel-misc-card-desc"><?php esc_html_e( 'The request form is not on the site: it opens only through a link you make here and give to somebody — in an order e-mail, a message, a forum reply. Whoever has the link can fill in the form (no login needed), so give it an expiry or a number of uses when it should not travel. What comes in waits for you under "Needs you".', 'fishotel-misc-plugin' ); ?></p>

		<div class="fh-rq-grid">
			<label class="fh-rq-grid__wide"><span><?php esc_html_e( 'Label (a batch name, a person, an occasion — only you see it)', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="label" value="<?php echo esc_attr( $fh_value( 'label' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_LABEL ); ?>" required></label>
			<label>
				<span><?php esc_html_e( 'Fee (empty = none)', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-rq-input fh-rq-input--money" name="fee" value="<?php echo esc_attr( $fh_value( 'fee' ) ); ?>" placeholder="0.00" inputmode="decimal">
				<span class="fh-rq-sub"><?php esc_html_e( 'Shown on the form, and offered as the fee of each sheet when you approve it (you can still change it then). One fee per sheet, however many fish are on it.', 'fishotel-misc-plugin' ); ?></span>
			</label>
			<label><span><?php esc_html_e( 'Works until the end of (empty = no expiry)', 'fishotel-misc-plugin' ); ?></span><input type="date" class="fh-rq-input" name="expires" value="<?php echo esc_attr( $fh_value( 'expires' ) ); ?>"></label>
			<label><span><?php esc_html_e( 'Maximum uses (empty = no limit; one sheet = one use)', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="max_uses" value="<?php echo esc_attr( $fh_value( 'max_uses' ) ); ?>" min="0" max="<?php echo esc_attr( Rules::MAX_USES ); ?>"></label>
		</div>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Make the link', 'fishotel-misc-plugin' ); ?></button></p>
	</form>

	<?php if ( ! $links ) : ?>
		<div class="fishotel-misc-empty">
			<span class="dashicons dashicons-admin-links"></span>
			<p><?php esc_html_e( 'No link yet.', 'fishotel-misc-plugin' ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat fh-rq-table fh-rq-links">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Label', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'The link', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-rq-num"><?php esc_html_e( 'Fee', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Until', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-rq-num"><?php esc_html_e( 'Uses', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col" class="fh-rq-num"><?php esc_html_e( 'Requests', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'fishotel-misc-plugin' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $fh_link ) : ?>
					<?php
					$fh_problem = Rules::link_problem( $fh_link, $fh_now );
					$fh_url     = Urls::form( $fh_link['token'] );
					$fh_maker   = $fh_link['created_by'] > 0 ? Requests::user( $fh_link['created_by'] ) : null;
					?>
					<tr class="<?php echo '' === $fh_problem ? '' : 'is-off'; ?>">
						<td>
							<strong><?php echo esc_html( $fh_link['label'] ); ?></strong>
							<span class="fh-rq-sub"><?php echo esc_html( Admin::when( $fh_link['created_at'] ) . ( $fh_maker ? ' · ' . $fh_maker['name'] : '' ) ); ?></span>
						</td>
						<td>
							<span class="fh-rq-copy__row">
								<input type="text" class="fh-rq-input" readonly value="<?php echo esc_attr( $fh_url ); ?>" onfocus="this.select()" aria-label="<?php esc_attr_e( 'The link', 'fishotel-misc-plugin' ); ?>">
								<button type="button" class="button button-small" data-copy="<?php echo esc_attr( $fh_url ); ?>"><?php esc_html_e( 'Copy', 'fishotel-misc-plugin' ); ?></button>
							</span>
						</td>
						<td class="fh-rq-num"><?php echo $fh_link['fee_amount'] > 0 ? esc_html( Rules::money( $fh_link['fee_amount'], $fh_symbol ) ) : '—'; ?></td>
						<td><?php echo null !== $fh_link['expires_at'] ? esc_html( Admin::when( $fh_link['expires_at'] ) ) : '—'; ?></td>
						<td class="fh-rq-num"><?php echo esc_html( $fh_link['uses'] . ( $fh_link['max_uses'] > 0 ? ' / ' . $fh_link['max_uses'] : '' ) ); ?></td>
						<td class="fh-rq-num"><?php echo esc_html( (string) $fh_link['request_count'] ); ?></td>
						<td><span class="fh-rq-badge fh-rq-badge--<?php echo '' === $fh_problem ? 'ready' : 'cancelled'; ?>"><?php echo esc_html( $fh_words[ $fh_problem ] ?? $fh_problem ); ?></span></td>
						<td class="fh-rq-row__open">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fh-rq-inline">
								<?php wp_nonce_field( Admin::NONCE ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
								<input type="hidden" name="op" value="link_toggle">
								<input type="hidden" name="back" value="<?php echo esc_attr( $fh_here ); ?>">
								<input type="hidden" name="link_id" value="<?php echo esc_attr( $fh_link['id'] ); ?>">
								<?php if ( $fh_link['active'] ) : ?>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Switch off', 'fishotel-misc-plugin' ); ?></button>
								<?php else : ?>
									<input type="hidden" name="active" value="1">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Switch on', 'fishotel-misc-plugin' ); ?></button>
								<?php endif; ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="fh-rq-sub"><?php esc_html_e( 'A link is never deleted, so the requests that came through it keep saying where they came from. Switched off, expired or used up, its page only says so.', 'fishotel-misc-plugin' ); ?></p>
	<?php endif; ?>
</div>
