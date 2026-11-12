<?php
/**
 * Add a request by hand: pick a site customer or type a name / e-mail /
 * handle, then the fish, the size, the quantity, where it came from, a
 * note and an optional fee. It is approved as it is saved.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string   $tab        Current tab.
 * @var array    $ctx        Admin::context().
 * @var array    $flash      Admin::take_flash().
 * @var array    $needs      Listing::needs_you().
 * @var array    $keep       A refused form's values.
 * @var string[] $came_froms The settings' "came from" list.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;

$fh_value = static function ( $key, $default = '' ) use ( $keep ) {
	return isset( $keep[ $key ] ) && is_scalar( $keep[ $key ] ) ? (string) $keep[ $key ] : (string) $default;
};
$fh_sizes = $ctx['sizes'];
$fh_user  = (int) $fh_value( 'user_id', 0 );
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fishotel-misc-card fh-rq-card fh-rq-form">
		<?php wp_nonce_field( Admin::NONCE ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( Admin::ACTION ); ?>">
		<input type="hidden" name="op" value="add">
		<input type="hidden" name="back" value="<?php echo esc_attr( Admin::page_url( 'add' ) ); ?>">

		<div class="fishotel-misc-card-header">
			<div class="fishotel-misc-card-icon"><span class="dashicons dashicons-plus-alt2"></span></div>
			<h2 class="fishotel-misc-card-title"><?php esc_html_e( 'Add a request by hand', 'fishotel-misc-plugin' ); ?></h2>
		</div>
		<p class="fishotel-misc-card-desc"><?php esc_html_e( 'For the requests that reach you by e-mail, by phone, in person or on the forum. It is approved as you save it and goes straight on the list. Nobody is e-mailed — unless you set a fee (the invoice), and later when the fish is ready.', 'fishotel-misc-plugin' ); ?></p>

		<h3 class="fh-rq-card__sub"><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></h3>
		<div class="fh-rq-grid">
			<label class="fh-rq-grid__wide fh-rq-customer">
				<span><?php esc_html_e( 'Find a site customer (type a name or an e-mail) — or skip this and fill in the fields below', 'fishotel-misc-plugin' ); ?></span>
				<input type="search" class="fh-rq-input fh-rq-customer__search" autocomplete="off" placeholder="<?php esc_attr_e( 'search customers…', 'fishotel-misc-plugin' ); ?>">
				<input type="hidden" name="user_id" class="fh-rq-customer__id" value="<?php echo esc_attr( $fh_user > 0 ? (string) $fh_user : '' ); ?>">
				<span class="fh-rq-customer__results" aria-live="polite"></span>
				<span class="fh-rq-customer__picked fh-rq-sub"><?php echo $fh_user > 0 ? esc_html( sprintf( /* translators: %d: user id */ __( 'Site customer #%d is picked.', 'fishotel-misc-plugin' ), $fh_user ) ) : ''; ?></span>
			</label>
			<label><span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="name" value="<?php echo esc_attr( $fh_value( 'name' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_NAME ); ?>"></label>
			<label><span><?php esc_html_e( 'E-mail (needed for a fee, and to be told when the fish is ready)', 'fishotel-misc-plugin' ); ?></span><input type="email" class="fh-rq-input" name="email" value="<?php echo esc_attr( $fh_value( 'email' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_EMAIL ); ?>"></label>
			<label><span><?php esc_html_e( 'Forum handle', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="handle" value="<?php echo esc_attr( $fh_value( 'handle' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_HANDLE ); ?>"></label>
		</div>

		<h3 class="fh-rq-card__sub"><?php esc_html_e( 'What', 'fishotel-misc-plugin' ); ?></h3>
		<div class="fh-rq-grid">
			<label class="fh-rq-grid__wide">
				<span><?php esc_html_e( 'Fish (type for suggestions from the species list and the fish products — anything else is kept as you type it)', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-rq-input fh-rq-fish" name="fish" value="<?php echo esc_attr( $fh_value( 'fish' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_FISH ); ?>" list="fh-rq-names" autocomplete="off" required>
			</label>
			<label><span><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></span>
				<?php if ( is_array( $fh_sizes ) ) : ?>
					<select class="fh-rq-input" name="size_slug">
						<option value=""><?php esc_html_e( 'any', 'fishotel-misc-plugin' ); ?></option>
						<?php foreach ( $fh_sizes as $fh_size ) : ?>
							<option value="<?php echo esc_attr( $fh_size['slug'] ); ?>" <?php selected( $fh_value( 'size_slug' ), (string) $fh_size['slug'] ); ?>><?php echo esc_html( $fh_size['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" class="fh-rq-input" name="size_label" value="<?php echo esc_attr( $fh_value( 'size_label' ) ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_SIZE ); ?>" placeholder="<?php esc_attr_e( 'any', 'fishotel-misc-plugin' ); ?>">
				<?php endif; ?>
			</label>
			<label><span><?php esc_html_e( 'Quantity', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="qty" value="<?php echo esc_attr( $fh_value( 'qty', 1 ) ); ?>" min="1" max="<?php echo esc_attr( Rules::MAX_QTY ); ?>"></label>
			<label><span><?php esc_html_e( 'Came from', 'fishotel-misc-plugin' ); ?></span>
				<select class="fh-rq-input" name="came_from">
					<option value="">—</option>
					<?php foreach ( $came_froms as $fh_from ) : ?>
						<option value="<?php echo esc_attr( $fh_from ); ?>" <?php selected( Rules::name_key( $fh_value( 'came_from' ) ), Rules::name_key( $fh_from ) ); ?>><?php echo esc_html( $fh_from ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="fh-rq-grid__wide"><span><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></span><textarea class="fh-rq-input" name="note" rows="2" maxlength="<?php echo esc_attr( Rules::MAX_NOTE ); ?>"><?php echo esc_textarea( $fh_value( 'note' ) ); ?></textarea></label>
		</div>

		<h3 class="fh-rq-card__sub"><?php esc_html_e( 'Money', 'fishotel-misc-plugin' ); ?></h3>
		<div class="fh-rq-grid">
			<label>
				<span><?php esc_html_e( 'Fee (empty = none)', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" class="fh-rq-input fh-rq-input--money" name="fee" value="<?php echo esc_attr( $ctx['fees']['available'] ? $fh_value( 'fee' ) : '' ); ?>" placeholder="0.00" inputmode="decimal" <?php disabled( ! $ctx['fees']['available'] ); ?>>
				<span class="fh-rq-sub">
					<?php
					echo esc_html(
						$ctx['fees']['available']
							? __( 'With a fee the request waits for the payment: the invoice is e-mailed with a pay link, and the money goes into the customer\'s wallet.', 'fishotel-misc-plugin' )
							/* translators: %s: why */
							: sprintf( __( 'Unavailable: %s.', 'fishotel-misc-plugin' ), $ctx['fees']['reason'] )
					);
					?>
				</span>
			</label>
			<label class="fh-rq-check fh-rq-grid__wide">
				<input type="checkbox" name="owed" value="1" <?php checked( '1' === $fh_value( 'owed' ) ); ?>>
				<?php esc_html_e( 'A replacement for a lost fish: it is OWED (already paid for) — never a fee, and the "ready" e-mail says there is nothing to pay.', 'fishotel-misc-plugin' ); ?>
			</label>
		</div>

		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the request', 'fishotel-misc-plugin' ); ?></button></p>
		<datalist id="fh-rq-names"></datalist>
	</form>
</div>
