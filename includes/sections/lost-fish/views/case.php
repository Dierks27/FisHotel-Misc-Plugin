<?php
/**
 * One case card.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 *
 * @var array $card     Admin::card(): case, facts, numbers, rows, rest_left, preview, subject, alternatives, todo, stale.
 * @var array $settings Settings::get().
 */

defined( 'ABSPATH' ) || exit;

use FisHotel\Misc\Sections\Lost_Fish\Admin;
use FisHotel\Misc\Sections\Lost_Fish\Cases;
use FisHotel\Misc\Sections\Lost_Fish\Email;
use FisHotel\Misc\Sections\Lost_Fish\Lost_Fish;
use FisHotel\Misc\Sections\Lost_Fish\Money;
use FisHotel\Misc\Sections\Lost_Fish\Shipping;

$fh_case    = $card['case'];
$fh_facts   = $card['facts'];
$fh_numbers = $card['numbers'];
$fh_id      = (int) $fh_case['id'];
$fh_symbol  = is_array( $fh_facts ) ? $fh_facts['symbol'] : '$';
$fh_status  = (string) $fh_case['status'];
$fh_post    = admin_url( 'admin-post.php' );
$fh_money   = static function ( $cents ) use ( $fh_symbol ) {
	return null === $cents ? '—' : Money::format( $cents, $fh_symbol );
};

/**
 * The hidden fields every form of this card starts with.
 */
$fh_form = static function ( $op ) use ( $fh_id ) {
	echo '<input type="hidden" name="action" value="' . esc_attr( Admin::ACTION_CASE ) . '">';
	echo '<input type="hidden" name="case_id" value="' . esc_attr( $fh_id ) . '">';
	echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
	wp_nonce_field( Admin::NONCE_CASE . $fh_id, '_wpnonce', false );
};

