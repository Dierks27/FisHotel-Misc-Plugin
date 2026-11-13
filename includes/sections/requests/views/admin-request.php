<?php
/**
 * One request's own page: who and what, the next step for its state
 * (approve / decline, the invoice, the match to a supplier order, tell
 * them, filled, cancel, reopen), an edit form, and its whole history from
 * the log with a note field.
 *
 * @package FisHotel\Misc\Sections\Requests
 *
 * @var string   $tab        Current tab.
 * @var array    $ctx        Admin::context().
 * @var array    $flash      Admin::take_flash().
 * @var array    $needs      Listing::needs_you().
 * @var bool     $missing    Whether the request does not exist.
 * @var array    $request    The request (when it exists).
 * @var array    $detail     Admin::request_detail() (when it exists).
 * @var string[] $came_froms The settings' "came from" list.
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Requests\Admin;
use FisHotel\Misc\Sections\Requests\Requests;
use FisHotel\Misc\Sections\Requests\Rules;
use FisHotel\Misc\Sections\Requests\Service;
use FisHotel\Misc\Sections\Requests\Urls;

if ( $missing ) {
	?>
	<div class="wrap fishotel-misc-wrap fh-rq-wrap">
		<?php include Requests::path() . 'views/admin-tabs.php'; ?>
		<div class="fishotel-misc-notice fishotel-misc-notice--error"><?php esc_html_e( 'That request does not exist.', 'fishotel-misc-plugin' ); ?></div>
		<p><a class="button" href="<?php echo esc_url( Admin::page_url() ); ?>">&larr; <?php esc_html_e( 'Requests', 'fishotel-misc-plugin' ); ?></a></p>
	</div>
	<?php
	return;
}

$fh_id     = (int) $request['id'];
$fh_state  = (string) $request['state'];
$fh_now    = (int) $ctx['now'];
$fh_symbol = Urls::currency_symbol();
$fh_here   = Admin::request_url( $fh_id );
$fh_fees   = $ctx['fees'];
$fh_sizes  = $ctx['sizes'];

/**
 * The opening of an action form.
 *
 * @param string $op    The operation.
 * @param string $class Extra class.
 */
$fh_open = static function ( $op, $class = '' ) use ( $fh_here, $fh_id ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fh-rq-form ' . esc_attr( $class ) . '">';
	wp_nonce_field( Admin::NONCE );
	echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION ) . '">';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	echo '<input type="hidden" name="back" value="' . esc_attr( $fh_here ) . '">';
	echo '<input type="hidden" name="id" value="' . esc_attr( $fh_id ) . '">';
};

$fh_open_states = Rules::is_open( $fh_state );
?>
<div class="wrap fishotel-misc-wrap fh-rq-wrap">
	<?php include Requests::path() . 'views/admin-tabs.php'; ?>

	<div class="fh-rq-toolbar">
		<a class="button" href="<?php echo esc_url( Admin::page_url() ); ?>">&larr; <?php esc_html_e( 'Requests', 'fishotel-misc-plugin' ); ?></a>
		<h2 class="fh-rq-title">
			#<?php echo esc_html( (string) $fh_id ); ?> — <?php echo esc_html( Rules::fish_line( $request ) ); ?>
			<?php echo Admin::badge( $request ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by badge(). ?>
		</h2>
	</div>

	<div class="fh-rq-columns">
		<div class="fh-rq-column">

			<section class="fishotel-misc-card fh-rq-card">
				<h3 class="fh-rq-card__title"><?php esc_html_e( 'Who and what', 'fishotel-misc-plugin' ); ?></h3>
				<dl class="fh-rq-facts">
					<div><dt><?php esc_html_e( 'Who', 'fishotel-misc-plugin' ); ?></dt><dd>
						<?php echo esc_html( Rules::who( $request ) ); ?>
						<?php if ( '' !== $request['handle'] ) : ?>
							<span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( sprintf( /* translators: %s: forum handle */ __( 'forum: %s', 'fishotel-misc-plugin' ), $request['handle'] ) ); ?></span>
						<?php endif; ?>
					</dd></div>
					<div><dt><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo '' !== $request['email'] ? esc_html( $request['email'] ) : '<span class="fh-rq-sub fh-rq-sub--inline">' . esc_html__( 'none — nothing can be e-mailed', 'fishotel-misc-plugin' ) . '</span>'; ?></dd></div>
					<div><dt><?php esc_html_e( 'Site customer', 'fishotel-misc-plugin' ); ?></dt><dd>
						<?php if ( $detail['user'] ) : ?>
							<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $detail['user']['id'] ) ); ?>"><?php echo esc_html( $detail['user']['name'] ); ?></a>
							<span class="fh-rq-sub fh-rq-sub--inline"><?php esc_html_e( 'sees it under My Account → Requests', 'fishotel-misc-plugin' ); ?></span>
						<?php else : ?>
							<span class="fh-rq-sub fh-rq-sub--inline"><?php esc_html_e( 'no account — sees it through the personal link in each e-mail', 'fishotel-misc-plugin' ); ?></span>
						<?php endif; ?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></dt><dd>
						<?php echo esc_html( Rules::fish_line( $request ) ); ?>
						<span class="fh-rq-sub fh-rq-sub--inline">
							<?php
							if ( $request['species_id'] ) {
								esc_html_e( 'matched to the species list', 'fishotel-misc-plugin' );
							} elseif ( $request['product_id'] ) {
								esc_html_e( 'matched to a product', 'fishotel-misc-plugin' );
							} else {
								esc_html_e( 'as typed — not in the species list', 'fishotel-misc-plugin' );
							}
							?>
						</span>
					</dd></div>
					<?php if ( '' !== $request['note'] ) : ?>
						<div><dt><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></dt><dd class="fh-rq-pre"><?php echo esc_html( $request['note'] ); ?></dd></div>
					<?php endif; ?>
					<div><dt><?php esc_html_e( 'Came through', 'fishotel-misc-plugin' ); ?></dt><dd>
						<?php echo esc_html( Rules::source_label( $request['source'] ) ); ?>
						<?php if ( $detail['link'] ) : ?>
							“<?php echo esc_html( $detail['link']['label'] ); ?>”
						<?php endif; ?>
						<?php if ( '' !== $request['came_from'] ) : ?>
							· <?php echo esc_html( $request['came_from'] ); ?>
						<?php endif; ?>
						<?php if ( (int) $request['from_order_id'] > 0 ) : ?>
							·
							<?php if ( '' !== $detail['from_order_url'] ) : ?>
								<a href="<?php echo esc_url( $detail['from_order_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %d: order number */ __( 'order #%d', 'fishotel-misc-plugin' ), (int) $request['from_order_id'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( sprintf( /* translators: %d: order number */ __( 'order #%d', 'fishotel-misc-plugin' ), (int) $request['from_order_id'] ) ); ?>
							<?php endif; ?>
						<?php endif; ?>
					</dd></div>
					<div><dt><?php esc_html_e( 'Asked', 'fishotel-misc-plugin' ); ?></dt><dd><?php echo esc_html( Admin::when( $request['created_at'] ) ); ?> <span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( Rules::age_words( Rules::ts( $request['created_at'] ), $fh_now ) ); ?></span></dd></div>
					<?php if ( $detail['mates'] ) : ?>
						<div><dt><?php esc_html_e( 'Same sheet', 'fishotel-misc-plugin' ); ?></dt><dd>
							<?php foreach ( $detail['mates'] as $fh_mate ) : ?>
								<a href="<?php echo esc_url( Admin::request_url( $fh_mate['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_mate['id'] ); ?> <?php echo esc_html( Rules::fish_line( $fh_mate ) ); ?></a> <span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( Rules::state_label( $fh_mate['state'] ) ); ?></span><br>
							<?php endforeach; ?>
						</dd></div>
					<?php endif; ?>
				</dl>
			</section>

			<section class="fishotel-misc-card fh-rq-card">
				<h3 class="fh-rq-card__title"><?php esc_html_e( 'Correct it', 'fishotel-misc-plugin' ); ?></h3>
				<?php $fh_open( 'edit', 'fh-rq-grid' ); ?>
					<input type="hidden" name="seen" value="<?php echo esc_attr( Rules::seen( $request ) ); ?>">
					<label><span><?php esc_html_e( 'Name', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="name" value="<?php echo esc_attr( $request['name'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_NAME ); ?>"></label>
					<label><span><?php esc_html_e( 'E-mail', 'fishotel-misc-plugin' ); ?></span><input type="email" class="fh-rq-input" name="email" value="<?php echo esc_attr( $request['email'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_EMAIL ); ?>"></label>
					<label><span><?php esc_html_e( 'Forum handle', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input" name="handle" value="<?php echo esc_attr( $request['handle'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_HANDLE ); ?>"></label>
					<label class="fh-rq-grid__wide"><span><?php esc_html_e( 'Fish', 'fishotel-misc-plugin' ); ?></span><input type="text" class="fh-rq-input fh-rq-fish" name="fish" value="<?php echo esc_attr( $request['fish'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_FISH ); ?>" list="fh-rq-names" autocomplete="off"></label>
					<label><span><?php esc_html_e( 'Size', 'fishotel-misc-plugin' ); ?></span>
						<?php if ( is_array( $fh_sizes ) ) : ?>
							<?php $fh_listed = false; ?>
							<select class="fh-rq-input" name="size_slug">
								<option value=""><?php esc_html_e( 'any', 'fishotel-misc-plugin' ); ?></option>
								<?php foreach ( $fh_sizes as $fh_size ) : ?>
									<?php $fh_listed = $fh_listed || (string) $fh_size['slug'] === (string) $request['size_slug']; ?>
									<option value="<?php echo esc_attr( $fh_size['slug'] ); ?>" <?php selected( (string) $request['size_slug'], (string) $fh_size['slug'] ); ?>><?php echo esc_html( $fh_size['label'] ); ?></option>
								<?php endforeach; ?>
								<?php if ( ! $fh_listed && null !== $request['size_slug'] ) : ?>
									<option value="<?php echo esc_attr( $request['size_slug'] ); ?>" selected><?php echo esc_html( sprintf( /* translators: %s: size */ __( '%s (not a standard size)', 'fishotel-misc-plugin' ), (string) $request['size_label'] ) ); ?></option>
								<?php endif; ?>
							</select>
						<?php else : ?>
							<input type="text" class="fh-rq-input" name="size_label" value="<?php echo esc_attr( (string) $request['size_label'] ); ?>" maxlength="<?php echo esc_attr( Rules::MAX_SIZE ); ?>">
						<?php endif; ?>
					</label>
					<label><span><?php esc_html_e( 'Quantity', 'fishotel-misc-plugin' ); ?></span><input type="number" class="fh-rq-input" name="qty" value="<?php echo esc_attr( (string) $request['qty'] ); ?>" min="1" max="<?php echo esc_attr( Rules::MAX_QTY ); ?>"></label>
					<label><span><?php esc_html_e( 'Came from', 'fishotel-misc-plugin' ); ?></span>
						<select class="fh-rq-input" name="came_from">
							<option value="">—</option>
							<?php foreach ( $came_froms as $fh_from ) : ?>
								<option value="<?php echo esc_attr( $fh_from ); ?>" <?php selected( Rules::name_key( $request['came_from'] ), Rules::name_key( $fh_from ) ); ?>><?php echo esc_html( $fh_from ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="fh-rq-grid__wide"><span><?php esc_html_e( 'Note', 'fishotel-misc-plugin' ); ?></span><textarea class="fh-rq-input" name="note" rows="2" maxlength="<?php echo esc_attr( Rules::MAX_NOTE ); ?>"><?php echo esc_textarea( $request['note'] ); ?></textarea></label>
					<p class="fh-rq-grid__wide"><button type="submit" class="button"><?php esc_html_e( 'Save the correction', 'fishotel-misc-plugin' ); ?></button> <span class="fh-rq-sub fh-rq-sub--inline"><?php esc_html_e( 'Saving never sends an e-mail. An e-mail address that belongs to a site customer joins the request to that account; a changed address moves the request to the account of the new one (or to none).', 'fishotel-misc-plugin' ); ?></span></p>
				</form>
				<datalist id="fh-rq-names"></datalist>
			</section>
		</div>

		<div class="fh-rq-column">

			<section class="fishotel-misc-card fh-rq-card fh-rq-next">
				<h3 class="fh-rq-card__title"><?php esc_html_e( 'What happens next', 'fishotel-misc-plugin' ); ?></h3>

				<?php if ( 'new' === $fh_state ) : ?>
					<p><?php esc_html_e( 'It waits for you.', 'fishotel-misc-plugin' ); ?></p>
					<?php $fh_open( 'approve' ); ?>
						<label><?php esc_html_e( 'Fee (empty = none)', 'fishotel-misc-plugin' ); ?>
							<input type="text" class="fh-rq-input fh-rq-input--money" name="fee" value="<?php echo esc_attr( (float) $request['fee_amount'] > 0 && $fh_fees['available'] && empty( $request['owed'] ) ? number_format( (float) $request['fee_amount'], 2, '.', '' ) : '' ); ?>" placeholder="0.00" inputmode="decimal" <?php disabled( ! $fh_fees['available'] || ! empty( $request['owed'] ) ); ?>>
						</label>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Approve', 'fishotel-misc-plugin' ); ?></button>
						<span class="fh-rq-sub"><?php esc_html_e( 'Without a fee it goes on the list. With a fee the bill is e-mailed and it goes on the list when that is paid. To put several requests of one person on ONE bill, approve them together on the list page.', 'fishotel-misc-plugin' ); ?></span>
					</form>
					<?php $fh_open( 'decline' ); ?>
						<label><?php esc_html_e( 'Decline — a note (optional; it is in the e-mail when one is sent)', 'fishotel-misc-plugin' ); ?>
							<textarea class="fh-rq-input" name="note" rows="2" maxlength="<?php echo esc_attr( Rules::MAX_NOTE ); ?>"></textarea>
						</label>
						<label class="fh-rq-check"><input type="checkbox" name="send" value="1" <?php disabled( '' === $request['email'] ); ?>> <?php esc_html_e( 'e-mail them that it was declined', 'fishotel-misc-plugin' ); ?></label>
						<button type="submit" class="button" data-confirm="<?php esc_attr_e( 'Decline this request?', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Decline', 'fishotel-misc-plugin' ); ?></button>
					</form>

				<?php elseif ( 'awaiting_payment' === $fh_state ) : ?>
					<?php $fh_carrier = $detail['carrier']; ?>
					<p>
						<?php
						if ( $fh_carrier ) {
							echo esc_html(
								sprintf(
									/* translators: 1: amount, 2: when invoiced, 3: days left */
									_n( 'It waits for a fee of %1$s, billed %2$s. Unpaid, it closes by itself in %3$d day.', 'It waits for a fee of %1$s, billed %2$s. Unpaid, it closes by itself in %3$d days.', Rules::fee_days_left( $request, $fh_now, (int) $ctx['settings']['expire_days'] ), 'fishotel-misc-plugin' ),
									Rules::money( $fh_carrier['fee_amount'], $fh_symbol ),
									Admin::when( $request['approved_at'] ),
									Rules::fee_days_left( $request, $fh_now, (int) $ctx['settings']['expire_days'] )
								)
							);
						}
						?>
					</p>
					<?php if ( count( $detail['members'] ) > 1 ) : ?>
						<p class="fh-rq-sub">
							<?php esc_html_e( 'One bill covers:', 'fishotel-misc-plugin' ); ?>
							<?php foreach ( $detail['members'] as $fh_member ) : ?>
								<a href="<?php echo esc_url( Admin::request_url( $fh_member['id'] ) ); ?>">#<?php echo esc_html( (string) $fh_member['id'] ); ?> <?php echo esc_html( Rules::fish_line( $fh_member ) ); ?></a><?php echo esc_html( (int) $fh_member['id'] === (int) $request['fee_ref'] ? ' ' . __( '(carries the amount)', 'fishotel-misc-plugin' ) : '' ); ?>;
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
					<?php if ( '' !== $detail['pay_url'] ) : ?>
						<label class="fh-rq-copy"><span><?php esc_html_e( 'The pay link (it is in the bill e-mail; copy it to send it yourself)', 'fishotel-misc-plugin' ); ?></span>
							<span class="fh-rq-copy__row"><input type="text" class="fh-rq-input" readonly value="<?php echo esc_attr( $detail['pay_url'] ); ?>" onfocus="this.select()"><button type="button" class="button" data-copy="<?php echo esc_attr( $detail['pay_url'] ); ?>"><?php esc_html_e( 'Copy', 'fishotel-misc-plugin' ); ?></button></span>
						</label>
					<?php endif; ?>
					<?php if ( ! $fh_fees['available'] ) : ?>
						<p class="fh-rq-warn"><?php echo esc_html( sprintf( /* translators: %s: why */ __( 'The pay link does not work right now: %s.', 'fishotel-misc-plugin' ), $fh_fees['reason'] ) ); ?></p>
					<?php endif; ?>
					<div class="fh-rq-buttons">
						<?php $fh_open( 'resend', 'fh-rq-inline' ); ?>
							<button type="submit" class="button" <?php disabled( '' === $request['email'] ); ?>><?php esc_html_e( 'E-mail the bill again', 'fishotel-misc-plugin' ); ?></button>
						</form>
						<?php $fh_open( 'mark_paid', 'fh-rq-inline' ); ?>
							<button type="submit" class="button" data-confirm="<?php esc_attr_e( 'Record the fee as paid by hand? Nothing is added to the customer\'s wallet from here — do that in Batch Manager if it is due.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Paid some other way', 'fishotel-misc-plugin' ); ?></button>
						</form>
					</div>
					<?php $fh_open( 'set_fee' ); ?>
						<label><?php esc_html_e( 'Change the fee (0 removes it; the bill goes out again and the old link stops working)', 'fishotel-misc-plugin' ); ?>
							<input type="text" class="fh-rq-input fh-rq-input--money" name="fee" value="<?php echo esc_attr( $fh_carrier ? number_format( (float) $fh_carrier['fee_amount'], 2, '.', '' ) : '' ); ?>" inputmode="decimal">
						</label>
						<button type="submit" class="button"><?php esc_html_e( 'Change the fee', 'fishotel-misc-plugin' ); ?></button>
					</form>

				<?php elseif ( 'waiting' === $fh_state ) : ?>
					<p><?php esc_html_e( 'It is on the list. When a supplier order that is a draft or ordered has a line of this fish, the daily check puts it on order by itself.', 'fishotel-misc-plugin' ); ?></p>
					<?php if ( empty( $request['auto_match'] ) ) : ?>
						<p class="fh-rq-warn"><?php esc_html_e( 'Matching by itself is OFF for this request (you took it off a supplier order).', 'fishotel-misc-plugin' ); ?></p>
						<?php $fh_open( 'auto', 'fh-rq-inline' ); ?>
							<input type="hidden" name="on" value="1">
							<button type="submit" class="button"><?php esc_html_e( 'Let it match by itself again', 'fishotel-misc-plugin' ); ?></button>
						</form>
					<?php endif; ?>

				<?php elseif ( in_array( $fh_state, array( 'on_order', 'in_quarantine', 'ready', 'offered' ), true ) ) : ?>
					<p>
						<?php
						$fh_words = array(
							'on_order'      => __( 'A supplier order carries it.', 'fishotel-misc-plugin' ),
							'in_quarantine' => __( 'Its fish is in a tank, in quarantine.', 'fishotel-misc-plugin' ),
							'ready'         => __( 'Its tank group is on sale: the fish is ready, and the person has not been told yet.', 'fishotel-misc-plugin' ),
							'offered'       => __( 'The person was told the fish is ready.', 'fishotel-misc-plugin' ),
						);
						echo esc_html( $fh_words[ $fh_state ] );
						?>
						<?php if ( (int) $request['cr_order_id'] > 0 ) : ?>
							<?php if ( '' !== $detail['order_url'] ) : ?>
								<a href="<?php echo esc_url( $detail['order_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order ref */ __( 'Supplier order %s', 'fishotel-misc-plugin' ), '' !== $detail['ref'] ? $detail['ref'] : '#' . (int) $request['cr_order_id'] ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( sprintf( /* translators: %d: supplier order id */ __( 'Supplier order #%d', 'fishotel-misc-plugin' ), (int) $request['cr_order_id'] ) ); ?>
							<?php endif; ?>
							<?php if ( ! empty( $request['link_manual'] ) ) : ?>
								<span class="fh-rq-sub fh-rq-sub--inline"><?php esc_html_e( 'matched by hand', 'fishotel-misc-plugin' ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</p>
					<?php if ( 'ready' === $fh_state ) : ?>
						<?php if ( '' !== $detail['blocker'] ) : ?>
							<p class="fh-rq-warn"><?php echo esc_html( Service::mail_words( $detail['blocker'], __( 'The "ready" e-mail', 'fishotel-misc-plugin' ), false ) ); ?></p>
						<?php endif; ?>
						<div class="fh-rq-buttons">
							<?php $fh_open( 'tell', 'fh-rq-inline' ); ?>
								<button type="submit" class="button button-primary" <?php disabled( '' !== $detail['blocker'] ); ?>><?php esc_html_e( 'Tell them (e-mail)', 'fishotel-misc-plugin' ); ?></button>
							</form>
							<?php $fh_open( 'told', 'fh-rq-inline' ); ?>
								<button type="submit" class="button" title="<?php esc_attr_e( 'You told them some other way: no e-mail is sent.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Told them', 'fishotel-misc-plugin' ); ?></button>
							</form>
						</div>
					<?php endif; ?>
					<?php if ( 'offered' !== $fh_state ) : ?>
						<?php $fh_open( 'unlink', 'fh-rq-inline' ); ?>
							<button type="submit" class="button" data-confirm="<?php esc_attr_e( 'Take this request off its supplier order? It goes back to waiting and is no longer matched by itself until you switch that back on.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Take it off this order', 'fishotel-misc-plugin' ); ?></button>
						</form>
					<?php endif; ?>

				<?php else : ?>
					<p><?php esc_html_e( 'It is closed.', 'fishotel-misc-plugin' ); ?> <span class="fh-rq-sub fh-rq-sub--inline"><?php echo esc_html( Admin::when( (string) $request['closed_at'] ) ); ?></span></p>
					<?php $fh_open( 'reopen', 'fh-rq-inline' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Reopen — back on the list', 'fishotel-misc-plugin' ); ?></button>
					</form>
				<?php endif; ?>

				<?php if ( in_array( $fh_state, array( 'waiting', 'on_order', 'in_quarantine', 'ready' ), true ) ) : ?>
					<h4 class="fh-rq-card__sub"><?php esc_html_e( 'Match it to a supplier order line by hand', 'fishotel-misc-plugin' ); ?></h4>
					<?php if ( ! $ctx['cleanroom'] ) : ?>
						<p class="fh-rq-sub"><?php esc_html_e( 'Unavailable: the Clean Room section is switched off.', 'fishotel-misc-plugin' ); ?></p>
					<?php elseif ( ! $detail['candidates'] ) : ?>
						<p class="fh-rq-sub"><?php esc_html_e( 'No recent supplier order (draft, ordered or received) has a line with this fish or a name like it.', 'fishotel-misc-plugin' ); ?></p>
					<?php else : ?>
						<?php $fh_open( 'link' ); ?>
							<select class="fh-rq-input" name="line">
								<?php foreach ( $detail['candidates'] as $fh_line ) : ?>
									<option value="<?php echo esc_attr( $fh_line['order_id'] . ':' . $fh_line['id'] ); ?>" <?php selected( (int) $request['line_id'], (int) $fh_line['id'] ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: order ref, 2: order status, 3: species, 4: size, 5: fish ordered */
												__( '%1$s (%2$s) — %3$s%4$s — %5$d ordered', 'fishotel-misc-plugin' ),
												$fh_line['ref'],
												$fh_line['status'],
												$fh_line['species'],
												'' !== $fh_line['size_label'] ? ' ' . $fh_line['size_label'] : '',
												$fh_line['qty_ordered']
											) . ( $fh_line['exact'] ? '' : ' — ' . __( 'a name like it', 'fishotel-misc-plugin' ) )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button"><?php esc_html_e( 'Match', 'fishotel-misc-plugin' ); ?></button>
							<span class="fh-rq-sub"><?php esc_html_e( 'A match by hand keeps its place even when the line has more requests than fish ordered. Once the line is in a tank, the state follows it from there.', 'fishotel-misc-plugin' ); ?></span>
						</form>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $fh_open_states ) : ?>
					<h4 class="fh-rq-card__sub"><?php esc_html_e( 'Close it', 'fishotel-misc-plugin' ); ?></h4>
					<div class="fh-rq-buttons">
						<?php if ( in_array( $fh_state, array( 'waiting', 'on_order', 'in_quarantine', 'ready', 'offered' ), true ) ) : ?>
							<?php $fh_open( 'fill', 'fh-rq-inline' ); ?>
								<button type="submit" class="button" title="<?php esc_attr_e( 'The person has the fish.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Mark filled', 'fishotel-misc-plugin' ); ?></button>
							</form>
						<?php endif; ?>
						<?php if ( 'new' !== $fh_state ) : ?>
							<?php $fh_open( 'cancel', 'fh-rq-inline' ); ?>
								<button type="submit" class="button" data-confirm="<?php esc_attr_e( 'Cancel this request? Nobody is e-mailed.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Cancel it', 'fishotel-misc-plugin' ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</section>

			<section class="fishotel-misc-card fh-rq-card">
				<h3 class="fh-rq-card__title"><?php esc_html_e( 'History', 'fishotel-misc-plugin' ); ?></h3>
				<?php if ( ! $detail['log'] ) : ?>
					<p class="fh-rq-sub"><?php esc_html_e( 'Nothing was logged for this request.', 'fishotel-misc-plugin' ); ?></p>
				<?php else : ?>
					<ol class="fh-rq-history">
						<?php foreach ( $detail['log'] as $fh_line ) : ?>
							<li class="fh-rq-history__line fh-rq-history__line--<?php echo esc_attr( $fh_line['action'] ); ?>">
								<span class="fh-rq-history__when"><?php echo esc_html( Admin::when( $fh_line['created_at'] ) ); ?> · <?php echo esc_html( '' !== $fh_line['actor'] ? $fh_line['actor'] : 'system' ); ?></span>
								<span class="fh-rq-history__note fh-rq-pre"><?php echo esc_html( $fh_line['note'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
				<?php $fh_open( 'note' ); ?>
					<label><?php esc_html_e( 'Add a note to the history', 'fishotel-misc-plugin' ); ?>
						<textarea class="fh-rq-input" name="text" rows="2" maxlength="<?php echo esc_attr( Rules::MAX_NOTE ); ?>"></textarea>
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Add the note', 'fishotel-misc-plugin' ); ?></button>
				</form>
			</section>
		</div>
	</div>
</div>