$fh_badge = array(
	'open'     => __( 'Not sent yet', 'fishotel-misc-plugin' ),
	'sent'     => $card['stale'] ? __( 'No answer', 'fishotel-misc-plugin' ) : __( 'Waiting', 'fishotel-misc-plugin' ),
	'answered' => __( 'Answered — to do', 'fishotel-misc-plugin' ),
	'done'     => __( 'Done', 'fishotel-misc-plugin' ),
	'closed'   => __( 'Closed', 'fishotel-misc-plugin' ),
);
$fh_matches = array(
	'product'   => __( 'the product on the order line', 'fishotel-misc-plugin' ),
	'variation' => __( 'the variation on the order line', 'fishotel-misc-plugin' ),
	'invoice'   => __( 'the Invoices section', 'fishotel-misc-plugin' ),
	'name'      => __( 'the NAME of an invoice line', 'fishotel-misc-plugin' ),
	'manual'    => __( 'you, by hand', 'fishotel-misc-plugin' ),
);
?>
<article class="fh-lf-case fh-lf-case--<?php echo esc_attr( $fh_status ); ?><?php echo $card['stale'] ? ' fh-lf-case--stale' : ''; ?>" id="fh-lf-case-<?php echo esc_attr( $fh_id ); ?>">

	<header class="fh-lf-case__head">
		<span class="fh-lf-case__id">#<?php echo esc_html( $fh_id ); ?></span>
		<span class="fh-lf-badge fh-lf-badge--<?php echo esc_attr( $card['stale'] ? 'stale' : $fh_status ); ?>"><?php echo esc_html( $fh_badge[ $fh_status ] ?? $fh_status ); ?></span>
		<strong class="fh-lf-case__fish"><?php echo esc_html( Email::fish_line( $fh_case ) ); ?></strong>
		<?php if ( '' !== $fh_case['size_label'] ) : ?>
			<span class="fh-lf-sub"><?php echo esc_html( $fh_case['size_label'] ); ?></span>
		<?php endif; ?>
		<span class="fh-lf-case__who">
			<?php if ( is_array( $fh_facts ) ) : ?>
				<a href="<?php echo esc_url( $fh_facts['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Order #%s', 'fishotel-misc-plugin' ), $fh_facts['number'] ) ); ?></a>
				<span class="fh-lf-sub"><?php echo esc_html( $fh_facts['status'] ); ?></span>
			<?php else : ?>
				<?php echo esc_html( sprintf( /* translators: %d: order id */ __( 'Order #%d (not found)', 'fishotel-misc-plugin' ), (int) $fh_case['order_id'] ) ); ?>
			<?php endif; ?>
			· <?php echo esc_html( '' !== $fh_case['customer_name'] ? $fh_case['customer_name'] : __( '(no name)', 'fishotel-misc-plugin' ) ); ?>
			<?php if ( '' !== $fh_case['customer_email'] ) : ?>
				· <span class="fh-lf-sub"><?php echo esc_html( $fh_case['customer_email'] ); ?></span>
			<?php endif; ?>
		</span>
	</header>

	<p class="fh-lf-case__meta">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: date, 2: how the case was opened */
				__( 'Opened %1$s %2$s', 'fishotel-misc-plugin' ),
				Lost_Fish::site_time( $fh_case['created_at'] ),
				'manual' === $fh_case['origin'] ? __( 'by hand', 'fishotel-misc-plugin' ) : __( 'by a loss in the Clean Room', 'fishotel-misc-plugin' )
			)
		);
		if ( isset( $fh_matches[ $fh_case['line_match'] ] ) ) {
			/* translators: %s: how the order line was found */
			echo ' · ' . esc_html( sprintf( __( 'order line found by %s', 'fishotel-misc-plugin' ), $fh_matches[ $fh_case['line_match'] ] ) );
		}
		if ( null !== $fh_case['sent_at'] ) {
			/* translators: %s: date */
			echo ' · ' . esc_html( sprintf( __( 'sent %s', 'fishotel-misc-plugin' ), Lost_Fish::site_time( $fh_case['sent_at'] ) ) );
		}
		if ( null !== $fh_case['reminded_at'] ) {
			/* translators: %s: date */
			echo ' · ' . esc_html( sprintf( __( 'reminded %s', 'fishotel-misc-plugin' ), Lost_Fish::site_time( $fh_case['reminded_at'] ) ) );
		}
		if ( 'sent' === $fh_status && null !== $fh_case['link_expires'] ) {
			/* translators: %s: date */
			echo ' · ' . esc_html( sprintf( __( 'links good until %s', 'fishotel-misc-plugin' ), Lost_Fish::site_time( $fh_case['link_expires'], 'M j, Y' ) ) );
		}
		if ( 'closed' === $fh_status ) {
			/* translators: 1: date, 2: reason */
			echo ' · ' . esc_html( sprintf( __( 'closed %1$s: %2$s', 'fishotel-misc-plugin' ), Lost_Fish::site_time( $fh_case['closed_at'] ), Cases::reason_label( $fh_case['close_reason'] ) ) );
		}
		?>
	</p>

	<?php if ( ! empty( $fh_case['needs_check'] ) && '' !== $fh_case['check_note'] && in_array( $fh_status, array( 'open', 'sent', 'answered' ), true ) ) : ?>
		<p class="fh-lf-flag"><span class="dashicons dashicons-warning"></span> <?php echo esc_html( $fh_case['check_note'] ); ?></p>
	<?php endif; ?>

	<?php if ( in_array( $fh_status, array( 'answered', 'done' ), true ) ) : ?>
		<?php $fh_amount = Money::amount_for( $fh_case['choice'], $fh_numbers ); ?>
		<p class="fh-lf-answer">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: who, 2: the choice (with its amount), 3: date */
					__( '%1$s: %2$s (%3$s)', 'fishotel-misc-plugin' ),
					'owner' === $fh_case['choice_via'] ? __( 'Answer recorded by hand', 'fishotel-misc-plugin' ) : __( 'The customer chose', 'fishotel-misc-plugin' ),
					Cases::choice_label( $fh_case['choice'] ) . ( null !== $fh_amount && 'retry' !== $fh_case['choice'] ? ' — ' . $fh_money( $fh_amount ) : '' ),
					Lost_Fish::site_time( $fh_case['answered_at'] )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( 'answered' === $fh_status && is_array( $card['todo'] ) ) : ?>
		<div class="fh-lf-todo">
			<h3><?php esc_html_e( 'What to do — the site did none of it', 'fishotel-misc-plugin' ); ?></h3>
			<p class="fh-lf-todo__text"><?php echo esc_html( $card['todo']['text'] ); ?></p>
			<?php if ( '' !== $card['todo']['detail'] ) : ?>
				<p class="fh-lf-sub"><?php echo esc_html( $card['todo']['detail'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $card['todo']['note'] ) : ?>
				<p class="fh-lf-sub"><?php echo esc_html( $card['todo']['note'] ); ?></p>
			<?php endif; ?>
			<p class="fh-lf-todo__links">
				<?php if ( '' !== $card['todo']['url'] ) : ?>
					<a class="button" href="<?php echo esc_url( $card['todo']['url'] ); ?>"><?php echo esc_html( $card['todo']['link'] ); ?></a>
				<?php endif; ?>
				<?php if ( 'credit' === $fh_case['choice'] && is_array( $fh_facts ) ) : ?>
					<a class="button" href="<?php echo esc_url( $fh_facts['edit_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Open order #%s', 'fishotel-misc-plugin' ), $fh_facts['number'] ) ); ?></a>
				<?php endif; ?>
			</p>
			<?php if ( 'retry' === $fh_case['choice'] && '' === $fh_case['request_ref'] && function_exists( 'fishotel_requests_create' ) ) : ?>
				<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-inline">
					<?php $fh_form( 'request' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Add to Requests', 'fishotel-misc-plugin' ); ?></button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-done">
				<?php $fh_form( 'done' ); ?>
				<label class="screen-reader-text" for="fh-lf-note-<?php echo esc_attr( $fh_id ); ?>"><?php esc_html_e( 'Note (optional)', 'fishotel-misc-plugin' ); ?></label>
				<input type="text" class="fh-lf-input" id="fh-lf-note-<?php echo esc_attr( $fh_id ); ?>" name="note" maxlength="1000" placeholder="<?php esc_attr_e( 'Note (optional) — e.g. refunded through the card', 'fishotel-misc-plugin' ); ?>">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Done', 'fishotel-misc-plugin' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<?php if ( 'done' === $fh_status && null !== $fh_case['done_note'] && '' !== $fh_case['done_note'] ) : ?>
		<p class="fh-lf-sub"><?php echo esc_html( sprintf( /* translators: %s: note */ __( 'Note: %s', 'fishotel-misc-plugin' ), $fh_case['done_note'] ) ); ?></p>
	<?php endif; ?>

	<?php if ( in_array( $fh_status, array( 'open', 'sent', 'answered' ), true ) ) : ?>
		<div class="fh-lf-numbers">
			<table class="fh-lf-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Paid for the fish', 'fishotel-misc-plugin' ); ?></th>
						<td class="fh-lf-num"><?php echo esc_html( ! empty( $fh_case['paid_known'] ) ? $fh_money( $fh_numbers['fish_paid'] ) : __( 'not known', 'fishotel-misc-plugin' ) ); ?></td>
						<td class="fh-lf-sub">
							<?php
							if ( (int) $fh_case['qty'] > 1 && ! empty( $fh_case['paid_known'] ) ) {
								/* translators: 1: quantity, 2: price of one */
								echo esc_html( sprintf( __( '%1$d × %2$s, tax included', 'fishotel-misc-plugin' ), (int) $fh_case['qty'], $fh_money( (int) $fh_case['unit_paid'] ) ) );
							} else {
								esc_html_e( 'what the order line was paid, tax included', 'fishotel-misc-plugin' );
							}
							?>
						</td>
					</tr>
					<tr class="<?php echo null === $fh_numbers['ship_diff'] ? 'is-unknown' : ''; ?>">
						<th scope="row"><?php esc_html_e( 'Shipping difference', 'fishotel-misc-plugin' ); ?></th>
						<td class="fh-lf-num"><?php echo esc_html( null === $fh_numbers['ship_diff'] ? __( 'not known', 'fishotel-misc-plugin' ) : $fh_money( $fh_numbers['ship_diff'] ) ); ?></td>
						<td class="fh-lf-sub">
							<?php
							echo esc_html( $fh_case['ship_note'] );
							if ( 'open' === $fh_status && empty( $fh_case['ship_manual'] ) && in_array( $fh_case['ship_status'], array( Shipping::NONE, Shipping::DROP ), true ) ) {
								echo ' ' . esc_html__( '(Worked out from the shipping settings.)', 'fishotel-misc-plugin' );
							}
							?>
						</td>
					</tr>
					<?php foreach ( $card['rows'] as $fh_row ) : ?>
						<tr class="fh-lf-choice<?php echo $fh_case['choice'] === $fh_row['choice'] ? ' is-chosen' : ''; ?>">
							<th scope="row"><?php echo esc_html( $fh_row['title'] ); ?></th>
							<td class="fh-lf-num"><?php echo esc_html( 'retry' === $fh_row['choice'] ? '—' : ( '' !== $fh_row['money'] ? $fh_row['money'] : __( 'not known', 'fishotel-misc-plugin' ) ) ); ?></td>
							<td class="fh-lf-sub"><?php echo esc_html( $fh_row['text'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $fh_numbers['offer_cancel'] ) && 'open' === $fh_status ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cancel the whole order', 'fishotel-misc-plugin' ); ?></th>
							<td class="fh-lf-num">—</td>
							<td class="fh-lf-sub"><?php esc_html_e( 'Not offered: part of this order has already shipped.', 'fishotel-misc-plugin' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( is_array( $fh_facts ) && 'open' === $fh_status ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'The e-mail will say', 'fishotel-misc-plugin' ); ?></th>
							<td colspan="2" class="fh-lf-sub"><?php echo esc_html( Email::ship_line( $fh_facts, $card['rest_left'] ) ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<?php if ( 'open' === $fh_status ) : ?>

		<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-fix">
			<?php $fh_form( 'numbers' ); ?>
			<label>
				<span><?php esc_html_e( 'Paid for ONE fish', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" inputmode="decimal" class="fh-lf-input fh-lf-input--num" name="unit_paid" value="<?php echo esc_attr( ! empty( $fh_case['paid_known'] ) ? Money::decimal( (int) $fh_case['unit_paid'] ) : '' ); ?>">
			</label>
			<label>
				<span><?php esc_html_e( 'Shipping difference', 'fishotel-misc-plugin' ); ?></span>
				<input type="text" inputmode="decimal" class="fh-lf-input fh-lf-input--num" name="ship_diff" value="<?php echo esc_attr( ! empty( $fh_case['ship_manual'] ) ? Money::decimal( $fh_case['ship_diff'] ) : '' ); ?>" placeholder="<?php echo esc_attr( null === $fh_case['ship_diff'] ? __( 'fill in, or 0', 'fishotel-misc-plugin' ) : Money::decimal( $fh_case['ship_diff'] ) ); ?>">
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Save numbers', 'fishotel-misc-plugin' ); ?></button>
			<span class="fh-lf-sub">
				<?php
				echo esc_html(
					null === $fh_case['ship_diff']
						? __( 'Type the shipping difference, or 0 to confirm there is none.', 'fishotel-misc-plugin' )
						: ( ! empty( $fh_case['ship_manual'] ) ? __( 'Typed by hand. Empty the field to have it worked out again.', 'fishotel-misc-plugin' ) : __( 'Leave the shipping field empty to keep the worked-out figure; type one to overrule it.', 'fishotel-misc-plugin' ) )
				);
				?>
			</span>
		</form>

		<?php if ( '' !== $card['preview'] ) : ?>
			<details class="fh-lf-preview">
				<summary><?php esc_html_e( 'Preview the e-mail', 'fishotel-misc-plugin' ); ?></summary>
				<p class="fh-lf-sub">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: e-mail address, 2: subject */
							__( 'To: %1$s · Subject: %2$s · The links in this preview do nothing.', 'fishotel-misc-plugin' ),
							is_array( $fh_facts ) && '' !== $fh_facts['email'] ? $fh_facts['email'] : __( '(no address)', 'fishotel-misc-plugin' ),
							$card['subject']
						)
					);
					?>
				</p>
				<iframe class="fh-lf-preview__frame" sandbox="" title="<?php esc_attr_e( 'E-mail preview', 'fishotel-misc-plugin' ); ?>" srcdoc="<?php echo esc_attr( $card['preview'] ); ?>"></iframe>
			</details>
		<?php endif; ?>

		<?php if ( $fh_numbers['blockers'] ) : ?>
			<ul class="fh-lf-blockers">
				<?php foreach ( $fh_numbers['blockers'] as $fh_blocker ) : ?>
					<li><span class="dashicons dashicons-lock"></span> <?php echo esc_html( $fh_blocker ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-send">
			<?php $fh_form( 'send' ); ?>
			<input type="hidden" name="shown_refund" value="<?php echo esc_attr( null !== $fh_numbers['refund'] ? (int) $fh_numbers['refund'] : '' ); ?>">
			<button type="submit" class="button button-primary button-hero" <?php disabled( ! $fh_numbers['ready'] ); ?> data-fh-lf-confirm="<?php echo esc_attr( sprintf( /* translators: %s: e-mail address */ __( 'Send this e-mail to %s now?', 'fishotel-misc-plugin' ), is_array( $fh_facts ) ? $fh_facts['email'] : '' ) ); ?>">
				<?php esc_html_e( 'Send to customer', 'fishotel-misc-plugin' ); ?>
			</button>
			<span class="fh-lf-sub"><?php echo esc_html( $fh_numbers['ready'] ? __( 'Nothing is sent until you click.', 'fishotel-misc-plugin' ) : __( 'Not ready to send — see above.', 'fishotel-misc-plugin' ) ); ?></span>
		</form>

	<?php endif; ?>

	<details class="fh-lf-more">
		<summary><?php esc_html_e( 'More', 'fishotel-misc-plugin' ); ?></summary>

		<?php if ( 'open' === $fh_status && $card['alternatives'] ) : ?>
			<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-inline">
				<?php $fh_form( 'reassign' ); ?>
				<label>
					<span><?php esc_html_e( 'Give this loss to another customer who is owed the same fish', 'fishotel-misc-plugin' ); ?></span>
					<select name="holder" class="fh-lf-input">
						<?php foreach ( $card['alternatives'] as $fh_holder ) : ?>
							<option value="<?php echo esc_attr( (int) $fh_holder['order_id'] . '|' . $fh_holder['ref'] ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: order number, 2: customer, 3: paid date, 4: fish owed */
										__( 'Order #%1$s · %2$s · paid %3$s · owed %4$d', 'fishotel-misc-plugin' ),
										$fh_holder['order_number'],
										$fh_holder['customer_name'],
										$fh_holder['paid_at'] > 0 ? Lost_Fish::site_time( gmdate( 'Y-m-d H:i:s', (int) $fh_holder['paid_at'] ), 'M j, Y' ) : '?',
										(int) $fh_holder['owed']
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Move the case', 'fishotel-misc-plugin' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( in_array( $fh_status, array( 'open', 'sent' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-inline">
				<?php $fh_form( 'answer' ); ?>
				<label>
					<span><?php esc_html_e( 'The customer told you directly? Record the answer by hand', 'fishotel-misc-plugin' ); ?></span>
					<select name="choice" class="fh-lf-input">
						<?php foreach ( $card['rows'] as $fh_row ) : ?>
							<option value="<?php echo esc_attr( $fh_row['choice'] ); ?>"><?php echo esc_html( $fh_row['title'] . ( '' !== $fh_row['money'] ? ' — ' . $fh_row['money'] : '' ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button" data-fh-lf-confirm="<?php esc_attr_e( 'Record this as the customer\'s answer? No e-mail is sent.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Record the answer', 'fishotel-misc-plugin' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( in_array( $fh_status, array( 'open', 'sent', 'answered' ), true ) ) : ?>
			<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-inline">
				<?php $fh_form( 'close' ); ?>
				<label>
					<span><?php esc_html_e( 'Close without an answer', 'fishotel-misc-plugin' ); ?></span>
					<select name="reason" class="fh-lf-input">
						<option value="mistake"><?php echo esc_html( Cases::reason_label( 'mistake' ) ); ?></option>
						<option value="settled"><?php echo esc_html( Cases::reason_label( 'settled' ) ); ?></option>
						<option value="other"><?php echo esc_html( Cases::reason_label( 'other' ) ); ?></option>
					</select>
				</label>
				<button type="submit" class="button" data-fh-lf-confirm="<?php esc_attr_e( 'Close this case? Nothing is sent; the links of an earlier e-mail will say it is closed.', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Close the case', 'fishotel-misc-plugin' ); ?></button>
			</form>
		<?php endif; ?>

		<?php if ( 'open' !== $fh_status ) : ?>
			<form method="post" action="<?php echo esc_url( $fh_post ); ?>" class="fh-lf-inline">
				<?php $fh_form( 'reopen' ); ?>
				<span class="fh-lf-sub"><?php esc_html_e( 'Reopen: the answer is forgotten, the old links stop working, the numbers are fresh. Nothing is sent.', 'fishotel-misc-plugin' ); ?></span>
				<button type="submit" class="button" data-fh-lf-confirm="<?php esc_attr_e( 'Reopen this case?', 'fishotel-misc-plugin' ); ?>"><?php esc_html_e( 'Reopen', 'fishotel-misc-plugin' ); ?></button>
			</form>
		<?php endif; ?>
	</details>

</article>
